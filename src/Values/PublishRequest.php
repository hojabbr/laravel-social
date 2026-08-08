<?php

namespace Hojabbr\Social\Values;

use Hojabbr\Social\Enums\Placement;

/**
 * Everything a driver needs to publish once, and nothing about the caller's
 * domain. This is the whole contract between an application and this package: a
 * request in, a PublishResult out.
 *
 * `$body` is the one text field, because every network has exactly one: a
 * Telegram message, an Instagram caption, a YouTube description. `$title` is
 * separate because only some networks have a second, shorter one.
 *
 * The three booleans are Telegram's and are ignored elsewhere. They are named
 * for the reader's experience rather than for the Bot API parameter they set
 * (`notify`, not `disable_notification`) so a second network that grows the same
 * idea does not inherit an inverted flag.
 */
final readonly class PublishRequest
{
    /**
     * @param  list<string>  $tags  Hashtags/keywords WITHOUT a leading '#'.
     * @param  list<Media>  $media  In reading order; more than one is an album/carousel.
     */
    public function __construct(
        public Destination $destination,
        public string $body = '',
        public ?string $title = null,
        public array $tags = [],
        public array $media = [],
        public bool $notify = false,
        public bool $bodyAbove = false,
        public bool $preview = false,
    ) {}

    public function placement(): Placement
    {
        return $this->destination->placement;
    }

    public function account(): Account
    {
        return $this->destination->account;
    }

    public function hasMedia(): bool
    {
        return $this->media !== [];
    }

    /**
     * The first media, which is the one a single-item send publishes and the one
     * an album hangs its caption on.
     */
    public function firstMedia(): ?Media
    {
        return $this->media[0] ?? null;
    }

    /**
     * @return list<Media>
     */
    public function videos(): array
    {
        return array_values(array_filter($this->media, static fn (Media $media): bool => $media->isVideo()));
    }
}
