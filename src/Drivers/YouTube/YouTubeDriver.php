<?php

namespace Hojabbr\Social\Drivers\YouTube;

use Hojabbr\Social\Contracts\ProvidesAnalytics;
use Hojabbr\Social\Contracts\RefreshesTokens;
use Hojabbr\Social\Contracts\SupportsDeletion;
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
use Illuminate\Support\Facades\Log;

/**
 * YouTube, through the Data API v3 with a resumable upload.
 *
 * A Short and a long video are the SAME insert call: YouTube decides which one it
 * is from the file itself (vertical, at most three minutes), and there is no flag
 * to ask for. So `Placement::Reel` and `Placement::LongVideo` differ here only in
 * the URL we hand back — never in what is sent — and a caller that lies about the
 * placement gets a working post with a link that redirects.
 *
 * A channel is an ACCOUNT, not the network. The grant — one Google refresh token
 * per connected channel — therefore lives in `accounts.youtube.<key>.refresh_token`
 * and every call here derives its access token from the account it was handed.
 * Holding it at network level made "one channel" a property of the driver rather
 * than of the config, so a second channel was unreachable without a second
 * network entry duplicating the OAuth client. The client id/secret DO stay at
 * network level: they identify the Google app, and every channel connected
 * through it shares them.
 *
 * The outcome mapping follows where the video starts to exist:
 *
 *   token refresh / session open  → Rejected. No video, nothing to undo.
 *   the bytes PUT                 → the dangerous half. A dropped connection or a
 *                                   5xx is Unknown: YouTube may have finished
 *                                   receiving and lost the answer, and a retry
 *                                   would publish the video twice on a public
 *                                   channel.
 *   thumbnails.set                → never fails the publish. The video is live;
 *                                   a missing custom thumbnail is cosmetic.
 */
class YouTubeDriver extends BaseDriver implements ProvidesAnalytics, RefreshesTokens, SupportsDeletion
{
    /**
     * What the OAuth grant has to cover: upload, read back what we uploaded,
     * read its analytics — and `youtube.force-ssl`, which is the scope
     * `videos.delete` needs. Without that last one this class implements
     * SupportsDeletion and every deletion answers 403, which is worse than not
     * offering deletion at all: a retraction would report success it never had.
     */
    public const SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/youtube.force-ssl https://www.googleapis.com/auth/yt-analytics.readonly';

    /** Hard API limit; a longer title is refused outright rather than trimmed by YouTube. */
    private const TITLE_LIMIT = 100;

    /** Description limit. */
    private const DESCRIPTION_LIMIT = 5000;

    /** Tag count we send. The real constraint is 500 characters across all tags. */
    private const TAG_LIMIT = 15;

    private const TAG_CHARACTER_BUDGET = 500;

    /** 256GB by the docs; irrelevant to us, kept honest rather than invented. */
    private const MAX_VIDEO_BYTES = 274877906944;

    /** Custom thumbnails are capped at 2MB. */
    private const MAX_THUMBNAIL_BYTES = 2 * 1024 * 1024;

    /**
     * YouTube Analytics needs a date range and has no "lifetime" flag. This is
     * the platform's own launch month, which is the idiomatic way to ask for
     * everything.
     */
    private const ANALYTICS_EPOCH = '2005-02-01';

    private ?YouTubeClient $client = null;

