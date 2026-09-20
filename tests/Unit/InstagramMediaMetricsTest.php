<?php

use Hojabbr\Social\Drivers\Instagram\InstagramDriver;
use Hojabbr\Social\Enums\Placement;
use Illuminate\Support\Facades\Http;

/**
 * Which metrics a post is asked for, which is decided by what it was published
 * as.
 *
 * `/insights` refuses the WHOLE call when one metric does not apply to the
 * media, so a carousel asked for a reel's average watch time answers 400 and
 * returns no reach, no saves and no views either. Asking every post the same
 * question is why, on 2026-09-09, 374 reels carried samples and all 36 stories
 * and 27 carousels carried none: two years of numbers that can never be
 * recovered, because none of them is queryable for a past date.
 */
function metricsDriver(): InstagramDriver
{
    return new InstagramDriver(
        ['enabled' => true, 'api_base' => 'https://graph.instagram.com/v23.0'],
        ['fa' => ['id' => '17841400000000001', 'token' => 'IGAA-token']],
        'instagram',
    );
}

function askedMetrics(?Placement $placement): array
{
    Http::fake(['*/insights*' => Http::response(['data' => []])]);

    $driver = metricsDriver();
    $driver->mediaMetrics($driver->account('fa'), '17900000000000001', $placement);

    $query = [];
    parse_str((string) parse_url(Http::recorded()[0][0]->url(), PHP_URL_QUERY), $query);

    return explode(',', (string) ($query['metric'] ?? ''));
}

it('asks a reel for its watch time', function () {
    expect(askedMetrics(Placement::Reel))->toContain('ig_reels_avg_watch_time')
        ->toContain('saved')
        ->toContain('views');
});

it('never asks a feed post for a reel metric', function () {
    expect(askedMetrics(Placement::Feed))
        ->not->toContain('ig_reels_avg_watch_time')
        ->toContain('saved')
        ->toContain('views');
});

it('asks a story only what a story answers', function () {
    // A story has no likes and no saves, and it answers for about 24 hours.
    expect(askedMetrics(Placement::Story))
        ->toBe(['reach', 'views', 'replies']);
});

it('keeps the reel vocabulary when the caller does not say', function () {
    // Additive by construction: a caller that has not been taught about
    // placements yet reads exactly what it read before.
    expect(askedMetrics(null))->toContain('ig_reels_avg_watch_time');
});
