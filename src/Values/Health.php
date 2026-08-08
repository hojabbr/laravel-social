<?php

namespace Hojabbr\Social\Values;

/**
 * What a driver can say about itself right now: whether it is switched on,
 * whether it has the credentials to work, and whatever live state it could read
 * back from the network.
 *
 * `$details` is free-form because the useful facts differ per network — a bot's
 * admin rights, a channel's title, a token's expiry — and a fixed schema would
 * either be mostly-null or grow a field per network.
 */
final readonly class Health
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $network,
        public bool $enabled,
        public bool $configured,
        public array $details = [],
        public ?string $error = null,
    ) {}

    /**
     * Switched on AND able to work — the gate every dispatch path consults
     * before it creates a share row.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->configured;
    }

    /**
     * @return array{network: string, enabled: bool, configured: bool, error: string|null, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'network' => $this->network,
            'enabled' => $this->enabled,
            'configured' => $this->configured,
            'error' => $this->error,
            'details' => $this->details,
        ];
    }
}
