# Changelog

Semantic Versioning. Below `1.0.0` a breaking change lands in a new minor, so pin with `^0.6` and read
this file before moving between minors.

## 0.7.0 — 2026-09-24

### Added

- `Contracts\SupportsMediaReplacement`: `replaceMedia()` gives a published object a new file in place,
  keeping its link, views and reactions, and sends the caption again. It answers `Enums\MediaReplacement`:
  `Replaced`, `Missing` (the network confirmed the object is gone, so a fresh post cannot double it) or
  `Failed`. `TelegramDriver` implements it over
  `editMessageMedia`, built as multipart by hand because the SDK's method cannot upload. Instagram cannot
  (a Reel's video is fixed at creation).

## 0.6.2 — 2026-09-20

### Fixed

- `ProvidesAnalytics::mediaMetrics()` takes an optional `?Placement $placement`, and the Instagram driver
  asks for the metric set that placement actually answers. `/insights` refuses the WHOLE call when one
  metric does not apply, so a carousel or a story asked for `ig_reels_avg_watch_time` returned nothing at
  all rather than a partial reading. A story asks `reach,views,replies`; a feed post drops the reel metric;
  a reel and an unspecified placement are unchanged, so the parameter is additive.

## 0.6.1 — 2026-08-19

### Fixed

- Instagram token renewal sends the token in the query string (`InstagramClient::getWithTokenInQuery()`).
  `refresh_access_token` refuses a Bearer header, so renewal never worked and read as a dead token.

## 0.6.0 — 2026-08-12

### Added

- `Contracts\SupportsComments`, write-only: `replyToComment()` returns a `PublishResult` (a reply creates
  a public object), `hideComment()` and `deleteComment()` return bool. There is no read method: Instagram's
  comment read API answers an empty list for this token type, so reading is the consumer's webhook.
  `InstagramDriver` implements it.

## 0.5.0 — 2026-08-08

### Added

- `Contracts\SupportsCoverUpdate`: replace the cover of a published object. `YouTubeDriver` implements it
  over `thumbnails.set`; Instagram cannot (a Reel's cover is fixed at creation).

## 0.4.0 — 2026-08-08

### Added

- `Capabilities::$thumbnailAspect`: the shape of a fixed thumbnail slot (`16 / 9` on YouTube, null
  elsewhere). YouTube pads a wrong-shaped thumbnail silently.

## 0.3.0 — 2026-08-08

### Changed

- A YouTube channel is an account: its grant moved from `networks.youtube.refresh_token` to
  `accounts.youtube.<key>.refresh_token`. **Upgrading:** move the token; the old key is not read.
- `Account` gained `refreshToken`, separate from `token` so rotation cannot overwrite the grant.
- `YouTubeDriver::hasAccount()` asks for a grant, not a channel id; `health()` reports a `channels` map
  and is no longer green with no channel granted.

### Added

- Telegram sends return a public permalink (`t.me/<username>/<topic>/<id>`), null for chats without a
  username.

### Fixed

- YouTube scopes include `youtube.force-ssl`, which deletion needs; existing grants must be re-consented.
- `videos.delete` sends `id` in the query string.
- `thumbnails.set` derives its `Content-Type` from the file.

## 0.2.1 — 2026-08-08

### Fixed

- Comments no longer state Instagram's publishing quota as a number; the driver reads the live quota.

## 0.2.0 — 2026-08-08

### Changed

- Drivers refuse media whose mime type their `Capabilities` do not list, before sending. Media with no
  stated type passes through.

### Removed

- `Capabilities::textLimit()` and `Capabilities::maxBytesFor()`; read the fields directly.

### Added

- A standalone test suite (`orchestra/testbench`, Pest, `composer test`).

## 0.1.1 — 2026-08-08

### Fixed

- `Credentials::daysRemaining()` returned the wrong sign.

## 0.1.0 — 2026-08-08

First release: `SocialManager` with `extend()`, the three-outcome `PublishResult`, `Contracts\Driver` with
optional `SupportsDeletion`, `SupportsTopics`, `ProvidesAnalytics` and `RefreshesTokens`, `Capabilities`
and `RateProfile`, Instagram, YouTube and Telegram drivers, and `Testing\FakeDriver`.
