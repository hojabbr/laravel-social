# Changelog

All notable changes to `hojabbr/laravel-social` are documented here.

This project follows [Semantic Versioning](https://semver.org). While the version
is below `1.0.0`, a breaking change lands in a new MINOR (`0.2.0`), not a patch —
so pin with `^0.1.0` and read this file before moving between minors.

## 0.2.1 — 2026-08-08

### Fixed

- Two comments in `InstagramDriver` stated the publishing quota as
  25 posts per 24 hours, which is Meta's documented figure. Measured against two
  live Business accounts, `content_publishing_limit` answers `quota: 100`. The code
  always read the real number, so nothing behaved differently — but a comment that
  states a limit is read as the limit, and a caller sizing a backfill against 25
  would have left three quarters of its allowance unused. The comments now name the
  endpoint instead of a number.

## 0.2.0 — 2026-08-08

A MINOR rather than a patch because two public methods are gone. Nothing in the
package called either of them, so an upgrade is a no-op unless your own code did.

### Changed

- Every driver now REFUSES media whose mime type its `Capabilities` do not list,
  before it sends anything. `mimeTypes` was published and never consulted, which
  made it a declaration rather than a rule: a PNG reached Instagram — which takes
  JPEG only — and came back as a container error minutes later, after the upload,
  naming a media type the caller never chose. Media with NO stated mime type is
  still passed through, because "I do not know what this file is" is the caller
  admitting it and the network is then the better judge.

### Removed

- `Capabilities::textLimit(bool $withMedia)` and
  `Capabilities::maxBytesFor(MediaKind $kind)`. Both were conveniences over fields
  that callers already read directly (`bodyLimit`/`captionLimit`,
  `maxVideoBytes`/`maxImageBytes`), and a caller building a request always knows
  statically which shape it is building — so at every real call site the field
  read is the clearer one. The four fields are unchanged.

### Added

- The test suite runs on its own: `orchestra/testbench` + Pest, `composer test`.
  Previously the tests could only run inside a consuming application, which is a
  suite nobody runs.

## 0.1.1 — 2026-08-08

### Fixed

- `Credentials::daysRemaining()` reported the sign backwards — a long-lived token
  with sixty days left came back as `-60`, which reads to every caller as
  "expired two months ago". A status page showed a healthy Instagram token as
  long overdue, and any caller that renewed on `daysRemaining() < N` would have
  rotated on every run.

## 0.1.0 — 2026-08-08

First release.

### Added

- `SocialManager` — resolves a network name to its driver, memoises one driver per
  network, and `extend()` registers a driver the package has never heard of. A
  network entry names its `driver`, so two networks can share one driver class
  with different credentials.
- The three-state publish outcome (`Outcome::Sent | Rejected | Unknown`).
  `publish()` never throws: a network refusal and a dropped connection are
  different outcomes because a caller holding a durable claim has to record them
  differently. Rejected releases the claim, Unknown keeps it.
- `Contracts\Driver` — the one contract every network implements, plus four
  optional ones a driver adds only when its network genuinely has the feature:
  `SupportsDeletion`, `SupportsTopics`, `ProvidesAnalytics`, `RefreshesTokens`.
- `Capabilities` and `RateProfile`, so a caller never branches on a network's
  name: text ceilings, tag caps, album size, accepted mime types, per-file byte
  ceilings, whether the network pulls media from a URL, and the pacing.
- **Instagram** driver — Content Publishing API on Instagram Login: container →
  poll → publish → permalink. Reels, Stories, feed posts and carousels. Media
  insights, account insights, and the publishing-quota read-out. Long-lived token
  refresh. Deliberately does not implement `SupportsDeletion`, because a post made
  on this token type cannot be deleted through the API.
- **YouTube** driver — no SDK: OAuth refresh, resumable upload (the session URI
  arrives in a `Location` response header), thumbnail set, deletion, statistics
  and YouTube Analytics retention.
- **Telegram** driver — messages, photos, albums with a per-page id each, videos
  with real geometry and a spec-compliant thumbnail, forum-topic management,
  pinning, member lockdown, reactions and a bot-health read-out. Flood-wait
  handling honours `retry_after`.
- `Testing\FakeDriver` — replaces a network in the container, records the requests
  it was asked to publish, and returns whatever outcome a test scripts. For
  testing the CALLER: that a rejection releases a claim and an unknown keeps it.
