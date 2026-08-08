<?php

use Hojabbr\Social\Drivers\YouTube\YouTubeDriver;
use Hojabbr\Social\Enums\Placement;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A channel is an account, so everything that used to be true of "the" YouTube
 * grant has to be true per account — and the network must stop claiming it is
 * ready when nothing is connected.
 */
function youtube(array $accounts, array $config = []): YouTubeDriver
{
    return new YouTubeDriver([
        'enabled' => true,
        'client_id' => 'client',
        'client_secret' => 'secret',
        ...$config,
    ], $accounts, 'youtube');
}

test('an account holding its own grant is postable, one without is not', function (): void {
    $driver = youtube([
        'fa' => ['id' => 'UC-fa', 'refresh_token' => 'grant-fa'],
        'en' => ['id' => 'UC-en'],
    ]);

    expect($driver->hasAccount('fa'))->toBeTrue()
        ->and($driver->hasAccount('en'))->toBeFalse();
});

test('the channel id is not a credential, so an account with only a grant is still postable', function (): void {
    // `mine=true` reads the channel behind a token, so an id that has not been
    // filled in yet is a blank admin field rather than a broken connection.
    expect(youtube(['fa' => ['refresh_token' => 'grant-fa']])->hasAccount('fa'))->toBeTrue();
});

test('two accounts each carry their own grant', function (): void {
    $driver = youtube([
        'fa' => ['id' => 'UC-fa', 'refresh_token' => 'grant-fa'],
        'agency' => ['id' => 'UC-agency', 'refresh_token' => 'grant-agency'],
    ]);

    expect($driver->account('fa')->refreshToken)->toBe('grant-fa')
        ->and($driver->account('agency')->refreshToken)->toBe('grant-agency')
        ->and($driver->accountKeys())->toBe(['fa', 'agency']);
});

test('a grant does not leak into the token slot', function (): void {
    // The two slots are separate so a rotation writing an access token back can
    // never overwrite the grant that produced it.
    expect(youtube(['fa' => ['refresh_token' => 'grant-fa']])->account('fa')->token)->toBeNull();
});

test('the network is unusable while no channel is connected', function (): void {
    expect(youtube(['fa' => ['id' => 'UC-fa']])->isUsable())->toBeFalse()
        ->and(youtube(['fa' => ['refresh_token' => 'grant-fa']])->isUsable())->toBeTrue();
});

test('health does not report ready when the client is configured but nothing is granted', function (): void {
    $health = youtube(['fa' => ['id' => 'UC-fa']])->health();

    expect($health->configured)->toBeFalse()
        ->and($health->details['clientConfigured'])->toBeTrue()
        ->and($health->details['channels'])->toBe([])
        ->and($health->error)->toContain('no channel has been connected');
});

test('health reports nothing configured when the Google client is missing', function (): void {
    $health = youtube(['fa' => ['refresh_token' => 'grant-fa']], ['client_id' => '', 'client_secret' => ''])->health();

    expect($health->configured)->toBeFalse()
        ->and($health->details['clientConfigured'])->toBeFalse();
});

test('a destination is built for the account that holds the grant', function (): void {
    $destination = youtube(['fa' => ['id' => 'UC-fa', 'refresh_token' => 'grant-fa']])
        ->destination('fa', Placement::Reel);

    expect($destination->account->key)->toBe('fa')
        ->and($destination->placement)->toBe(Placement::Reel);
});

test('the grant scope covers deletion', function (): void {
    // SupportsDeletion is implemented, so the scope videos.delete needs has to be
    // in the grant — otherwise a retraction reports a success it never had.
    expect(YouTubeDriver::SCOPES)->toContain('youtube.force-ssl');
});

test('a deletion puts the video id in the query string, where the API reads it', function (): void {
    // Laravel's `delete($url, $data)` sends $data as the request BODY, and
    // `videos.delete` reads `id` from the query alone — so a deletion built that
    // way answers "Required parameter: id" while looking correct at the call
    // site. The whole retraction path depends on this one detail.
    Http::preventStrayRequests();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.access', 'expires_in' => 3600]),
        'www.googleapis.com/youtube/v3/videos*' => Http::response('', 204),
    ]);

    $driver = youtube(['fa' => ['id' => 'UC-fa', 'refresh_token' => 'grant-fa']]);

    expect($driver->delete($driver->account('fa'), 'vid-123'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), 'youtube/v3/videos?id=vid-123')
        && $request->body() === '');
});

test('a cover update posts the image to thumbnails.set for the given video', function (): void {
    // The repair path for a live post: the same call publish() makes, addressed
    // by account and video id instead of by a PublishRequest. The body is raw
    // binary and the mime type is the only thing naming it.
    $path = tempnam(sys_get_temp_dir(), 'cover').'.png';
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

    Http::preventStrayRequests();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.access', 'expires_in' => 3600]),
        'www.googleapis.com/upload/youtube/v3/thumbnails/set*' => Http::response(['items' => []], 200),
    ]);

    $driver = youtube(['fa' => ['id' => 'UC-fa', 'refresh_token' => 'grant-fa']]);

    expect($driver->updateCover($driver->account('fa'), 'vid-123', $path))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'thumbnails/set?videoId=vid-123')
        && $request->header('Content-Type') === ['image/png']);

    unlink($path);
});

test('a cover update reports false rather than throwing when the file is gone', function (): void {
    // A caller walking several posts has to be able to continue past one that
    // cannot be repaired, so a missing file is an answer and not an exception.
    Http::preventStrayRequests();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.access', 'expires_in' => 3600])]);

    $driver = youtube(['fa' => ['id' => 'UC-fa', 'refresh_token' => 'grant-fa']]);

    expect($driver->updateCover($driver->account('fa'), 'vid-123', '/no/such/cover.png'))->toBeFalse();
});

test('a cover update refuses an account with no grant before it reaches the wire', function (): void {
    Http::preventStrayRequests();

    $driver = youtube(['fa' => ['id' => 'UC-fa']]);

    expect($driver->updateCover($driver->account('fa'), 'vid-123', '/no/such/cover.png'))->toBeFalse();
});
