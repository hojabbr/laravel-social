<?php

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

test('the two text ceilings are separate numbers, and so are the two byte ceilings', function (): void {
    // A caller attaching media reads captionLimit and one posting text reads
    // bodyLimit; a single "maxBytes" would make a video → photo → text fallback
    // ladder wrong at one end. Four declared numbers, read by the caller that
    // knows which shape it is building.
    expect(capabilities()->bodyLimit)->toBe(4096)
        ->and(capabilities()->captionLimit)->toBe(1024)
        ->and(capabilities()->maxVideoBytes)->toBe(45 * 1024 * 1024)
        ->and(capabilities()->maxImageBytes)->toBe(10 * 1024 * 1024);
});
