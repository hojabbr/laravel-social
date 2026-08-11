<?php

namespace Hojabbr\Social\Drivers\Instagram;

use Hojabbr\Social\Contracts\ProvidesAnalytics;
use Hojabbr\Social\Contracts\RefreshesTokens;
use Hojabbr\Social\Contracts\SupportsComments;
use Hojabbr\Social\Drivers\BaseDriver;
use Hojabbr\Social\Enums\Placement;
use Hojabbr\Social\Values\Account;
use Hojabbr\Social\Values\Capabilities;
use Hojabbr\Social\Values\Credentials;
use Hojabbr\Social\Values\Health;
use Hojabbr\Social\Values\Metrics;
use Hojabbr\Social\Values\PublishRequest;
use Hojabbr\Social\Values\PublishResult;
use Hojabbr\Social\Values\RateProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Instagram, through the Content Publishing API on Instagram Login.
 *
 * Publishing is a container ladder, not an upload: we hand Instagram a public
 * URL, it FETCHES the file itself, and only then is there something to publish.
 * That is why `Capabilities::$pullsMedia` is true — a caller has to expose a
 * reachable URL before it builds the request, and no bytes cross this boundary.
 *
 *   1. POST /{ig-user-id}/media          → a container id
 *   2. GET  /{container-id}?status_code  → IN_PROGRESS until Meta has the file
 *   3. POST /{ig-user-id}/media_publish  → the media id (the post now exists)
 *   4. GET  /{media-id}?permalink        → the post's own URL
 *
 * Step 3 is the ONLY non-idempotent call, and that is what decides the outcome
 * mapping: a failure at step 1 or 2 is Rejected, because a container is not a
 * post and nothing is live. A dropped connection at step 3 is Unknown — a post
 * may exist and a retry would double it. Collapsing those two would be the
 * classic duplicate-on-a-public-account bug.
 *
 * SupportsDeletion is deliberately NOT implemented: `DELETE /{ig-media-id}`
 * answers "This api only supports Instagram API with Facebook login only" on this
 * token type, so a retraction path has to surface the permalink for a human
 * rather than believe a delete it never made.
 *
 * SupportsComments IS implemented, and the pair is not a contradiction: a
 * COMMENT can be deleted on this token type while a POST cannot. Two
 * capabilities, two interfaces — which is exactly why they are two interfaces
 * and not one flag with a comment next to it.
 */
class InstagramDriver extends BaseDriver implements ProvidesAnalytics, RefreshesTokens, SupportsComments
{
    /** Caption ceiling, hashtags included — they are caption text here. */
    private const CAPTION_LIMIT = 2200;

    /** Instagram has enforced at most five hashtags per post since Dec 2025. */
    private const TAG_LIMIT = 5;

    /** A carousel takes 2-10 children. */
    private const CAROUSEL_MAX = 10;

    /** Long-lived Instagram user tokens last 60 days and refresh in place. */
    private const TOKEN_LIFETIME_DAYS = 60;

    /**
     * The marker that opens the error of a reply Meta refused with an ACTION
     * BLOCK rather than an ordinary error.
     *
     * A published constant, not a phrase to grep for: the caller's response to an
     * action block is account-level and lasting (stop writing comments), so both
     * sides have to agree on how one is recognised, and a string literal repeated
     * in two repositories is how they stop agreeing. See
     * {@see InstagramClient::isSpamBlock()} for what an action block actually is.
     */
    public const COMMENT_BLOCKED = '[action-blocked]';

    private ?InstagramClient $client = null;

