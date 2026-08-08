# Laravel Social

Driver-based social publishing and analytics for Laravel. Instagram, YouTube and
Telegram behind one contract, resolved the way Laravel resolves a filesystem disk
or a queue connection.

```php
$result = Social::driver('instagram')->publish(new PublishRequest(
    destination: Social::driver('instagram')->destination('fa', Placement::Reel),
    body: 'A short caption.',
    tags: ['bourse', 'tehran'],
    media: [Media::video(url: 'https://example.com/reel.mp4')],
));

$result->isSent();       // true
$result->externalId;     // '17901234567890123'
$result->url;            // the post's own permalink
```

## Why this exists

Publishing to a social network is not a request that either works or throws. It
has **three** outcomes, and a caller that records a durable claim has to tell them
apart:

| Outcome | What happened | What the caller should do |
| --- | --- | --- |
| `Sent` | The object exists. | Record its id. |
| `Rejected` | The network answered and refused. Deterministic: nothing was created. | Release the claim. A corrected retry is safe. |
| `Unknown` | A dropped connection, a timeout, an ambiguous 5xx. The object may or may not exist. | **Keep** the claim. Never blind-retry — surface it for a human. |

Every driver here maps its network's failures onto those three, and `publish()`
never throws. The mapping is decided by *where the object starts to exist*: an
Instagram container that fails validation is `Rejected` because a container is not
a post, while a dropped connection during `media_publish` is `Unknown` because a
post may be live. Collapsing those two is the classic duplicate-post bug.

## Requirements

- PHP 8.5+
- Laravel 13
- `irazasyed/telegram-bot-sdk` ^3.15 — only if you use the Telegram driver
- `spatie/image` ^3.8 — optional; lets the Telegram driver build a spec-compliant
  video thumbnail instead of letting Telegram generate one

Instagram and YouTube need no extra dependency: both are plain HTTP through
Laravel's own client.

## Install

```bash
composer require hojabbr/laravel-social
php artisan vendor:publish --tag=social-config
```

The provider registers itself through package discovery.

> **Publish the whole config file, and keep it whole.** `mergeConfigFrom` merges
> only the *first* level of the array. Our config is nested, so a nested key you
> delete from your copy is **not** backfilled from the package's — and a nested key
> a later version adds does not appear until you re-publish.

## Configuration

Two lists. `networks` are transports — credentials, API bases, queue lanes.
`accounts` are places to post.

```php
'networks' => [
    'instagram' => [
        'driver' => 'instagram',
        'enabled' => true,
        'app_id' => '…',
        'app_secret' => '…',
        'queue' => 'shares',
    ],
],

'accounts' => [
    'instagram' => [
        'fa' => ['id' => '17841400000000000', 'handle' => 'myaccount', 'token' => 'IGAA…'],
        'en' => ['id' => '17841400000000001', 'handle' => 'myaccount_en', 'token' => 'IGAA…'],
    ],
],
```

Two things follow from that split:

- **Account keys are your vocabulary, not the network's.** Key by locale, by
  brand, by tenant — whatever your routing decision actually is. A network with
  one identity uses `default`.
- **A network names its driver**, so `instagram` and `instagram_agency` can be the
  same driver class with different credentials. The *network* is the config entry;
  the *driver* is the code.

Per-account credentials (an Instagram user token) live on the account.
Network-wide credentials (a Meta app secret, a bot token) live on the network —
duplicating one secret per account is how two copies of it start to disagree.

Config keys carry `env()` defaults, which you are free to replace with whatever
your app uses for secrets. Nothing in this package reads `env()` directly.

## Publishing

A `PublishRequest` is the whole contract: it carries no model, no route, no
domain concept of yours.

```php
use Hojabbr\Social\Enums\Placement;
use Hojabbr\Social\Facades\Social;
use Hojabbr\Social\Values\{Media, PublishRequest};

$driver = Social::driver('youtube');

$result = $driver->publish(new PublishRequest(
    destination: $driver->destination('default', Placement::Reel),
    title: 'How a bond auction works',
    body: "The long description.\n\nSecond paragraph.",
    tags: ['finance', 'education'],
    media: [Media::video('/path/to/short.mp4', thumbnailPath: '/path/to/cover.jpg')],
));
```

