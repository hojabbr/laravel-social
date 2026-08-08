<?php

namespace Hojabbr\Social\Drivers\Telegram;

use Hojabbr\Social\Contracts\SupportsDeletion;
use Hojabbr\Social\Contracts\SupportsTopics;
use Hojabbr\Social\Drivers\BaseDriver;
use Hojabbr\Social\Enums\Placement;
use Hojabbr\Social\Values\Account;
use Hojabbr\Social\Values\Capabilities;
use Hojabbr\Social\Values\Health;
use Hojabbr\Social\Values\Media;
use Hojabbr\Social\Values\PublishRequest;
use Hojabbr\Social\Values\PublishResult;
use Hojabbr\Social\Values\RateProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;
use Throwable;

/**
 * Telegram, as a broadcast channel: one bot posting into one chat, whose forum
 * topics are addressable as `Destination::$topic`.
 *
 * `$body` is HTML, never MarkdownV2 — use `TelegramDriver::escape()` on anything
 * interpolated into it. MarkdownV2 requires escaping eighteen characters
 * (`_ * [ ] ( ) ~ ` > # + - = | { } . !`), which is precisely what financial copy
 * is made of: every decimal price carries a `.`, every range a `-`, every change
 * a `+`. One missed escape is a hard 400 that fail-loops a job — an outage class
 * rather than a bug class. HTML needs three characters escaped that this content
 * essentially never contains.
 *
 * NOTE for anyone routing outbound traffic through a proxy: api.telegram.org is
 * reachable directly from anywhere we run, and sending it through an exit chosen
 * for geoblocked origins only adds a failure mode.
 */
class TelegramDriver extends BaseDriver implements SupportsDeletion, SupportsTopics
{
    /**
     * The Bot API's hard ceiling on a video thumbnail's longest edge. It also
     * demands JPEG and under 200 kB; at this size and quality 80 the file lands
     * around 20-40 kB, so no size-retry loop is needed.
     */
    private const THUMBNAIL_MAX_EDGE = 320;

    /** ffprobe reads container metadata only, so it returns near-instantly. */
    private const PROBE_TIMEOUT_SECONDS = 15;

    /** The Bot API refuses a sendMediaGroup with more than ten items outright. */
    private const ALBUM_MAX_ITEMS = 10;

    /** Bot API caption ceiling on sendPhoto/sendVideo (characters, post-parse). */
    private const CAPTION_LIMIT = 1024;

    /** Bot API text ceiling on sendMessage. */
    private const TEXT_LIMIT = 4096;

    private ?TelegramApi $api = null;

    public function label(): string
    {
        return 'Telegram';
    }

