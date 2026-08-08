<?php

namespace Hojabbr\Social\Contracts;

use Hojabbr\Social\Enums\Placement;
use Hojabbr\Social\Exceptions\MissingCredentials;
use Hojabbr\Social\Values\Account;
use Hojabbr\Social\Values\Capabilities;
use Hojabbr\Social\Values\Destination;
use Hojabbr\Social\Values\Health;
use Hojabbr\Social\Values\PublishRequest;
use Hojabbr\Social\Values\PublishResult;
use Hojabbr\Social\Values\RateProfile;

/**
 * The one contract every network implements. Everything past this — deleting,
 * topic management, analytics, token refresh — is an optional contract a driver
 * adds only when the network genuinely has it, so a caller checks with
 * `instanceof` and cannot ask a network to do something it cannot.
 *
 * `publish()` never throws. A network refusal and a dropped connection are
 * different OUTCOMES, not different exceptions, because the caller has to record
 * them differently against a durable claim — see PublishResult.
 */
interface Driver
{
    /**
     * The network key this driver serves ('instagram'), matching its config entry.
     */
    public function network(): string;

    /**
     * A short human label for an admin surface.
     */
    public function label(): string;

    public function capabilities(): Capabilities;

    public function rateProfile(): RateProfile;

    /**
     * The queue lane work for this network belongs on. Declared per network
     * because the constraint is per network: Telegram's ceiling is per chat, so
     * its lane runs one process as an exact pacer, while an upload-bound network
     * wants whatever concurrency the box will give it.
     */
    public function queue(): string;

    /**
     * Switched on in config AND holding the credentials it needs. A driver that
     * is not usable must never be handed a publish.
     */
    public function isUsable(): bool;

    /**
     * @return list<string> Configured account keys, in config order.
     */
    public function accountKeys(): array;

    public function hasAccount(string $key): bool;

    /**
     * @throws MissingCredentials When no such account is configured.
     */
    public function account(string $key): Account;

    /**
     * The common "post to this account, in this shape" build. On the contract
     * rather than only on BaseDriver because it is how a caller holding a Driver
     * from the manager composes a PublishRequest at all.
     *
     * @throws MissingCredentials When no such account is configured.
     */
    public function destination(string $key, Placement $placement, int|string|null $topic = null): Destination;

    /**
     * Live state read back from the network, for an admin health surface. Must
     * not throw: a diagnostic page that 500s tells nobody anything.
     */
    public function health(): Health;

    public function publish(PublishRequest $request): PublishResult;
}
