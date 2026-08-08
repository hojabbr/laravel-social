<?php

namespace Hojabbr\Social\Values;

/**
 * How fast a network may be posted to. Declared by the driver so a caller pacing
 * a backfill reads the number instead of hardcoding it.
 *
 * The spacing is per ACCOUNT, not per destination-inside-an-account: Telegram's
 * ~20 messages/minute ceiling is per chat and a forum supergroup is one chat
 * however many topics it has, so every topic shares the budget. That is why
 * pauseMsFor() takes a MESSAGE COUNT — an album spends one slot per page, and a
 * sender that sleeps once per call walks straight into a 429 on the second album.
 */
final readonly class RateProfile
{
    public function __construct(public int $spacingMs = 0) {}

    public function pauseMsFor(int $messages = 1): int
    {
        return $this->spacingMs * max(1, $messages);
    }
}