- `body` is the single text field, because every network has exactly one — a
  Telegram message, an Instagram caption, a YouTube description. `title` is
  separate because only some networks have a second, shorter one.
- `tags` carry **no** leading `#`. Each driver spends them the way its network
  wants: Instagram appends them to the caption, YouTube sends a real API field.
- `media` is in reading order. More than one item is an album or a carousel.

### Bytes or a URL

The two transports are genuinely different, and `Capabilities::$pullsMedia` is
how you know which one you must provide:

```php
Media::video('/local/path.mp4');                        // Telegram, YouTube — bytes
Media::image(url: 'https://example.com/page-1.jpg');    // Instagram — it fetches
```

Instagram never receives bytes from you: Meta pulls the file from a public URL,
so a caller holding private files has to expose a reachable (ideally signed,
short-lived) URL first.

### Several objects from one call

An album or a carousel is *one* post with several objects. `externalIds` carries
all of them, in order, and `externalId` is the first — the one a pin or a reply
targets.

```php
$result = $telegram->publish(new PublishRequest(
    destination: $telegram->destination('default', Placement::Message, topic: 42),
    body: 'Three pages.',
    media: [Media::image($p1), Media::image($p2), Media::image($p3)],
    bodyAbove: true,
));

$result->externalIds; // [101, 102, 103]
```

A deletion path must walk all of them. Reading only the first is how pages 2..N
get stranded in a channel forever.

## Capabilities — so you never branch on a network name

```php
$caps = Social::driver('instagram')->capabilities();

$caps->textLimit(withMedia: true);   // 2200
$caps->tagLimit;                     // 5
$caps->maxItemsPerMessage;           // 10
$caps->accepts('image/png');         // false — Instagram takes JPEG
$caps->supports(Placement::Story);   // true
$caps->maxBytesFor(MediaKind::Video);
$caps->pullsMedia;                   // true
```

"Instagram wants JPEG", "a caption fits in 2200", "at most five hashtags" each
live in exactly one place: the driver that knows them.

Note what is deliberately **absent** from `Capabilities`: booleans for deletion,
topics and analytics. Those are optional contracts, and an `instanceof` cannot
disagree with itself the way a flag can.

## Optional contracts

A driver implements one only when its network genuinely has the feature, so you
check with `instanceof` and cannot ask a network for something it does not do.

| Contract | Implemented by | For |
| --- | --- | --- |
| `SupportsDeletion` | Telegram, YouTube | Removing a published object |
| `SupportsTopics` | Telegram | Creating/renaming/closing forum topics |
| `ProvidesAnalytics` | Instagram, YouTube | Reading numbers back |
| `RefreshesTokens` | Instagram, YouTube | Renewing credentials without a human |

The absences carry meaning. `InstagramDriver` does **not** implement
`SupportsDeletion`, because `DELETE /{ig-media-id}` answers *"This api only
supports Instagram API with Facebook login only"* on an Instagram-Login token — so
a retraction path is forced to surface the permalink for a human instead of
believing a delete it never made.

```php
if ($driver instanceof SupportsDeletion) {
    $driver->delete($driver->account('default'), $externalId);
}
```

## Analytics

Every analytics method returns `Metrics` — a flat bag of numbers — including on
failure, via `Metrics::unavailable()`. Analytics are read to be displayed, and a
page that cannot show one panel should show the others rather than 500.

```php
$driver = Social::driver('instagram');
$account = $driver->account('fa');

$driver->mediaMetrics($account, $mediaId)->get('reach');
$driver->accountMetrics($account)->values;
$driver->publishingLimit($account)->get('used');   // read BEFORE a burst
```

Flat, not typed-per-metric, for two reasons: every network names its own metrics,
and a typed schema is wrong the week a platform renames one. It also makes
caching honest — `toArray()` / `fromArray()` are the cache-safe shape, which
matters if your cache store serialises.

## Token refresh

