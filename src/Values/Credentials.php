<?php

namespace Hojabbr\Social\Values;

use Illuminate\Support\Carbon;

/**
 * Fresh credential material handed back by a token refresh, for the consumer to
 * persist wherever it keeps secrets.
 *
 * The package deliberately does NOT store this: it owns no table and no settings
 * store, and a token written into two places (a config cache and a database row)
 * eventually disagrees about which one is live.
 */
final readonly class Credentials
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?Carbon $expiresAt = null,
    ) {}

    /**
     * Whether the token is past — or within the given grace of — its expiry.
     * A token with no known expiry is never considered expired: Telegram's bot
     * token and a Google refresh token are both open-ended.
     */
    public function isExpired(int $graceSeconds = 0): bool
    {
        return $this->expiresAt !== null
            && $this->expiresAt->lessThanOrEqualTo(now()->addSeconds($graceSeconds));
    }

    /**
     * Whole days left before expiry, negative once it has passed. Floored, not
     * rounded: a token with 14 hours left has 0 days, and reporting 1 would read
     * as "there is still a day" to whoever is deciding whether to refresh.
     */
    public function daysRemaining(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return (int) floor($this->expiresAt->diffInDays(now(), absolute: false));
    }
}
