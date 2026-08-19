<?php

use Hojabbr\Social\Drivers\Instagram\InstagramDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Renewing the sixty-day token, and the one wire detail that decides whether it
 * works at all.
 *
 * `refresh_access_token` is an OAuth operation wearing a Graph URL: the token is
 * the SUBJECT of the call, not the authentication for it, so Meta reads it only
 * from the query string and answers a Bearer header with `400 IGApiException
 * code 100 — The parameter access_token is required`.
 *
 * Nothing above the driver can tell that refusal from the one a genuinely dead
 * token earns. Both arrive as "the network refused the renewal", which reads as
 * an account needing a human to re-authorise — and because an Instagram
 * long-lived token is renewable ONLY while it is still valid, believing that
 * report is how a live account walks into a hard stop at sixty days. Hence a
 * test that asserts the SHAPE of the request rather than only the outcome: the
 * outcome was already testable and would have passed on a fake that answers
 * anything.
 */
function refreshDriver(): InstagramDriver
{
    return new InstagramDriver(
        ['enabled' => true, 'api_base' => 'https://graph.instagram.com/v23.0'],
        ['fa' => ['id' => '17841400000000001', 'token' => 'IGAA-old']],
        'instagram',
    );
}

it('carries the token in the query string, never as a bearer header', function () {
    Http::fake(['*/refresh_access_token*' => Http::response([
        'access_token' => 'IGAA-new',
        'token_type' => 'bearer',
        'expires_in' => 5_184_000,
    ])]);

    $credentials = refreshDriver()->refresh(refreshDriver()->account('fa'));

    expect($credentials?->accessToken)->toBe('IGAA-new');

    Http::assertSent(function ($request): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['access_token'] === 'IGAA-old'
            && $query['grant_type'] === 'ig_refresh_token'
            && $request->header('Authorization') === [];
    });
});

it('takes the expiry the network reports rather than assuming the full sixty days', function () {
    Http::fake(['*/refresh_access_token*' => Http::response([
        'access_token' => 'IGAA-new',
        'expires_in' => 86_400,
    ])]);

    $credentials = refreshDriver()->refresh(refreshDriver()->account('fa'));

    // A day, because that is what it said. Assuming sixty would hide a token the
    // network has already decided to cut short.
    expect($credentials?->expiresAt?->diffInHours(now(), absolute: true))->toEqualWithDelta(24, 1);
});

it('falls back to the documented lifetime when the network reports no expiry', function () {
    Http::fake(['*/refresh_access_token*' => Http::response(['access_token' => 'IGAA-new'])]);

    expect(refreshDriver()->refresh(refreshDriver()->account('fa'))?->expiresAt?->diffInDays(now(), absolute: true))
        ->toEqualWithDelta(60, 1);
});

it('hands back nothing when the network refuses, leaving the current token in place', function () {
    Http::fake(['*/refresh_access_token*' => Http::response([
        'error' => ['message' => 'The parameter access_token is required.', 'type' => 'IGApiException', 'code' => 100],
    ], 400)]);

    expect(refreshDriver()->refresh(refreshDriver()->account('fa')))->toBeNull();
});

it('hands back nothing when the call never reached the network', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(refreshDriver()->refresh(refreshDriver()->account('fa')))->toBeNull();
});

it('has nothing to renew for an account with no token', function () {
    $driver = new InstagramDriver(
        ['enabled' => true],
        ['fa' => ['id' => '17841400000000001']],
        'instagram',
    );

    expect($driver->refresh($driver->account('fa')))->toBeNull();
});
