<?php

use Hojabbr\Social\Values\Metrics;

test('metrics survive a round trip through an array', function (): void {
    // The round trip is the point: a consumer whose cache store serialises must
    // cache the ARRAY, because a cached object comes back as an incomplete class
    // on the hit and 500s at the first typed read.
    $metrics = new Metrics('instagram', ['reach' => 1204, 'saved' => 31, 'ig_reels_avg_watch_time' => 8.4], label: 'sahmincom');

    $restored = Metrics::fromArray($metrics->toArray());

    expect($restored->network)->toBe('instagram')
        ->and($restored->label)->toBe('sahmincom')
        ->and($restored->get('reach'))->toBe(1204)
        ->and($restored->get('ig_reels_avg_watch_time'))->toBe(8.4)
        ->and($restored->error)->toBeNull();
});

test('an unavailable read carries its reason and no numbers', function (): void {
    $metrics = Metrics::unavailable('youtube', 'No access token.');

    expect($metrics->isEmpty())->toBeTrue()
        ->and($metrics->error)->toBe('No access token.')
        ->and($metrics->get('views'))->toBeNull();
});