    public function label(): string
    {
        return 'Instagram';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            placements: [Placement::Reel, Placement::Story, Placement::Feed],
            bodyLimit: self::CAPTION_LIMIT,
            captionLimit: self::CAPTION_LIMIT,
            tagLimit: self::TAG_LIMIT,
            maxItemsPerMessage: self::CAROUSEL_MAX,
            // JPEG only for images, and this is not pedantry: a PNG handed to
            // `image_url` fails the container with a generic media error, so any
            // caller holding PNGs needs a conversion step before it gets here.
            mimeTypes: ['image/jpeg', 'video/mp4', 'video/quicktime'],
            maxVideoBytes: 1024 * 1024 * 1024,
            maxImageBytes: 8 * 1024 * 1024,
            pullsMedia: true,
        );
    }

    public function rateProfile(): RateProfile
    {
        // No per-request pacing: the constraint is a daily publishing quota, not
        // a messages-per-minute rate. publishingLimit() is how a caller reads it.
        return new RateProfile(0);
    }

    /**
     * At least one account has to hold an id AND a token. Counting config KEYS
     * would report the network usable while every token is still blank, and the
     * fan-out would then create a share row for a network that cannot publish.
     */
    public function isUsable(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        foreach ($this->accountKeys() as $key) {
            $account = $this->account($key);

            if ($account->isConfigured() && $account->token !== null) {
                return true;
            }
        }

        return false;
    }

    public function health(): Health
    {
        $details = [];
        $configured = false;

        foreach ($this->accountKeys() as $key) {
            $account = $this->account($key);

            if (! $account->isConfigured() || $account->token === null) {
                $details[$key] = ['configured' => false];

                continue;
            }

            $configured = true;
            $profile = $this->read($account, $account->id, ['fields' => 'id,username,account_type,media_count']);

            $details[$key] = [
                'configured' => true,
                'id' => $account->id,
                'username' => $profile['username'] ?? $account->handle,
                'accountType' => $profile['account_type'] ?? null,
                'mediaCount' => $profile['media_count'] ?? null,
                'error' => $profile === [] ? 'Could not read the account back from Instagram.' : null,
            ];
        }

        return new Health($this->network, $this->isEnabled(), $configured, $details);
    }

    // -----------------------------------------------------------------
    // Publishing
    // -----------------------------------------------------------------

    public function publish(PublishRequest $request): PublishResult
    {
        $account = $request->account();

        if (! $account->isConfigured() || $account->token === null) {
            return PublishResult::rejected("The Instagram account '{$account->key}' has no id or access token configured.");
        }

        if (! $this->capabilities()->supports($request->placement())) {
            return PublishResult::rejected("Instagram cannot publish a {$request->placement()->value}.");
        }

        if (! $request->hasMedia()) {
            return PublishResult::rejected('Instagram has no text-only post; a Reel, Story or Feed post needs media.');
        }

        if (($refusal = $this->unacceptableMedia($request)) !== null) {
            return PublishResult::rejected($refusal);
        }

        foreach ($request->media as $media) {
            if ($media->url === null) {
                return PublishResult::rejected(
                    'Instagram FETCHES media from a public URL, so every Media needs a `url` (a local `path` is unreachable to Meta).',
                );
            }
        }

        $caption = $this->caption($request);

        try {
            $container = count($request->media) > 1
                ? $this->carouselContainer($account, $request, $caption)
                : $this->singleContainer($account, $request, $caption);
        } catch (ConnectionException $exception) {
            // A container is not a post: nothing is live, so this is a clean
            // rejection and a corrected retry is allowed.
            return PublishResult::rejected('Could not reach Instagram to prepare the post: '.$exception->getMessage());
        }

        if (! $container->isSent()) {
            return $container;
        }

        return $this->publishContainer($account, (string) $container->externalId);
    }

    /**
     * One Reel, Story or single-image Feed post.
     */
    private function singleContainer(Account $account, PublishRequest $request, string $caption): PublishResult
    {
        $media = $request->firstMedia();

        if ($media === null) {
            return PublishResult::rejected('No media to publish.');
        }

        $payload = array_filter([
            'media_type' => match ($request->placement()) {
                Placement::Reel => 'REELS',
                Placement::Story => 'STORIES',
                default => null,
            },
            $media->isVideo() ? 'video_url' : 'image_url' => $media->url,
            // A Story is ephemeral and captionless by design; sending a caption
            // is not an error, it is silently dropped, which is worse.
            'caption' => $request->placement() === Placement::Story ? null : $caption,
            'cover_url' => $request->placement() === Placement::Reel ? $media->thumbnailUrl : null,
            'share_to_feed' => $request->placement() === Placement::Reel ? 'true' : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $this->awaitContainer($account, $payload);
    }

    /**
     * A carousel: one child container per page, then a parent that names them.
     *
     * Children are created and awaited FIRST because the parent is rejected if
     * any child is not yet FINISHED — and a half-built carousel leaves orphan
     * containers that expire on their own in 24 hours, which is why failing here
     * needs no cleanup call.
     */
    private function carouselContainer(Account $account, PublishRequest $request, string $caption): PublishResult
    {
        $pages = array_slice($request->media, 0, self::CAROUSEL_MAX);
        $children = [];

        foreach ($pages as $index => $page) {
            $child = $this->awaitContainer($account, array_filter([
                'is_carousel_item' => 'true',
                $page->isVideo() ? 'video_url' : 'image_url' => $page->url,
            ], static fn (mixed $value): bool => $value !== null));

            if (! $child->isSent()) {
                return PublishResult::rejected(
                    sprintf('Instagram refused page %d of the carousel: %s', $index + 1, $child->error ?? 'unknown reason'),
                );
            }

            $children[] = (string) $child->externalId;
        }

        return $this->awaitContainer($account, [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => $caption,
        ]);
    }

    /**
     * Create a container and wait until Meta has actually fetched the media.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    private function awaitContainer(Account $account, array $payload): PublishResult
    {
        $response = $this->client()->post($account->id.'/media', $payload, (string) $account->token);

        if (! $response->successful()) {
            return PublishResult::rejected(InstagramClient::errorOf($response));
        }

        $id = $response->json('id');

        if (! is_scalar($id) || (string) $id === '') {
            return PublishResult::rejected('Instagram accepted the container but returned no id.');
        }

        return $this->awaitReady($account, (string) $id);
    }

    /**
     * Poll a container's `status_code` until Meta is done with it.
     *
     * A container that never leaves IN_PROGRESS is a Rejected, not an Unknown:
     * nothing has been published, so a retry is safe. EXPIRED means the 24-hour
     * window passed; ERROR carries a `status` string worth surfacing verbatim
     * because it names the media problem (aspect ratio, codec, duration).
     *
     * @throws ConnectionException
     */
    private function awaitReady(Account $account, string $containerId): PublishResult
    {
        $interval = $this->number('poll_interval_seconds', 5);
        $attempts = max(1, intdiv($this->number('poll_budget_seconds', 300), max(1, $interval)));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $response = $this->client()->get($containerId, ['fields' => 'status_code,status'], (string) $account->token);

            if (! $response->successful()) {
                return PublishResult::rejected(InstagramClient::errorOf($response));
            }

            $status = strtoupper((string) $response->json('status_code'));

            if ($status === 'FINISHED' || $status === 'PUBLISHED') {
                return PublishResult::sent($containerId);
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                $detail = $response->json('status');

                return PublishResult::rejected(sprintf(
                    'Instagram could not process the media (%s)%s.',
                    $status,
                    is_string($detail) && $detail !== '' ? ': '.$detail : '',
                ));
            }

            Sleep::for($interval)->seconds();
        }

        return PublishResult::rejected(
            'Instagram was still fetching the media after '.$this->number('poll_budget_seconds', 300).' seconds; nothing was published.',
        );
    }

    /**
     * The one non-idempotent call. A dropped connection here is Unknown: the post
     * may exist, and only a human should decide what happens next.
     */
    private function publishContainer(Account $account, string $containerId): PublishResult
    {
        try {
            $response = $this->client()->post(
                $account->id.'/media_publish',
                ['creation_id' => $containerId],
                (string) $account->token,
            );
        } catch (ConnectionException $exception) {
            return PublishResult::unknown(
                'The Instagram publish call was never confirmed ('.$exception->getMessage()
                .') — a post may already be live on the account; check it before re-posting.',
            );
        }

        if (! $response->successful()) {
            // Graph answered, so no post was created for THIS call. Still worth
            // saying which step failed: a rejection here is a different fix from
            // a rejection while the media was being fetched.
            return PublishResult::rejected('Instagram refused to publish the prepared post. '.InstagramClient::errorOf($response));
        }

        $mediaId = $response->json('id');

        if (! is_scalar($mediaId) || (string) $mediaId === '') {
            return PublishResult::unknown(
                'Instagram accepted the publish call but returned no media id — a post may be live; check the account before re-posting.',
            );
        }

        $mediaId = (string) $mediaId;

        return PublishResult::sent($mediaId, $this->permalink($account, $mediaId));
    }

    /**
     * The post's own URL — the thing the previous publishing middleman could
     * never give us, which is why a video could only ever link to a profile.
     *
     * A failure here is cosmetic and must not fail the publish: the post exists.
     */
    public function permalink(Account $account, int|string $mediaId): ?string
    {
        $url = $this->media($account, $mediaId, ['permalink'])['permalink'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Named fields of one media object, or [] when the read failed.
     *
     * Public because a caller may want more than the permalink — a caption, a
     * media type, a timestamp — and the alternative is a method per field, each
     * a copy of this one call. A failure is [] rather than an exception for the
     * same reason permalink() swallows one: nothing here is worth failing a
     * publish, a cache fill or a comment claim over.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function media(Account $account, int|string $mediaId, array $fields): array
    {
        return $this->read($account, (string) $mediaId, ['fields' => implode(',', $fields)]);
    }

    // -----------------------------------------------------------------
    // Analytics
    // -----------------------------------------------------------------

    public function mediaMetrics(Account $account, int|string $externalId): Metrics
    {
        $metrics = ['reach', 'likes', 'comments', 'saved', 'shares', 'views', 'ig_reels_avg_watch_time'];

        $data = $this->read($account, (string) $externalId.'/insights', ['metric' => implode(',', $metrics)]);

        return new Metrics($this->network, $this->flattenInsights($data), label: (string) $externalId);
    }

    public function accountMetrics(Account $account): Metrics
    {
        $profile = $this->read($account, $account->id, ['fields' => 'username,followers_count,media_count']);

        $insights = $this->read($account, $account->id.'/insights', [
            'metric' => 'reach,views,profile_views',
            'period' => 'day',
            'metric_type' => 'total_value',
        ]);

        /** @var array<string, int|float|string|null> $values */
        $values = [
            'followers' => is_numeric($profile['followers_count'] ?? null) ? (int) $profile['followers_count'] : null,
            'posts' => is_numeric($profile['media_count'] ?? null) ? (int) $profile['media_count'] : null,
            ...$this->flattenInsights($insights),
        ];

        return new Metrics($this->network, $values, label: is_string($profile['username'] ?? null) ? $profile['username'] : $account->key);
    }

    /**
     * The daily publishing quota, read BEFORE a burst so a backfill
     * can stop rather than collect rejections.
     */
    public function publishingLimit(Account $account): Metrics
    {
        $data = $this->read($account, $account->id.'/content_publishing_limit', [
            'fields' => 'config,quota_usage',
        ]);

        $row = is_array($data['data'][0] ?? null) ? $data['data'][0] : $data;
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];

        return new Metrics($this->network, [
            'used' => is_numeric($row['quota_usage'] ?? null) ? (int) $row['quota_usage'] : null,
            'quota' => is_numeric($config['quota_total'] ?? null) ? (int) $config['quota_total'] : null,
            'windowHours' => is_numeric($config['quota_duration'] ?? null) ? intdiv((int) $config['quota_duration'], 3600) : null,
        ], label: $account->key);
    }

    // -----------------------------------------------------------------
    // Tokens
    // -----------------------------------------------------------------

    public function credentials(Account $account): ?Credentials
    {
        if ($account->token === null) {
            return null;
        }

        // `expires_in` is only reported by the refresh/debug endpoints, so a
        // plain read cannot know the expiry — and guessing one would be worse
        // than admitting it. The refresh path fills it in.
        return new Credentials($account->token);
    }

    /**
     * Renew a 60-day long-lived token in place. Instagram's own endpoint, on the
     * same host, with the token authenticating its own renewal — no app secret
     * involved and no user interaction.
     */
    public function refresh(Account $account): ?Credentials
    {
        if ($account->token === null) {
            return null;
        }

        try {
            $response = $this->client()->get('refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
            ], $account->token);
        } catch (ConnectionException) {
            return null;
        }

        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        $seconds = $response->json('expires_in');

        return new Credentials(
            accessToken: $token,
            expiresAt: is_numeric($seconds)
                ? Carbon::now()->addSeconds((int) $seconds)
                : Carbon::now()->addDays(self::TOKEN_LIFETIME_DAYS),
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Compose the caption Instagram actually receives: the body, then the
     * hashtags, because on Instagram a hashtag IS caption text. That fact lives
     * here rather than in a caller so nobody has to remember it — and so the
     * budget is spent once, with the BODY giving way to the tags rather than the
     * tags being cut off mid-word by a hard truncation.
     */
    private function caption(PublishRequest $request): string
    {
        $body = trim($request->body);
        $tags = array_slice(array_values(array_filter(array_map(
            static fn (string $tag): string => ltrim(trim($tag), '#'),
            $request->tags,
        ), static fn (string $tag): bool => $tag !== '')), 0, self::TAG_LIMIT);

        if ($tags === []) {
            return mb_substr($body, 0, self::CAPTION_LIMIT);
        }

        $block = '#'.implode(' #', $tags);
        $budget = self::CAPTION_LIMIT - mb_strlen($block) - 2;

        if ($budget < 1) {
            return mb_substr($block, 0, self::CAPTION_LIMIT);
        }

        return trim(mb_substr($body, 0, $budget))."\n\n".$block;
    }

    /**
     * A GET whose failure is not worth propagating — a read-out, a permalink, an
     * insight panel. Returns [] rather than throwing.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function read(Account $account, string $path, array $query): array
    {
        if ($account->token === null) {
            return [];
        }

        try {
            $response = $this->client()->get($path, $query, $account->token);
        } catch (ConnectionException) {
            return [];
        }

        return $response->successful() && is_array($response->json()) ? $response->json() : [];
    }

    /**
     * Graph returns insights as a list of {name, values|total_value} objects.
     * Flatten to name => number, which is what a caller wants and what caches
     * cleanly.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|float|string|null>
     */
    private function flattenInsights(array $data): array
    {
        $rows = is_array($data['data'] ?? null) ? $data['data'] : [];
        $values = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null)) {
                continue;
            }

            $value = is_array($row['total_value'] ?? null)
                ? ($row['total_value']['value'] ?? null)
                : ($row['values'][0]['value'] ?? null);

            $values[$row['name']] = is_numeric($value) ? $value + 0 : null;
        }

        return $values;
    }

    // -----------------------------------------------------------------
    // Comments
    // -----------------------------------------------------------------

    /**
     * Reply to a comment. A reply CREATES a public object, so this carries the
     * full three-state outcome: refused before anything existed, created, or
     * never heard back.
     */
    public function replyToComment(Account $account, int|string $commentId, string $text): PublishResult
    {
        if ($account->token === null) {
            return PublishResult::rejected('The Instagram account has no token, so no reply can be posted.');
        }

        try {
            $response = $this->client()->post((string) $commentId.'/replies', ['message' => $text], $account->token);
        } catch (ConnectionException $unreachable) {
            // The connection dropped around a NON-IDEMPOTENT write. A reply may
            // be live under the comment, so this is never safe to retry blind —
            // the same reasoning as step 3 of the publish ladder.
            return PublishResult::unknown('The reply request to Instagram did not complete: '.$unreachable->getMessage());
        }

        if (! $response->successful()) {
            // An action block is still a REJECTION — nothing was created, so the
            // caller must be free to release its claim and try a different text
            // later. What it is not is an ordinary refusal, and the caller has to
            // be able to tell: the marker is a published constant both sides
            // reference, rather than a phrase one side greps for.
            return PublishResult::rejected(
                (InstagramClient::isSpamBlock($response) ? self::COMMENT_BLOCKED.' ' : '')
                .'Instagram refused the reply. '.InstagramClient::errorOf($response),
            );
        }

        $replyId = $response->json('id');

        if (! is_scalar($replyId) || (string) $replyId === '') {
            // Accepted, but we cannot name what was created — so it can never be
            // deleted or matched against an inbound webhook echo. Unknown rather
            // than Sent, because Sent would claim we hold an id we do not.
            return PublishResult::unknown(
                'Instagram accepted the reply but returned no comment id — a reply may be live; check the post before replying again.',
            );
        }

        // No url: a comment has no permalink endpoint on this host, and inventing
        // one would put a URL in `output` that resolves to something else.
        return PublishResult::sent((string) $replyId);
    }

    /**
     * Hide or unhide a comment.
     *
     * A 200 is NOT enough. Graph answers this endpoint with `{"success":true}`
     * and will answer 200 with a different body when it has quietly done nothing,
     * so the body is what is asserted — a caller that trusted the status would
     * report a hide that never happened.
     */
    public function hideComment(Account $account, int|string $commentId, bool $hidden): bool
    {
        return $this->commentWrite(
            $account,
            fn (string $token) => $this->client()->post((string) $commentId, ['hide' => $hidden ? 'true' : 'false'], $token),
        );
    }

    /**
     * Delete a comment.
     *
     * This works where whole-POST deletion does not (see the class docblock):
     * Instagram Login refuses `DELETE /{ig-media-id}` but accepts
     * `DELETE /{ig-comment-id}` for a comment the account owns and for a reader's
     * comment on the account's own media. Two capabilities, two interfaces —
     * which is why this driver implements SupportsComments and not
     * SupportsDeletion.
     */
    public function deleteComment(Account $account, int|string $commentId): bool
    {
        return $this->commentWrite(
            $account,
            fn (string $token) => $this->client()->delete((string) $commentId, $token),
        );
    }

    /**
     * The shared shape of the two boolean comment writes: no token is false, an
     * unreachable host is false, and a 200 without `success: true` is false.
     *
     * @param  callable(string): \Illuminate\Http\Client\Response  $call
     */
    private function commentWrite(Account $account, callable $call): bool
    {
        if ($account->token === null) {
            return false;
        }

        try {
            $response = $call($account->token);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }

    /**
     * How fast COMMENTS may be written, which is a different question from how
     * fast posts may be published.
     *
     * Its own profile rather than a change to rateProfile(), because Instagram
     * governs the two separately: publishing is bounded by a daily quota with no
     * per-request pacing, while commenting is policed by an undocumented
     * anti-spam heuristic that scores BURST RATE and repetitive text (see
     * isSpamBlock()). Folding a comment floor into rateProfile() would silently
     * slow the Reel publisher, which has no such constraint.
     *
     * The number is a floor, not a target: the application paces well outside it.
     */
    public function commentRateProfile(): RateProfile
    {
        return new RateProfile(20_000);
    }

    public function client(): InstagramClient
    {
        return $this->client ??= new InstagramClient($this->text('api_base', 'https://graph.instagram.com/v23.0'));
    }

    public function setClient(InstagramClient $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Instagram's own refusal text for a delete attempt, kept as a method rather
     * than a comment so a caller can show the reason it is not offering the
     * button. See the class docblock: this token type cannot delete.
     */
    public function deletionUnavailableReason(): string
    {
        return 'Instagram does not allow deleting a post through the API on an Instagram-Login token; remove it from the app or from instagram.com.';
    }
}
