<?php

namespace Hojabbr\Social\Drivers;

use Hojabbr\Social\Contracts\Driver;
use Hojabbr\Social\Enums\Placement;
use Hojabbr\Social\Exceptions\MissingCredentials;
use Hojabbr\Social\Values\Account;
use Hojabbr\Social\Values\Destination;

/**
 * The config reading and account resolution every driver needs, once.
 *
 * A driver is constructed with its own slice of config rather than reaching for
 * `config()` itself: that is what lets the manager build two networks off one
 * driver class, and what lets a test build a driver with a literal array instead
 * of arranging the container.
 */
abstract class BaseDriver implements Driver
{
    /**
     * @param  array<string, mixed>  $config  This network's `social.networks.<name>` entry.
     * @param  array<string, array<string, mixed>>  $accounts  Its `social.accounts.<name>` entry.
     * @param  string  $network  The config key, which may differ from the driver name.
     */
    public function __construct(
        protected array $config,
        protected array $accounts,
        protected string $network,
    ) {}

    public function network(): string
    {
        return $this->network;
    }

    public function queue(): string
    {
        return $this->text('queue') ?: 'default';
    }

    /**
     * @return list<string>
     */
    public function accountKeys(): array
    {
        return array_map(strval(...), array_keys($this->accounts));
    }

    public function hasAccount(string $key): bool
    {
        return $this->account($key)->isConfigured();
    }

    public function account(string $key): Account
    {
        $account = $this->accounts[$key] ?? null;

        if (! is_array($account)) {
            throw MissingCredentials::account($this->network, $key, $this->accountKeys());
        }

        return new Account(
            network: $this->network,
            key: $key,
            id: trim((string) ($account['id'] ?? '')),
            handle: ($handle = trim((string) ($account['handle'] ?? ''))) === '' ? null : $handle,
            token: ($token = trim((string) ($account['token'] ?? ''))) === '' ? null : $token,
        );
    }

    /**
     * Convenience for the common "post to this account, in this shape" build.
     */
    public function destination(string $key, Placement $placement, int|string|null $topic = null): Destination
    {
        return new Destination($this->account($key), $placement, $topic);
    }

    /**
     * Whether the network's own kill switch is on. Distinct from isUsable(),
     * which also asks whether the credentials are actually there.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    protected function text(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    protected function number(string $key, int $default): int
    {
        $value = $this->config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function flag(string $key, bool $default = false): bool
    {
        return array_key_exists($key, $this->config) ? (bool) $this->config[$key] : $default;
    }
}