The driver renews and **returns** the material; persisting it is yours, because
only you know where your secrets live. A package that wrote the token itself
would be a second writer to somebody else's store.

```php
$credentials = $driver->refresh($account);       // renews if close to expiry
$driver->credentials($account)?->daysRemaining(); // status read-out; never rotates

Social::forget('instagram');   // drop the memoised driver after you store it
```

`forget()` matters: a driver captures the credentials it was built with, so
renewing a token without forgetting the driver leaves the old one in use for the
rest of the process.

## Rate profiles

```php
Sleep::for($driver->rateProfile()->pauseMsFor($messages))->milliseconds();
```

The spacing is per **account**, and `pauseMsFor()` takes a message count on
purpose: Telegram's ~20-per-minute ceiling is per chat, a forum supergroup is one
chat however many topics it has, and an album spends one slot **per page**. A
sender that sleeps once per call walks into a 429 on its second album — and more
workers cannot buy throughput against a per-chat ceiling.

## Health

For an admin surface. Never throws: a diagnostic page that 500s tells nobody
anything.

```php
$health = $driver->health();

$health->isUsable();   // enabled AND holding credentials
$health->details;      // free-form live state: bot rights, channel title, expiry
$health->error;        // why the read failed, if it did
```

## Testing

`FakeDriver` replaces a network in the container, records what it was asked to
publish, and returns whatever outcome you script. It is for testing **your**
code — that a rejection releases a claim, that an unknown keeps it, that your
caption came out right.

```php
use Hojabbr\Social\Testing\FakeDriver;
use Hojabbr\Social\Values\PublishResult;

$fake = FakeDriver::fake('instagram');
$fake->willReturn(PublishResult::rejected('Caption too long.'));

// … run the code under test …

expect($fake->requests)->toHaveCount(1)
    ->and($fake->lastRequest()?->body)->toContain('#bourse');
```

Called several times, `willReturn()` queues its results in order and the last one
repeats — so a test that publishes twice can make the first attempt fail without
having to describe the second. `unusable()` makes the network look switched off,
which is the other branch most callers have.

## Adding a network

Register a factory and configure a network that names it. Nothing else changes,
including for callers.

```php
Social::extend('mastodon', fn (array $network, array $accounts, string $name) =>
    new MastodonDriver($network, $accounts, $name));
```

```php
'networks' => ['mastodon' => ['driver' => 'mastodon', 'enabled' => true, /* … */]],
```

Implement `Contracts\Driver`, plus whichever optional contracts your network
genuinely supports. Extending `Drivers\BaseDriver` gives you config reading,
account resolution and `destination()`.

## Network notes

Things the APIs do not advertise and that cost time to discover.

**Instagram** (Content Publishing API on Instagram Login,
`graph.instagram.com`) — container → poll → publish → read the permalink. Meta
pulls the media, containers expire in 24 h, and **only JPEG** is accepted for
`image_url` (a PNG fails the container with a generic media error). Hashtags are
capped at 5 and count against the 2200-character caption. Posts cannot be deleted
through this API. The publishing budget is 25 posts per rolling 24 h — read
`publishingLimit()` before a burst.

**YouTube** — no SDK; the whole path is an OAuth refresh, two upload calls, a
thumbnail POST and two reads. The resumable session URI arrives in a `Location`
**response header**, not in the body, and the second call PUTs the whole file
with no `Content-Range`. `access_type=offline` **plus** `prompt=consent` is what
actually returns a refresh token — without the prompt, Google re-grants a
previously consented scope and silently omits it. An unaudited Google Cloud
project has its uploads locked to `private` with no appeal.

**Telegram** — the ceiling is ~20 messages/minute per **chat**. The documented
50 MB multipart limit governs the whole *request*, not the file, so a 49.9 MB
video plus its thumbnail answers 413 (the driver caps at 45 MB). Nested Bot API
objects must be JSON-encoded, not passed as arrays, or multipart drops them
silently — which is how link previews stay switched on when you asked for them
off. `show_caption_above_media` must be identical on **every** album item or the
whole send is refused. A bot can only delete its own message for 48 hours.

## License

MIT. See [LICENSE.md](LICENSE.md).