    public function label(): string
    {
        return 'YouTube';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            placements: [Placement::Reel, Placement::LongVideo],
            bodyLimit: self::DESCRIPTION_LIMIT,
            captionLimit: self::DESCRIPTION_LIMIT,
            tagLimit: self::TAG_LIMIT,
            maxItemsPerMessage: 1,
            mimeTypes: ['video/mp4', 'video/quicktime', 'video/webm'],
            maxVideoBytes: self::MAX_VIDEO_BYTES,
            maxImageBytes: self::MAX_THUMBNAIL_BYTES,
        );
    }

    public function rateProfile(): RateProfile
    {
        // No pacing: the constraint is a daily API quota, not a rate.
        return new RateProfile(0);
    }

    /**
     * An account is postable when it holds its own grant. The CHANNEL ID is not
     * part of that test — `mine=true` reads the channel behind a token, so the id
     * is a convenience for an admin page rather than a credential — which is why
     * this overrides the base class's "configured means it has an id".
     */
    public function hasAccount(string $key): bool
    {
        return $this->refreshTokenFor($this->account($key)) !== '';
    }

    public function isUsable(): bool
    {
        return $this->isEnabled() && $this->hasOauthCredentials() && $this->connectedKeys() !== [];
    }

    public function health(): Health
    {
        if (! $this->hasOauthCredentials()) {
            return new Health($this->network, $this->isEnabled(), false, [
                'clientConfigured' => false,
                'connected' => false,
                'channels' => [],
            ]);
        }

        $connected = $this->connectedKeys();

        if ($connected === []) {
            // Deliberately NOT "ready": the client is configured but no channel
            // is granted, so this network can route nowhere. Reporting ready here
            // is how an admin page shows green while every publish is refused.
            return new Health($this->network, $this->isEnabled(), false, [
                'clientConfigured' => true,
                'connected' => false,
                'channels' => [],
            ], 'The Google client is configured but no channel has been connected yet.');
        }

        $channels = [];

        foreach ($connected as $key) {
            $channels[$key] = $this->channelSummary($this->account($key));
        }

        $unreadable = array_keys(array_filter($channels, static fn (array $channel): bool => $channel === []));

        return new Health($this->network, $this->isEnabled(), true, [
            'clientConfigured' => true,
            'connected' => true,
            'privacyStatus' => $this->text('privacy_status', 'public'),
            'channels' => $channels,
        ], $unreadable === [] ? null : 'Could not read these channels back from YouTube; the grant may have been revoked: '
            .implode(', ', $unreadable).'.');
    }

    // -----------------------------------------------------------------
    // Publishing
    // -----------------------------------------------------------------

    public function publish(PublishRequest $request): PublishResult
    {
        if (! $this->capabilities()->supports($request->placement())) {
            return PublishResult::rejected("YouTube cannot publish a {$request->placement()->value}; it has no API for image posts.");
        }

        $media = $request->firstMedia();

        if ($media === null || ! $media->isVideo()) {
            return PublishResult::rejected('A YouTube publish needs a video.');
        }

        if ($media->path === null || ! is_file($media->path)) {
            return PublishResult::rejected('YouTube uploads BYTES, so the media needs a readable local `path` (a URL is not fetched).');
        }

        if (($refusal = $this->unacceptableMedia($request)) !== null) {
            return PublishResult::rejected($refusal);
        }

        $bytes = $media->bytes() ?? 0;

        if ($bytes < 1) {
            return PublishResult::rejected("The video at {$media->path} is empty.");
        }

        $account = $request->destination->account;
        $token = $this->accessToken($account);

        if ($token === null) {
            return PublishResult::rejected(
                "Could not get a YouTube access token for the '{$account->key}' channel — "
                .'its grant is missing, revoked, or Google refused the refresh.',
            );
        }

        $metadata = $this->metadata($request);
        $mimeType = $media->mimeType ?? 'video/mp4';

        try {
            $session = $this->client()->openUploadSession($metadata, $mimeType, $bytes, $token);
        } catch (ConnectionException $exception) {
            return PublishResult::rejected('Could not reach YouTube to open the upload: '.$exception->getMessage());
        }

        if (! $session->successful()) {
            return PublishResult::rejected(YouTubeClient::errorOf($session));
        }

        $sessionUri = $session->header('Location');

        if ($sessionUri === '') {
            // A 2xx with no Location is not a success: there is nowhere to send
            // the bytes. Saying so is the whole point — a silent pass here would
            // record a published video that never existed.
            return PublishResult::rejected('YouTube accepted the upload request but returned no session URI in its Location header.');
        }

        return $this->upload($request, $sessionUri, $media->path, $mimeType, $bytes, $token);
    }

    /**
     * The half where the video starts to exist.
     */
    private function upload(
        PublishRequest $request,
        string $sessionUri,
        string $path,
        string $mimeType,
        int $bytes,
        string $token,
    ): PublishResult {
        try {
            $response = $this->client()->uploadBytes($sessionUri, $path, $mimeType, $bytes, $token);
        } catch (ConnectionException $exception) {
            return PublishResult::unknown(
                'The YouTube upload was never confirmed ('.$exception->getMessage()
                .') — a video may already be on the channel; check it before re-uploading.',
            );
        }

        if ($response->serverError()) {
            return PublishResult::unknown(
                'YouTube answered the upload with HTTP '.$response->status()
                .' — the video may have been received; check the channel before re-uploading.',
            );
        }

        if (! $response->successful()) {
            return PublishResult::rejected(YouTubeClient::errorOf($response));
        }

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            return PublishResult::unknown(
                'YouTube accepted the upload but returned no video id — check the channel before re-uploading.',
            );
        }

        $this->attachThumbnail($request, $id, $token);

        return PublishResult::sent($id, $this->watchUrl($id, $request->placement()));
    }

    /**
     * A custom thumbnail, when the caller has one and the channel is allowed one.
     *
     * Deliberately best-effort: the video is already live, so a failure here is
     * logged and swallowed. Turning a live video into a failed publish because its
     * poster did not stick would release the claim and re-upload the video.
     */
    private function attachThumbnail(PublishRequest $request, string $videoId, string $token): void
    {
        $thumbnail = $request->firstMedia()?->thumbnailPath;

        if ($thumbnail === null || ! is_file($thumbnail)) {
            return;
        }

        $size = filesize($thumbnail);

        if ($size === false || $size > self::MAX_THUMBNAIL_BYTES) {
            Log::warning('Skipped a YouTube thumbnail over the 2MB limit.', ['video' => $videoId, 'bytes' => $size]);

            return;
        }

        // Derived, not assumed: thumbnails.set takes a raw binary body, so the
        // Content-Type is the ONLY thing telling YouTube what the bytes are. A
        // hardcoded 'image/jpeg' over a PNG is a refusal the caller cannot see,
        // because this step is best-effort and swallows its own failure.
        $mimeType = mime_content_type($thumbnail) ?: 'image/jpeg';

        try {
            $response = $this->client()->setThumbnail($videoId, $thumbnail, $mimeType, $token);

            if (! $response->successful()) {
                Log::warning('YouTube refused the custom thumbnail.', [
                    'video' => $videoId,
                    'error' => YouTubeClient::errorOf($response),
                ]);
            }
        } catch (ConnectionException $exception) {
            Log::warning('Could not set the YouTube thumbnail.', ['video' => $videoId, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * The videos.insert resource.
     *
     * @return array{snippet: array<string, mixed>, status: array<string, mixed>}
     */
    private function metadata(PublishRequest $request): array
    {
        return [
            'snippet' => array_filter([
                'title' => $this->title($request),
                'description' => mb_substr(trim($request->body), 0, self::DESCRIPTION_LIMIT),
                'tags' => $this->tags($request->tags),
                'categoryId' => $this->text('category_id', '25'),
                // Every value here is a string or a list of strings, so what is
                // being filtered out is the EMPTY ones: an empty `tags` array or
                // a blank category is a field YouTube would rather not see at
                // all than see empty.
            ], static fn (string|array $value): bool => $value !== '' && $value !== []),
            'status' => [
                'privacyStatus' => $this->text('privacy_status', 'public'),
                // Required on every insert since 2020; omitting it is a refusal,
                // not a default.
                'selfDeclaredMadeForKids' => $this->flag('made_for_kids'),
            ],
        ];
    }

    /**
     * YouTube refuses a title containing `<` or `>` outright, and one over 100
     * characters. Both are network rules rather than editorial ones, so they are
     * enforced here — a caller cannot compose its way around them.
     */
    private function title(PublishRequest $request): string
    {
        $title = str_replace(['<', '>'], '', trim((string) ($request->title ?? '')));

        if ($title === '') {
            // A video with no title is refused; fall back to the first line of
            // the description rather than sending an empty snippet.
            $title = trim(strtok(trim($request->body), "\n") ?: 'Video');
        }

        return mb_substr($title, 0, self::TITLE_LIMIT);
    }

    /**
     * Tags are a real API field here (not caption text), capped by COUNT and by a
     * 500-character total — exceed the total and the whole insert is refused, so
     * the budget is spent tag by tag and the rest dropped.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function tags(array $tags): array
    {
        $kept = [];
        $budget = self::TAG_CHARACTER_BUDGET;

        foreach ($tags as $tag) {
            $tag = ltrim(trim($tag), '#');

            if ($tag === '' || count($kept) >= self::TAG_LIMIT) {
                continue;
            }

            $cost = mb_strlen($tag) + 1;

            if ($cost > $budget) {
                break;
            }

            $budget -= $cost;
            $kept[] = $tag;
        }

        return $kept;
    }

    private function watchUrl(string $id, Placement $placement): string
    {
        return $placement === Placement::Reel
            ? "https://www.youtube.com/shorts/{$id}"
            : "https://www.youtube.com/watch?v={$id}";
    }

    // -----------------------------------------------------------------
    // Deletion
    // -----------------------------------------------------------------

    /**
     * YouTube genuinely can delete a video, so a retraction here removes the post
     * rather than reporting a permalink for a human — the opposite of Instagram.
     */
    public function delete(Account $account, int|string $externalId): bool
    {
        $token = $this->accessToken($account);

        if ($token === null) {
            return false;
        }

        try {
            $response = $this->client()->delete('videos', ['id' => (string) $externalId], $token);
        } catch (ConnectionException $exception) {
            Log::warning('Could not reach YouTube to delete a video.', ['video' => $externalId, 'error' => $exception->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('YouTube refused to delete a video.', [
                'video' => $externalId,
                'error' => YouTubeClient::errorOf($response),
            ]);
        }

        return $response->successful();
    }

    // -----------------------------------------------------------------
    // Analytics
    // -----------------------------------------------------------------

    public function mediaMetrics(Account $account, int|string $externalId): Metrics
    {
        $token = $this->accessToken($account);

        if ($token === null) {
            return Metrics::unavailable($this->network, 'No YouTube access token.');
        }

        $statistics = $this->read('videos', ['part' => 'statistics', 'id' => (string) $externalId], $token);
        $row = is_array($statistics['items'][0]['statistics'] ?? null) ? $statistics['items'][0]['statistics'] : [];

        $retention = $this->reports([
            'ids' => 'channel==MINE',
            'startDate' => self::ANALYTICS_EPOCH,
            'endDate' => Carbon::now()->toDateString(),
            'metrics' => 'estimatedMinutesWatched,averageViewDuration,averageViewPercentage',
            'filters' => 'video=='.$externalId,
        ], $token);

        return new Metrics($this->network, [
            'views' => $this->intOf($row['viewCount'] ?? null),
            'likes' => $this->intOf($row['likeCount'] ?? null),
            'comments' => $this->intOf($row['commentCount'] ?? null),
            ...$retention,
        ], label: (string) $externalId);
    }

    public function accountMetrics(Account $account): Metrics
    {
        $token = $this->accessToken($account);

        if ($token === null) {
            return Metrics::unavailable($this->network, 'No YouTube access token.');
        }

        $channel = $this->read('channels', array_filter([
            'part' => 'snippet,statistics',
            'id' => $account->id !== '' ? $account->id : null,
            'mine' => $account->id !== '' ? null : 'true',
        ]), $token);

        $item = is_array($channel['items'][0] ?? null) ? $channel['items'][0] : [];
        $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];

        $reports = $this->reports([
            'ids' => 'channel==MINE',
            'startDate' => Carbon::now()->subDays(28)->toDateString(),
            'endDate' => Carbon::now()->toDateString(),
            'metrics' => 'views,estimatedMinutesWatched,subscribersGained',
        ], $token);

        return new Metrics($this->network, [
            'subscribers' => $this->intOf($statistics['subscriberCount'] ?? null),
            'lifetimeViews' => $this->intOf($statistics['viewCount'] ?? null),
            'videos' => $this->intOf($statistics['videoCount'] ?? null),
            ...$reports,
        ], label: is_string($item['snippet']['title'] ?? null) ? $item['snippet']['title'] : $account->key);
    }

    /**
     * There is no publishing-quota endpoint. What limits uploads is the API
     * project's own daily quota, which is not readable through the API at all —
     * it is a Cloud console figure — so this reports the known cost rather than
     * pretending to measure it.
     */
    public function publishingLimit(Account $account): Metrics
    {
        return new Metrics($this->network, [
            'uploadsPerDay' => 100,
            'note' => 'videos.insert has its own ~100-calls-per-day bucket; YouTube exposes no endpoint for the remaining allowance.',
        ], label: $account->key);
    }

    // -----------------------------------------------------------------
    // OAuth and tokens
    // -----------------------------------------------------------------

    /**
     * Where to send the owner to grant the channel, once.
     */
    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return $this->client()->authorizationUrl($redirectUri, $state, self::SCOPES);
    }

    /**
     * Finish the one-time grant. The REFRESH TOKEN in the result is the thing
     * worth storing; the access token is derivable from it forever after.
     */
    public function exchangeCode(string $code, string $redirectUri): ?Credentials
    {
        $token = $this->client()->exchangeCode($code, $redirectUri);

        if ($token === null) {
            return null;
        }

        return new Credentials(
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'],
            expiresAt: $token['expires_in'] === null ? null : Carbon::now()->addSeconds($token['expires_in']),
        );
    }

    /**
     * A Google refresh token does not expire on a clock, so this mostly proves the
     * grant is still live — and refreshes the cached access token, which is what
     * makes a nightly run useful rather than ceremonial.
     */
    public function refresh(Account $account): ?Credentials
    {
        $refreshToken = $this->refreshTokenFor($account);

        if ($refreshToken === '') {
            return null;
        }

        $token = $this->client()->accessToken($refreshToken, fresh: true);

        return $token === null ? null : new Credentials(
            accessToken: $token['access_token'],
            refreshToken: $refreshToken,
            expiresAt: Carbon::now()->addSeconds($token['expires_in']),
        );
    }

    public function credentials(Account $account): ?Credentials
    {
        $refreshToken = $this->refreshTokenFor($account);

        if ($refreshToken === '') {
            return null;
        }

        // Reading an access token is not a rotation here — Google returns the
        // same refresh token untouched — so a status page may safely ask.
        $token = $this->client()->accessToken($refreshToken);

        return $token === null ? null : new Credentials(
            accessToken: $token['access_token'],
            refreshToken: $refreshToken,
            expiresAt: Carbon::now()->addSeconds($token['expires_in']),
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * The channel behind the stored grant: what an admin page shows to prove the
     * right account is connected, and where a fresh connect reads the channel id.
     *
     * @return array<string, mixed>
     */
    public function channelSummary(Account $account): array
    {
        $token = $this->accessToken($account);

        if ($token === null) {
            return [];
        }

        $channel = $this->read('channels', array_filter([
            'part' => 'snippet,statistics',
            'id' => $account->id !== '' ? $account->id : null,
            'mine' => $account->id !== '' ? null : 'true',
        ]), $token);

        $item = is_array($channel['items'][0] ?? null) ? $channel['items'][0] : [];

        if ($item === []) {
            return [];
        }

        return [
            'channelId' => is_string($item['id'] ?? null) ? $item['id'] : null,
            'channelTitle' => is_string($item['snippet']['title'] ?? null) ? $item['snippet']['title'] : null,
            'handle' => is_string($item['snippet']['customUrl'] ?? null) ? $item['snippet']['customUrl'] : null,
            'subscribers' => $this->intOf($item['statistics']['subscriberCount'] ?? null),
        ];
    }

    private function accessToken(Account $account): ?string
    {
        $refreshToken = $this->refreshTokenFor($account);

        if ($refreshToken === '' || ! $this->hasOauthCredentials()) {
            return null;
        }

        return $this->client()->accessToken($refreshToken)['access_token'] ?? null;
    }

    /**
     * One channel's grant. Read off the ACCOUNT so two channels cannot share a
     * token by accident — the network no longer holds one to fall back to.
     */
    private function refreshTokenFor(Account $account): string
    {
        return trim((string) $account->refreshToken);
    }

    /**
     * The account keys that actually hold a grant, in config order.
     *
     * @return list<string>
     */
    private function connectedKeys(): array
    {
        return array_values(array_filter($this->accountKeys(), $this->hasAccount(...)));
    }

    private function hasOauthCredentials(): bool
    {
        return $this->text('client_id') !== '' && $this->text('client_secret') !== '';
    }

    /**
     * A read whose failure is not worth propagating — a read-out or an insight
     * panel. Returns [] rather than throwing.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function read(string $path, array $query, string $token): array
    {
        try {
            $response = $this->client()->get($path, $query, $token);
        } catch (ConnectionException) {
            return [];
        }

        return $response->successful() && is_array($response->json()) ? $response->json() : [];
    }

    /**
     * YouTube Analytics answers with a columnHeaders/rows matrix rather than
     * named fields; flatten the single row into metric => value.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, int|float|null>
     */
    private function reports(array $query, string $token): array
    {
        try {
            $response = $this->client()->analytics($query, $token);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $headers = $response->json('columnHeaders');
        $row = $response->json('rows.0');

        if (! is_array($headers) || ! is_array($row)) {
            return [];
        }

        $values = [];

        foreach ($headers as $index => $header) {
            $name = is_array($header) ? ($header['name'] ?? null) : null;

            if (is_string($name)) {
                $values[$name] = is_numeric($row[$index] ?? null) ? $row[$index] + 0 : null;
            }
        }

        return $values;
    }

    private function intOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public function client(): YouTubeClient
    {
        return $this->client ??= new YouTubeClient(
            endpoints: [
                'api_base' => $this->text('api_base', 'https://www.googleapis.com/youtube/v3'),
                'upload_base' => $this->text('upload_base', 'https://www.googleapis.com/upload/youtube/v3'),
                'analytics_base' => $this->text('analytics_base', 'https://youtubeanalytics.googleapis.com/v2'),
                'token_endpoint' => $this->text('token_endpoint', 'https://oauth2.googleapis.com/token'),
                'authorize_endpoint' => $this->text('authorize_endpoint', 'https://accounts.google.com/o/oauth2/v2/auth'),
            ],
            clientId: $this->text('client_id'),
            clientSecret: $this->text('client_secret'),
        );
    }

    public function setClient(YouTubeClient $client): self
    {
        $this->client = $client;

        return $this;
    }
}
