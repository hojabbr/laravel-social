<?php

use Hojabbr\Social\Drivers\Instagram\InstagramDriver;
use Hojabbr\Social\Enums\Placement;
use Hojabbr\Social\Values\Media;
use Hojabbr\Social\Values\PublishRequest;
use Illuminate\Support\Facades\Http;

/**
 * The declared mime list, enforced.
 *
 * A capability nothing consults is a capability that isn't real: `mimeTypes` used
 * to be published and never checked, so a PNG reached Instagram — which takes
 * JPEG only — and came back as a container error long after the upload, naming a
 * media type the caller never chose. The refusal now happens before any request.
 */
function instagram(): InstagramDriver
{
    return new InstagramDriver(
        [
            'enabled' => true,
            'app_id' => 'app',
            'app_secret' => 'secret',
            // No waiting: one of these tests deliberately lets a publish reach the
            // API, and the container poll would otherwise spend the production
            // budget doing nothing.
            'poll_interval_seconds' => 0,
            'poll_budget_seconds' => 0,
        ],
        ['fa' => ['id' => '17841400000000001', 'token' => 'IGAA-token']],
        'instagram',
    );
}

function reelRequest(Media $media): PublishRequest
{
    return new PublishRequest(
        destination: instagram()->destination('fa', Placement::Reel),
        body: 'caption',
        media: [$media],
    );
}

test('a media type the network does not take is refused before anything is sent', function (): void {
    Http::preventStrayRequests();

    $result = instagram()->publish(reelRequest(Media::video(url: 'https://example.test/a.webm', mimeType: 'video/webm')));

    expect($result->isRejected())->toBeTrue()
        ->and($result->error)->toContain('video/webm')
        // The accepted list is named in the refusal: a caller has to be able to
        // fix the file, not just learn that it was wrong.
        ->and($result->error)->toContain('video/mp4');

    Http::assertNothingSent();
});

test('media whose type is unstated is left for the network to judge', function (): void {
    // "I do not know what this file is" is the caller admitting it, and the
    // network is then the better judge — so this must reach the API, not a local
    // refusal that would be a guess.
    Http::fake(['graph.instagram.com/*' => Http::response(['id' => 'container-1'])]);

    $result = instagram()->publish(reelRequest(Media::video(url: 'https://example.test/a.mp4')));

    expect($result->error)->not->toContain('does not accept');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'graph.instagram.com'));
});