    /**
     * Escape a value for interpolation into an HTML-parse-mode body. The Bot API
     * supports only &lt; &gt; &amp; &quot; as named entities.
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            placements: [Placement::Message],
            bodyLimit: self::TEXT_LIMIT,
            captionLimit: self::CAPTION_LIMIT,
            // Telegram has no hashtag mechanic worth capping: a tag is just text
            // in the body. Zero means "the caller decides", not "none allowed".
            tagLimit: 0,
            maxItemsPerMessage: self::ALBUM_MAX_ITEMS,
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime'],
            maxVideoBytes: $this->number('upload_max_bytes', 45 * 1024 * 1024),
            maxImageBytes: $this->number('photo_max_bytes', 10 * 1024 * 1024),
        );
    }

    public function rateProfile(): RateProfile
    {
        return new RateProfile($this->number('spacing_ms', 3200));
    }

    public function isUsable(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    /**
     * Whether the credentials exist, regardless of the kill switch — what a
     * diagnostic surface asks before it tries to read live state.
     */
    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->account('default')->isConfigured();
    }

    public function health(): Health
    {
        $account = $this->accounts === [] ? null : $this->account($this->accountKeys()[0]);

        if (! $this->isConfigured() || $account === null) {
            return new Health($this->network, $this->isEnabled(), false);
        }

        $me = $this->me();
        $chat = $this->chat($account);
        $member = is_array($me) && isset($me['id']) ? $this->member($account, (int) $me['id']) : null;

        return new Health($this->network, $this->isEnabled(), true, [
            'username' => $me['username'] ?? null,
            'chatTitle' => $chat['title'] ?? null,
            'isForum' => (bool) ($chat['is_forum'] ?? false),
            'status' => $member['status'] ?? null,
            // The one right that gates topic creation, and the one most often
            // missing — a bot cannot grant it to itself.
            'canManageTopics' => (bool) ($member['can_manage_topics'] ?? false),
            'canRestrict' => (bool) ($member['can_restrict_members'] ?? false),
            'canPin' => (bool) ($member['can_pin_messages'] ?? false),
            'availableReactions' => $this->reactionsOf($chat),
        ]);
    }

    // -----------------------------------------------------------------
    // Publishing
    // -----------------------------------------------------------------

    public function publish(PublishRequest $request): PublishResult
    {
        $account = $request->account();

        if (! $account->isConfigured() || $this->token() === '') {
            return PublishResult::rejected('The Telegram bot token or chat id is not configured.');
        }

        foreach ($request->media as $media) {
            if ($media->path === null || ! is_file($media->path)) {
                return PublishResult::rejected(
                    'The file to upload no longer exists on this server: '.($media->path ?? '(none)'),
                );
            }
        }

        $images = array_values(array_filter($request->media, static fn (Media $m): bool => ! $m->isVideo()));

        return match (true) {
            count($images) > 1 => $this->sendAlbum($request, $images),
            $request->firstMedia()?->isVideo() === true => $this->sendVideo($request),
            $request->firstMedia() !== null => $this->sendPhoto($request),
            default => $this->sendMessage($request),
        };
    }

    private function sendMessage(PublishRequest $request): PublishResult
    {
        return $this->api()->send('sendMessage', $this->params($request, [
            'text' => $request->body,
            'parse_mode' => 'HTML',
            // JSON-encoded, like every other nested Bot API object here (`media`,
            // `permissions`). The SDK posts multipart/form-data, so a raw PHP
            // array goes out as `link_preview_options[is_disabled]=1`, which the
            // Bot API does not parse — it silently ignored the option and drew a
            // preview card under every post, all showing the site's one default
            // OG image because every post links to the same domain.
            'link_preview_options' => $request->preview
                ? null
                : json_encode(['is_disabled' => true], JSON_THROW_ON_ERROR),
        ]));
    }

    /**
     * Upload a local image. The file is streamed as multipart, never handed over
     * as a URL, so a private-disk file never needs a public address to be posted.
     */
    private function sendPhoto(PublishRequest $request): PublishResult
    {
        $media = $request->firstMedia();

        if ($media?->path === null) {
            return PublishResult::rejected('No image to post.');
        }

        return $this->api()->send('sendPhoto', $this->params($request, [
            'photo' => InputFile::create($media->path, basename($media->path)),
            'caption' => $request->body,
            'parse_mode' => 'HTML',
            'show_caption_above_media' => $request->bodyAbove,
        ]));
    }

    /**
     * Upload a video, telling Telegram how big it actually is.
     *
     * `width`, `height` and `duration` are optional in the Bot API, and omitting
     * them is exactly the bug this method exists to avoid: with no dimensions the
     * client has to guess the player's geometry, and it guesses from whatever the
     * thumbnail looks like. sendPhoto has no such problem because Telegram
     * measures the JPEG itself — which is why covers rendered correctly while
     * fullscreen video came out the wrong shape.
     */
    private function sendVideo(PublishRequest $request): PublishResult
    {
        $media = $request->firstMedia();

        if ($media?->path === null) {
            return PublishResult::rejected('No video to post.');
        }

        $geometry = self::probe($media->path);
        $thumbnail = self::thumbnailWithinSpec($media->thumbnailPath);

        try {
            return $this->api()->send('sendVideo', $this->params($request, [
                'video' => InputFile::create($media->path, basename($media->path)),
                'thumbnail' => $thumbnail !== null
                    ? InputFile::create($thumbnail, basename($thumbnail))
                    : null,
                'width' => $geometry['width'],
                'height' => $geometry['height'],
                'duration' => $geometry['duration'],
                'caption' => $request->body,
                'parse_mode' => 'HTML',
                'supports_streaming' => true,
            ]));
        } finally {
            if ($thumbnail !== null) {
                @unlink($thumbnail);
            }
        }
    }

    /**
     * Upload several local images as ONE album, caption on the first.
     *
     * A multi-page card is pages of one post, not several posts: an album
     * collapses to a single swipeable message, so a reader cannot meet page 3
     * without page 1, and a forward carries the whole thing.
     *
     * The multipart shape is Telegram's, not the SDK's: `media` is a JSON array
     * whose entries point at `attach://<field>`, and each field is a sibling
     * multipart part. The SDK's uploadFile() passes a JSON string through
     * untouched and turns every InputFile into its own part, so the two line up.
     *
     * @param  list<Media>  $images  In page order; Telegram preserves it.
     */
    private function sendAlbum(PublishRequest $request, array $images): PublishResult
    {
        // The Bot API accepts 2-10 items. >10 is a hard refusal, so a runaway
        // page count is clamped rather than losing the whole post.
        $images = array_slice($images, 0, self::ALBUM_MAX_ITEMS);

        $media = [];
        $files = [];

        foreach ($images as $index => $image) {
            $field = "page{$index}";
            $path = (string) $image->path;

            $media[] = array_filter([
                'type' => 'photo',
                'media' => "attach://{$field}",
                // Only the first item may carry a caption; Telegram shows it as
                // the album's caption. A caption on item 2+ is silently dropped.
                'caption' => $index === 0 ? $request->body : null,
                'parse_mode' => $index === 0 ? 'HTML' : null,
                // `show_caption_above_media` rides on the ITEM (InputMediaPhoto),
                // not on the top-level call the way sendPhoto takes it — put it
                // at the top level and it is dropped, which reads as Telegram
                // ignoring it and quietly moves the caption below the album.
                //
                // It goes on EVERY item even though only item 0 has a caption:
                // Telegram validates it album-wide and rejects the whole send
                // with "must be the same for all messages" when the other items
                // omit it. Omission is not "no opinion", it reads as false.
                'show_caption_above_media' => $request->bodyAbove,
            ], static fn (mixed $value): bool => $value !== null);

            $files[$field] = InputFile::create($path, basename($path));
        }

        return $this->api()->sendAlbum($this->params($request, [
            'media' => json_encode($media, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ...$files,
        ]));
    }

    /**
     * The chat, thread and notification parameters every send shares.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function params(PublishRequest $request, array $params): array
    {
        return array_filter([
            'chat_id' => $request->account()->id,
            'message_thread_id' => $request->destination->topic,
            'disable_notification' => ! $request->notify,
            ...$params,
        ], static fn (mixed $value): bool => $value !== null);
    }

    // -----------------------------------------------------------------
    // Deletion
    // -----------------------------------------------------------------

    /**
     * Delete one of our own messages. Telegram only allows this within 48 hours
     * for a normal message, but an admin bot may delete its own at any time.
     */
    public function delete(Account $account, int|string $externalId): bool
    {
        return (bool) $this->api()->attempt(fn (Api $api) => $api->deleteMessage([
            'chat_id' => $account->id,
            'message_id' => (int) $externalId,
        ]));
    }

    // -----------------------------------------------------------------
    // Forum topics
    // -----------------------------------------------------------------

    /**
     * Create a topic and return its message_thread_id.
     *
     * `$iconColor` is one of Telegram's six permitted integers and can ONLY be set
     * here — the Bot API has no way to change it afterwards, which is also why
     * syncTopic() does not take one. `$iconId` is a custom-emoji id, which the Bot
     * API does allow editing later.
     */
    public function createTopic(Account $account, string $name, ?string $iconId = null, ?int $iconColor = null): int|string|null
    {
        $topic = $this->api()->attempt(fn (Api $api) => $api->createForumTopic(array_filter([
            'chat_id' => $account->id,
            'name' => mb_substr($name, 0, 128),
            'icon_color' => $iconColor,
            'icon_custom_emoji_id' => $iconId,
        ], static fn (mixed $value): bool => $value !== null)));

        $threadId = $topic?->get('message_thread_id');

        return is_numeric($threadId) ? (int) $threadId : null;
    }

    /**
     * Push a topic's title and icon, and report whether the topic still exists.
     *
     * One call does both jobs on purpose. There is no `getForumTopic` in the Bot
     * API, so the only way to ask "is this topic still there" is to try something
     * and read the refusal — and since we have to spend the call anyway, it may
     * as well be the one that re-asserts the title and icon. That makes a setup
     * run self-healing for free.
     *
     * Tri-state: null means "could not tell" (a network blip), and the caller
     * must NOT treat it as deleted — recreating a topic that is actually alive
     * would leave a duplicate.
     */
    public function syncTopic(Account $account, int|string $topic, string $name, ?string $iconId = null): ?bool
    {
        try {
            $this->api()->invoke(fn (Api $api) => $api->editForumTopic(array_filter([
                'chat_id' => $account->id,
                'message_thread_id' => (int) $topic,
                'name' => mb_substr($name, 0, 128),
                'icon_custom_emoji_id' => $iconId,
            ], static fn (mixed $value): bool => $value !== null)));

            return true;
        } catch (Throwable $exception) {
            $description = mb_strtolower($exception->getMessage());

            $gone = str_contains($description, 'not found')
                || str_contains($description, 'topic_deleted')
                || str_contains($description, 'topic_id_invalid');

            return $gone ? false : null;
        }
    }

    /**
     * Close a topic so it reads as read-only. Secondary to restrictMembers():
     * the chat-level restriction is what actually stops members posting, this
     * gives them the correct affordance rather than a rejection.
     */
    public function closeTopic(Account $account, int|string $topic): bool
    {
        return (bool) $this->api()->attempt(fn (Api $api) => $api->closeForumTopic([
            'chat_id' => $account->id,
            'message_thread_id' => (int) $topic,
        ]));
    }

    public function deleteTopic(Account $account, int|string $topic): bool
    {
        return (bool) $this->api()->attempt(fn (Api $api) => $api->deleteForumTopic([
            'chat_id' => $account->id,
            'message_thread_id' => (int) $topic,
        ]));
    }

    /**
     * Hide the mandatory 'General' topic.
     *
     * Every forum supergroup has one and it CANNOT be deleted — Telegram creates
     * it with the forum and `deleteForumTopic` does not apply to it. Hiding is
     * therefore the removal: it disappears from the topic list for every member,
     * and Telegram closes it on the way out.
     *
     * Not in the SDK's method surface, so it goes through the generic post(). The
     * Bot API has had it since 6.4; the SDK simply never wrapped it.
     */
    public function hideGeneralTopic(Account $account): bool
    {
        return $this->api()->attempt(fn (Api $api) => $api->post('hideGeneralForumTopic', [
            'chat_id' => $account->id,
        ])) !== null;
    }

    public function pin(Account $account, int|string $externalId, bool $silent = true): bool
    {
        return (bool) $this->api()->attempt(fn (Api $api) => $api->pinChatMessage([
            'chat_id' => $account->id,
            'message_id' => (int) $externalId,
            'disable_notification' => $silent,
        ]));
    }

    /**
     * Strip every send right from default members, so reacting is all that is
     * left. Admins (and the bot) are exempt by definition. `can_manage_topics`
     * must be sent EXPLICITLY — the Bot API defaults it to the value of
     * `can_pin_messages`, which would silently let members create topics.
     */
    public function restrictMembers(Account $account): bool
    {
        return (bool) $this->api()->attempt(fn (Api $api) => $api->setChatPermissions([
            'chat_id' => $account->id,
            'permissions' => json_encode([
                'can_send_messages' => false,
                'can_send_audios' => false,
                'can_send_documents' => false,
                'can_send_photos' => false,
                'can_send_videos' => false,
                'can_send_video_notes' => false,
                'can_send_voice_notes' => false,
                'can_send_polls' => false,
                'can_send_other_messages' => false,
                'can_add_web_page_previews' => false,
                'can_change_info' => false,
                'can_invite_users' => true,
                'can_pin_messages' => false,
                'can_manage_topics' => false,
            ]),
        ]));
    }

    // -----------------------------------------------------------------
    // Diagnostics
    // -----------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function me(): ?array
    {
        return $this->api()->attempt(fn (Api $api) => $api->getMe())?->toArray();
    }

    /** @return array<string, mixed>|null */
    public function chat(Account $account): ?array
    {
        return $this->api()->attempt(fn (Api $api) => $api->getChat(['chat_id' => $account->id]))?->toArray();
    }

    /** @return array<string, mixed>|null */
    public function member(Account $account, int $userId): ?array
    {
        return $this->api()->attempt(fn (Api $api) => $api->getChatMember([
            'chat_id' => $account->id,
            'user_id' => $userId,
        ]))?->toArray();
    }

    /**
     * The plain-emoji reactions a chat currently allows. There is no Bot API
     * SETTER for this (`setChatAvailableReactions` is MTProto), so the only thing
     * a consumer can do is read it back and compare — which is why this is
     * exposed rather than kept private.
     *
     * @param  array<string, mixed>|null  $chat
     * @return list<string>
     */
    public function reactionsOf(?array $chat): array
    {
        // Telegram returns ReactionType objects; only the plain emoji ones carry
        // an `emoji` key (custom-emoji reactions carry an id instead).
        $reactions = is_array($chat['available_reactions'] ?? null) ? $chat['available_reactions'] : [];

        $live = [];

        foreach ($reactions as $reaction) {
            if (is_array($reaction) && is_string($reaction['emoji'] ?? null)) {
                $live[] = $reaction['emoji'];
            }
        }

        return $live;
    }

    // -----------------------------------------------------------------
    // Transport
    // -----------------------------------------------------------------

    public function api(): TelegramApi
    {
        return $this->api ??= new TelegramApi($this->token());
    }

    /**
     * Swap the SDK instance the transport uses. The seam tests inject a mocked
     * Guzzle stack through here.
     */
    public function setApi(Api $api): self
    {
        $this->api()->setApi($api);

        return $this;
    }

    private function token(): string
    {
        return $this->text('token');
    }

    /**
     * The video's real pixel size and length, read with ffprobe. Every field is
     * nullable: a probe failure must degrade to "send it anyway without
     * dimensions", never block a post.
     *
     * @return array{width: int|null, height: int|null, duration: int|null}
     */
    private static function probe(string $path): array
    {
        $blank = ['width' => null, 'height' => null, 'duration' => null];

        $result = Process::timeout(self::PROBE_TIMEOUT_SECONDS)->run([
            'ffprobe', '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height:format=duration',
            '-of', 'csv=p=0', $path,
        ]);

        if (! $result->successful()) {
            Log::warning('Telegram: could not probe a video, sending without dimensions.', ['path' => $path]);

            return $blank;
        }

        // ffprobe emits the stream line then the format line, e.g. "972,1728\n92.833333".
        $fields = preg_split('~[\s,]+~', trim($result->output()), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'width' => isset($fields[0]) && is_numeric($fields[0]) ? (int) $fields[0] : null,
            'height' => isset($fields[1]) && is_numeric($fields[1]) ? (int) $fields[1] : null,
            'duration' => isset($fields[2]) && is_numeric($fields[2]) ? (int) round((float) $fields[2]) : null,
        ];
    }

    /**
     * Re-encode a cover into what the Bot API actually accepts as a video
     * thumbnail: JPEG, at most 320x320, under 200 kB. A rendered 1080x1920 PNG
     * of a couple of megabytes breaches all three.
     *
     * Fit::Contain, not Fit::Crop: the spec is a bounding box, so the aspect must
     * be preserved or the thumbnail disagrees with the video it labels. The
     * driver is pinned because spatie/image picks Imagick when it is loaded, and
     * a silent driver split is a miserable thing to debug later.
     *
     * Returns a temp path the caller must unlink, or null when there is nothing
     * usable — Telegram then generates its own thumbnail, which is fine. That
     * fallback is also what makes spatie/image OPTIONAL rather than required.
     */
    private static function thumbnailWithinSpec(?string $coverPath): ?string
    {
        if ($coverPath === null || ! is_file($coverPath) || ! class_exists(Image::class)) {
            return null;
        }

        $output = sys_get_temp_dir().'/tg-thumb-'.Str::ulid().'.jpg';

        try {
            Image::useImageDriver(ImageDriver::Gd)
                ->loadFile($coverPath)
                ->fit(Fit::Contain, self::THUMBNAIL_MAX_EDGE, self::THUMBNAIL_MAX_EDGE)
                ->format('jpg')
                ->quality(80)
                ->save($output);
        } catch (Throwable $exception) {
            Log::warning('Telegram: could not build a thumbnail, letting Telegram make its own.', [
                'cover' => $coverPath,
                'error' => $exception->getMessage(),
            ]);

            if (is_file($output)) {
                @unlink($output);
            }

            return null;
        }

        return is_file($output) ? $output : null;
    }
}
