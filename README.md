# Laravel Social

Driver-based social publishing and analytics for Laravel: Instagram, YouTube and Telegram behind one
contract, resolved the way Laravel resolves a disk or a queue connection.

```php
$driver = Social::driver('instagram');

$result = $driver->publish(new PublishRequest(
    destination: $driver->destination('fa', Placement::Reel),
    body: 'A short caption.',
    tags: ['bourse', 'tehran'],
    media: [Media::video(url: 'https://example.com/reel.mp4')],
));

$result->isSent();    // true
$result->externalId;  // '17901234567890123'
$result->url;         // the post's permalink
```

## Three outcomes

`publish()` never throws. Every driver maps its network's failures onto:

| Outcome | Meaning | Caller |
| --- | --- | --- |
| `Sent` | The object exists. | Record its id. |
| `Rejected` | The network refused; nothing was created. | Release the claim; a corrected retry is safe. |
| `Unknown` | Timeout, dropped connection, ambiguous 5xx; it may exist. | Keep the claim and surface it; never blind-retry. |

The boundary is where the object starts to exist: a failed Instagram container is `Rejected`, a dropped
connection during `media_publish` is `Unknown`.

## Requirements

- PHP 8.5+, Laravel 13
- `irazasyed/telegram-bot-sdk` ^3.15 for the Telegram driver
- `spatie/image` ^3.8, optional, for spec-compliant Telegram video thumbnails

## Install

```bash
composer require hojabbr/laravel-social
php artisan vendor:publish --tag=social-config
```

Publish the whole config and keep it whole: `mergeConfigFrom` merges only the first level, so nested keys
you delete are not backfilled and new nested keys need a re-publish.

## Configuration

`networks` are transports (credentials, API bases, queue lanes); `accounts` are places to post.

```php
'networks' => [
    'instagram' => ['driver' => 'instagram', 'enabled' => true, 'app_id' => '…', 'app_secret' => '…', 'queue' => 'shares'],
],

'accounts' => [
    'instagram' => [
        'fa' => ['id' => '17841400000000000', 'handle' => 'myaccount', 'token' => 'IGAA…'],
        'en' => ['id' => '17841400000000001', 'handle' => 'myaccount_en', 'token' => 'IGAA…'],
    ],
    'youtube' => [
        'fa' => ['id' => 'UC…', 'handle' => 'mychannel', 'refresh_token' => '1//…'],
    ],
],
```

- Account keys are your vocabulary (locale, brand, tenant); a single identity uses `default`.
- A network names its driver, so two networks can share one driver class.
- Per-account credentials (`token`, `refresh_token`) live on the account; app-wide ones (Meta app secret,
  Google client, bot token) on the network. `token` and `refresh_token` are separate so a rotation writing
  the access token cannot overwrite the grant.
- A network with no account for a key is simply not a target for it.
- The package never reads `env()` directly.

## Publishing

```php
$driver = Social::driver('youtube');

$result = $driver->publish(new PublishRequest(
    destination: $driver->destination('fa', Placement::Reel),
    title: 'How a bond auction works',
    body: "The long description.",
    tags: ['finance', 'education'],
    media: [Media::video('/path/to/short.mp4', thumbnailPath: '/path/to/cover.jpg')],
));
```

- `body` is the one text field; `title` exists for networks with a second one.
- `tags` carry no `#`; each driver spends them its network's way.
- `media` is in reading order; several items are an album or carousel, and `externalIds` lists every
  object (`externalId` is the first). A deletion must walk all of them.
- `Capabilities::$pullsMedia` says whether the network fetches a URL (Instagram) or takes bytes
  (Telegram, YouTube).

## Capabilities

