<?php

namespace Hojabbr\Social\Values;

use Hojabbr\Social\Enums\MediaKind;

/**
 * One file to publish, addressed the way its network needs it.
 *
 * Both `$path` and `$url` exist because the two transports are genuinely
 * different, not as a convenience: Telegram and YouTube take BYTES (a multipart
 * part, a resumable PUT), while Instagram takes a public URL and fetches the
 * file itself. `Capabilities::$pullsMedia` is how a caller knows which one it
 * has to provide before it builds the request.
 */
final readonly class Media
{
    public function __construct(
        public MediaKind $kind,
        public ?string $path = null,
        public ?string $url = null,
        public ?string $mimeType = null,
        public ?string $thumbnailPath = null,
        public ?string $thumbnailUrl = null,
    ) {}

    public static function image(?string $path = null, ?string $url = null, ?string $mimeType = null): self
    {
        return new self(MediaKind::Image, path: $path, url: $url, mimeType: $mimeType);
    }

    public static function video(
        ?string $path = null,
        ?string $url = null,
        ?string $mimeType = null,
        ?string $thumbnailPath = null,
        ?string $thumbnailUrl = null,
    ): self {
        return new self(
            MediaKind::Video,
            path: $path,
            url: $url,
            mimeType: $mimeType,
            thumbnailPath: $thumbnailPath,
            thumbnailUrl: $thumbnailUrl,
        );
    }

    public function isVideo(): bool
    {
        return $this->kind === MediaKind::Video;
    }

    /**
     * Bytes on disk, or null when this media is URL-addressed only.
     */
    public function bytes(): ?int
    {
        if ($this->path === null || ! is_file($this->path)) {
            return null;
        }

        $size = filesize($this->path);

        return $size === false ? null : $size;
    }
}
