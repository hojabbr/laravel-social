<?php

namespace Hojabbr\Social\Values;

use Hojabbr\Social\Enums\Outcome;

/**
 * The outcome of one publish. Never thrown — a transient network hiccup must not
 * be able to kill a queue worker, and the three states are data the caller acts
 * on, not exceptional control flow.
 *
 * `$externalIds` is plural because one call can create several objects: a
 * Telegram album answers with one message per page, and every id has to be
 * recorded or a retraction strands pages 2..N forever. `$externalId` is the
 * FIRST of them — the one that carries the caption and that a reply or a pin
 * targets.
 */
final readonly class PublishResult
{
    /**
     * @param  list<int|string>  $externalIds
     */
    private function __construct(
        public Outcome $outcome,
        public int|string|null $externalId = null,
        public array $externalIds = [],
        public ?string $url = null,
        public ?string $error = null,
    ) {}

    public static function sent(int|string|null $externalId, ?string $url = null): self
    {
        return new self(
            Outcome::Sent,
            externalId: $externalId,
            externalIds: $externalId === null ? [] : [$externalId],
            url: $url,
        );
    }

    /**
     * Several objects from one call (an album, a carousel's children).
     *
     * @param  list<int|string>  $externalIds
     */
    public static function sentMany(array $externalIds, ?string $url = null): self
    {
        return new self(
            Outcome::Sent,
            externalId: $externalIds[0] ?? null,
            externalIds: $externalIds,
            url: $url,
        );
    }

    /**
     * The network answered and refused. Deterministic: nothing was created.
     */
    public static function rejected(string $error): self
    {
        return new self(Outcome::Rejected, error: $error);
    }

    /**
     * The outcome was never observed. Something may be live. Never auto-retry.
     */
    public static function unknown(string $error): self
    {
        return new self(Outcome::Unknown, error: $error);
    }

    public function isSent(): bool
    {
        return $this->outcome === Outcome::Sent;
    }

    public function isRejected(): bool
    {
        return $this->outcome === Outcome::Rejected;
    }

    public function isUnknown(): bool
    {
        return $this->outcome === Outcome::Unknown;
    }
}