```php
$caps = Social::driver('instagram')->capabilities();

$caps->bodyLimit;             // text without media
$caps->captionLimit;          // text with media
$caps->tagLimit;              // 5
$caps->maxItemsPerMessage;    // 10
$caps->maxVideoBytes;
$caps->maxImageBytes;
$caps->accepts('image/png');  // false: Instagram takes JPEG
$caps->supports(Placement::Story);
$caps->pullsMedia;            // true
$caps->thumbnailAspect;       // 16 / 9 on YouTube, null elsewhere
```

Drivers refuse media whose mime type is not listed, before sending.

## Optional contracts

Check with `instanceof`; a driver implements one only when its network has the feature.

| Contract | Implemented by |
| --- | --- |
| `SupportsDeletion` | Telegram, YouTube |
| `SupportsCoverUpdate` | YouTube |
| `SupportsMediaReplacement` (new file and caption in the same post) | Telegram |
| `SupportsComments` (write-only: reply, hide, delete) | Instagram |
| `SupportsTopics` | Telegram |
| `ProvidesAnalytics` | Instagram, YouTube |
| `RefreshesTokens` | Instagram, YouTube |

Instagram cannot delete posts, replace a Reel's cover or its video on an Instagram-Login token, and its comment read
API returns an empty list, so there is no read method.

## Analytics

Every method returns `Metrics`, a flat bag of values, including on failure (`Metrics::unavailable()`).
`toArray()` / `fromArray()` are the cache-safe shape.

```php
$driver->mediaMetrics($account, $mediaId)->get('reach');
$driver->accountMetrics($account)->values;
$driver->publishingLimit($account)->get('used');
```

## Token refresh

The driver renews and returns credentials; persisting them is yours. Then drop the memoised driver.

```php
$credentials = $driver->refresh($account);
$driver->credentials($account)?->daysRemaining();

Social::forget('instagram');
```

## Rate and health

```php
Sleep::for($driver->rateProfile()->pauseMsFor($messages))->milliseconds();

$health = $driver->health();   // never throws
$health->isUsable();
$health->details;
$health->error;
```

Spacing is per account and counts messages: Telegram's ~20 per minute is per chat, and an album spends
one slot per page.

## Testing

```php
use Hojabbr\Social\Testing\FakeDriver;

$fake = FakeDriver::fake('instagram');
$fake->willReturn(PublishResult::rejected('Caption too long.'));

// … run the code under test …

expect($fake->requests)->toHaveCount(1)
    ->and($fake->lastRequest()?->body)->toContain('#bourse');
```

Repeated `willReturn()` calls queue in order and the last repeats; `unusable()` makes the network look
switched off. Run the package's own suite with `composer test`.

## Adding a network

```php
Social::extend('mastodon', fn (array $network, array $accounts, string $name) =>
    new MastodonDriver($network, $accounts, $name));
```

Implement `Contracts\Driver` (extend `Drivers\BaseDriver` for config, accounts and `destination()`), plus
the optional contracts your network supports, and add a network entry naming the driver.

## Network notes

- **Instagram** (Instagram Login, `graph.instagram.com`): container, poll, publish, permalink. Meta pulls
  media, containers expire in 24 hours, `image_url` must be JPEG, at most 5 hashtags inside the
  2200-character caption. Read `publishingLimit()` before a burst. Token renewal sends the token in the
  query string.
- **YouTube**: OAuth refresh, resumable upload (the session URI is in the `Location` header), thumbnail,
  reads. `access_type=offline` plus `prompt=consent` returns a refresh token. Deletion needs the
  `youtube.force-ssl` scope. An unaudited Google Cloud project uploads as private only.
- **Telegram**: ~20 messages a minute per chat. The 50 MB limit covers the whole request (the driver caps
  at 45 MB). Nested Bot API objects must be JSON-encoded. `show_caption_above_media` must match on every
  album item. A bot deletes its own messages only within 48 hours, but edits them at any age:
  `replaceMedia()` swaps the file through `editMessageMedia` and sends the caption again, which Telegram
  otherwise clears.

## License

MIT. See [LICENSE.md](LICENSE.md).
