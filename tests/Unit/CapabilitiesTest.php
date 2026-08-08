<?php

use Hojabbr\Social\Enums\MediaKind;
use Hojabbr\Social\Enums\Placement;
use Hojabbr\Social\Values\Capabilities;

function capabilities(): Capabilities
{
    return new Capabilities(
        placements: [Placement::Reel, Placement::Feed],
        bodyLimit: 4096,
        captionLimit: 1024,
        tagLimit: 5,
        maxItemsPerMessage: 10,
        mimeTypes: ['image/jpeg', 'video/mp4'],
        maxVideoBytes: 45 * 1024 * 1024,
        maxImageBytes: 10 * 1024 * 1024,
    );
}

test('a placement the network cannot publish is refused', function (): void {
    expect(capabilities()->supports(Placement::Reel))->toBeTrue()
        ->and(capabilities()->supports(Placement::Story))->toBeFalse();
});

test('mime types match case-insensitively and an unknown type is never accepted', function (): void {
    expect(capabilities()->accepts('IMAGE/JPEG'))->toBeTrue()
        ->and(capabilities()->accepts('image/png'))->toBeFalse()
        ->and(capabilities()->accepts(null))->toBeFalse();
});

test('the text ceiling depends on whether media is attached', function (): void {
    expect(capabilities()->textLimit(withMedia: false))->toBe(4096)
        ->and(capabilities()->textLimit(withMedia: true))->toBe(1024);
});

test('video and image ceilings are separate numbers', function (): void {
    // A single "maxBytes" makes a caller's video → photo → text fallback ladder
    // wrong at one end, which is why these are two fields.
    expect(capabilities()->maxBytesFor(MediaKind::Video))->toBe(45 * 1024 * 1024)
        ->and(capabilities()->maxBytesFor(MediaKind::Image))->toBe(10 * 1024 * 1024);
});
