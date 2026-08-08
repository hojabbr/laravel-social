<?php

namespace Hojabbr\Social;

use Closure;
use Hojabbr\Social\Contracts\Driver;
use Hojabbr\Social\Drivers\Instagram\InstagramDriver;
use Hojabbr\Social\Drivers\Telegram\TelegramDriver;
use Hojabbr\Social\Drivers\YouTube\YouTubeDriver;
use Hojabbr\Social\Exceptions\UnknownNetwork;
use Illuminate\Contracts\Config\Repository;

/**
 * Resolves a network name to its driver, the way Laravel's own Filesystem and
 * Queue managers resolve a disk or a connection: config describes the networks,
 * the manager builds and memoises one driver each, and `extend()` lets an
 * application register a driver this package has never heard of.
 *
 * A network entry names its `driver`, so 'instagram' and 'instagram_agency' can
 * both be Instagram with different credentials — the network is the CONFIG
 * entry, the driver is the code.
 */
class SocialManager
{
    /** @var array<string, Driver> */
    protected array $resolved = [];

    /** @var array<string, Closure(array<string, mixed>, array<string, array<string, mixed>>, string): Driver> */
    protected array $customCreators = [];

    public function __construct(protected Repository $config) {}

    /**
     * @throws UnknownNetwork
     */
    public function driver(string $network): Driver
    {
        return $this->resolved[$network] ??= $this->resolve($network);
    }

    /**
     * Register a driver factory. The closure receives the network's config, its
     * accounts config, and the network name — everything a driver needs, so a
     * third-party driver has the same information the built-in ones do.
     *
     * @param  Closure(array<string, mixed>, array<string, array<string, mixed>>, string): Driver  $factory
     */
    public function extend(string $driver, Closure $factory): static
    {
        $this->customCreators[$driver] = $factory;

        return $this;
    }

    /**
     * Every configured network key, in config order.
     *
     * @return list<string>
     */
    public function networks(): array
    {
        /** @var array<string, mixed> $networks */
        $networks = (array) $this->config->get('social.networks', []);

        return array_map(strval(...), array_keys($networks));
    }

    /**
     * The networks that are switched on AND hold their credentials — what a
     * dispatch path may fan out to.
     *
     * @return list<string>
     */
    public function usable(): array
    {
        return array_values(array_filter(
            $this->networks(),
            fn (string $network): bool => $this->driver($network)->isUsable(),
        ));
    }

    /**
     * Drop a memoised driver so the next resolve reads config again.
     *
     * This is what a token refresh calls: a driver caches the credentials it was
     * built with, so renewing a token without forgetting the driver leaves the
     * old one in use for the rest of the process.
     */
    public function forget(?string $network = null): static
    {
        if ($network === null) {
            $this->resolved = [];

            return $this;
        }

        unset($this->resolved[$network]);

        return $this;
    }

    /**
     * @throws UnknownNetwork
     */
    protected function resolve(string $network): Driver
    {
        /** @var array<string, mixed>|null $settings */
        $settings = $this->config->get("social.networks.{$network}");

        if (! is_array($settings)) {
            throw UnknownNetwork::named($network, $this->networks());
        }

        /** @var array<string, array<string, mixed>> $accounts */
        $accounts = (array) $this->config->get("social.accounts.{$network}", []);

        $driver = (string) ($settings['driver'] ?? $network);

        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($settings, $accounts, $network);
        }

        return match ($driver) {
            'instagram' => new InstagramDriver($settings, $accounts, $network),
            'youtube' => new YouTubeDriver($settings, $accounts, $network),
            'telegram' => new TelegramDriver($settings, $accounts, $network),
            default => throw UnknownNetwork::named($driver, [...$this->networks(), ...array_keys($this->customCreators)]),
        };
    }
}
