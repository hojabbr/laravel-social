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

it('preserves a refused insight read instead of returning a successful empty measurement', function () {
    Http::fake(['*/insights*' => Http::response(['error' => [
        'message' => 'Unsupported get request. Object cannot be loaded.',
        'code' => 100,
        'error_subcode' => 33,
    ]], 400)]);

    $driver = metricsDriver();
    $metrics = $driver->mediaMetrics($driver->account('fa'), '17900000000000001', Placement::Reel);

    expect($metrics->values)->toBe([])
        ->and($metrics->error)->toContain('100/33', 'Object cannot be loaded');
});

it('reports transport failures without throwing or inventing a zero', function () {
    Http::fake(['*/insights*' => Http::failedConnection()]);

    $driver = metricsDriver();
    $metrics = $driver->mediaMetrics($driver->account('fa'), '17900000000000001');

    expect($metrics->values)->toBe([])
        ->and($metrics->error)->toContain('did not complete');
});

it('keeps account credentials out of persisted media insight errors', function (bool $transport) {
    Http::fake(['*/insights*' => $transport
        ? Http::failedConnection('Connection failed with Authorization: Bearer IGAA-token')
        : Http::response(['error' => ['code' => 190, 'message' => 'Invalid access token IGAA-token']], 400)]);

    $driver = metricsDriver();
    $metrics = $driver->mediaMetrics($driver->account('fa'), '17900000000000001');

    expect($metrics->values)->toBe([])
        ->and($metrics->error)->not->toContain('IGAA-token');

    if (! $transport) {
        expect($metrics->error)->toContain('190', 'Invalid access token');
    }
})->with(['transport' => true, 'provider refusal' => false]);

it('reports a missing token without making a request', function () {
    Http::preventStrayRequests();
    $driver = new InstagramDriver(
        ['api_base' => 'https://graph.instagram.com/v23.0'],
        ['fa' => ['id' => '17841400000000001']],
        'instagram',
    );

    $metrics = $driver->mediaMetrics($driver->account('fa'), '17900000000000001');

    expect($metrics->values)->toBe([])
        ->and($metrics->error)->toContain('no token');
    Http::assertNothingSent();
});

it('distinguishes malformed, empty and real zero readings', function (mixed $body, ?string $error, array $values) {
    Http::fake(['*/insights*' => Http::response($body)]);

    $driver = metricsDriver();
    $metrics = $driver->mediaMetrics($driver->account('fa'), '17900000000000001');

    expect($metrics->error)->toBe($error)
        ->and($metrics->values)->toBe($values);
})->with([
    'invalid envelope' => [['unexpected' => true], 'Instagram returned no media insights data.', []],
    'empty data' => [['data' => []], null, []],
    'zero views' => [['data' => [['name' => 'views', 'values' => [['value' => 0]]]]], null, ['views' => 0]],
]);
