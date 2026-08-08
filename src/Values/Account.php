<?php

namespace Hojabbr\Social\Values;

/**
 * One place to post: an Instagram professional account, a YouTube channel, a
 * Telegram chat.
 *
 * `$key` is the CONSUMER's name for it, not the network's — Sahmino keys
 * Instagram by locale ('fa', 'en') because a share is routed by the language of
 * the video it distributes. A network with one identity uses 'default'.
 *
 * `$token` is per-ACCOUNT credential material (an Instagram long-lived user
 * token). Network-level credentials — a Meta app secret, a Google client secret,
 * a Telegram bot token — belong to the driver, not here: they are shared by
 * every account on that network, and duplicating them per account is how two
 * copies of one secret start to disagree.
 *
 * `$refreshToken` is a SECOND credential slot rather than a use of `$token`,
 * and the separation is load-bearing. An OAuth grant has two lifetimes: a
 * long-lived refresh token that is the account's identity, and a short-lived
 * access token derived from it. A consumer's rotation step writes the ACCESS
 * token back to wherever it read a token from, so a network holding its grant in
 * `$token` would have that grant overwritten by an hour-long string the first
 * night the rotation ran — a total, silent loss of the connection. Two slots
 * make that inexpressible.
 */
final readonly class Account
{
    public function __construct(
        public string $network,
        public string $key,
        public string $id,
        public ?string $handle = null,
        public ?string $token = null,
        public ?string $refreshToken = null,
    ) {}

    /**
     * Whether this account has everything it needs to be posted to. An account
     * present in config but blank is "not configured yet", not an error — the
     * caller reports it and moves on.
     */
    public function isConfigured(): bool
    {
        return trim($this->id) !== '';
    }
}
