<?php

namespace Hojabbr\Social\Values;

use Hojabbr\Social\Enums\Placement;

/**
 * What a network will actually accept, declared by its driver so the caller
 * never branches on a network's name. "Instagram wants JPEG", "a caption fits in
 * 2200", "at most five hashtags" each live in exactly one place.
 *
 * Deliberately absent: booleans for deletion, topics and analytics. Those are
 * the optional CONTRACTS (SupportsDeletion, SupportsTopics, ProvidesAnalytics) —
 * a flag saying "I can delete" next to an interface saying the same thing is two
 * sources for one fact, and the flag is the one that can lie.
 */
final readonly class Capabilities
{
    /**
     * @param  list<Placement>  $placements  What this network can publish.
     * @param  int  $bodyLimit  Text ceiling with no media attached.
     * @param  int  $captionLimit  Text ceiling when media IS attached (often lower).
     * @param  int  $tagLimit  How many hashtags/keywords survive.
     * @param  int  $maxItemsPerMessage  Album/carousel ceiling; 1 means single-item only.
     * @param  list<string>  $mimeTypes  Accepted media mime types.
     * @param  int  $maxVideoBytes  Per-file ceiling for a video.
     * @param  int  $maxImageBytes  Per-file ceiling for an image — a separate
     *                              number because the two rarely match, and a
     *                              single "maxBytes" makes a caller's fallback
     *                              ladder (video → photo → text) wrong at one end.
     *                              Both are read by the caller building the
     *                              request: it is the one that knows what it can
     *                              fall back to when a file is too big.
     * @param  bool  $pullsMedia  The network FETCHES the file from a public URL
     *                            instead of taking bytes, so the caller has to
     *                            expose one before it builds the request.
     */
    public function __construct(
        public array $placements,
        public int $bodyLimit,
        public int $captionLimit,
        public int $tagLimit,
        public int $maxItemsPerMessage,
        public array $mimeTypes,
        public int $maxVideoBytes,
        public int $maxImageBytes,
        public bool $pullsMedia = false,
    ) {}

    public function supports(Placement $placement): bool
    {
        return in_array($placement, $this->placements, true);
    }

    public function accepts(?string $mimeType): bool
    {
        return $mimeType !== null && in_array(strtolower($mimeType), $this->mimeTypes, true);
    }
}
