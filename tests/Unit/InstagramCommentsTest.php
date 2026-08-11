<?php

use Hojabbr\Social\Contracts\SupportsComments;
use Hojabbr\Social\Contracts\SupportsDeletion;
use Hojabbr\Social\Drivers\Instagram\InstagramClient;
use Hojabbr\Social\Drivers\Instagram\InstagramDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Writing to a comment thread, and the three outcomes that follow from WHERE a
 * public object starts to exist.
 *
 * There is no read test in this file and there never will be: `GET
 * /{media-id}/comments` answers 200 with an empty list on this token type, so the
 * contract deliberately has no read method — see SupportsComments.
 */
function commentDriver(): InstagramDriver
{
    return new InstagramDriver(
        ['enabled' => true, 'api_base' => 'https://graph.instagram.com/v23.0'],
        ['fa' => ['id' => '17841400000000001', 'token' => 'IGAA-token']],
        'instagram',
    );
}

it('declares comment support and still refuses to claim post deletion', function () {
    // Two capabilities, two interfaces. A comment CAN be deleted on an
    // Instagram-Login token; the post it sits under cannot.
    expect(commentDriver())->toBeInstanceOf(SupportsComments::class)
        ->not->toBeInstanceOf(SupportsDeletion::class);
});

it('sends a reply and reports the id of what it created', function () {
    Http::fake(['*/17800000000000001/replies' => Http::response(['id' => '17800000000000009'])]);

    $result = commentDriver()->replyToComment(commentDriver()->account('fa'), '17800000000000001', 'سلام');

    expect($result->isSent())->toBeTrue()
        ->and($result->externalId)->toBe('17800000000000009')
        // No url: a comment has no permalink endpoint on this host, and a made-up
        // one in `output.url` resolves to something else.
        ->and($result->url)->toBeNull();
});

it('rejects a reply Instagram refused, because nothing was created', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400)]);

    $result = commentDriver()->replyToComment(commentDriver()->account('fa'), '17800000000000001', 'سلام');

    expect($result->isRejected())->toBeTrue()
        ->and($result->error)->toContain('code 100')
        ->and($result->error)->not->toContain(InstagramDriver::COMMENT_BLOCKED);
});

it('marks an action block as a rejection carrying the published marker', function () {
    // 368 is Meta's undocumented anti-spam judgement. It is a REJECTION — nothing
    // was created, so the caller may release its claim — but it is account-level
    // and lasting, so the caller has to be able to recognise it without grepping
    // for a phrase.
    Http::fake(['*' => Http::response([
        'error' => ['message' => 'Action Blocked', 'code' => 368, 'type' => 'OAuthException'],
    ], 400)]);

    $result = commentDriver()->replyToComment(commentDriver()->account('fa'), '17800000000000001', 'سلام');

    expect($result->isRejected())->toBeTrue()
        ->and($result->error)->toStartWith(InstagramDriver::COMMENT_BLOCKED);
});

it('recognises the OAuthException code 9 shape of the same block', function () {
    $response = Http::response(['error' => ['message' => 'Application request limit', 'code' => 9, 'type' => 'OAuthException']], 400);
    Http::fake(['*' => $response]);

    $result = commentDriver()->replyToComment(commentDriver()->account('fa'), '1', 'سلام');

    expect($result->error)->toStartWith(InstagramDriver::COMMENT_BLOCKED);
});

it('does not read an ordinary code 9 without the OAuthException type as a block', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Unknown', 'code' => 9]], 400)]);

    $result = commentDriver()->replyToComment(commentDriver()->account('fa'), '1', 'سلام');

    expect($result->error)->not->toStartWith(InstagramDriver::COMMENT_BLOCKED);
});

it('reports Unknown when the connection drops around the write', function () {
    // The reply may be live. Never a blind retry — the same reasoning as step 3
    // of the publish ladder.
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $result = commentDriver()->replyToComment(commentDriver()->account('fa'), '17800000000000001', 'سلام');

    expect($result->isUnknown())->toBeTrue()
        ->and($result->error)->toContain('did not complete');
});

it('reports Unknown when Instagram accepts the reply but names nothing', function () {
    // Accepted, but we cannot name what exists — so it can never be deleted or
    // matched against the webhook echo. Sent would claim an id we do not hold.
    Http::fake(['*' => Http::response(['ok' => true])]);

    $result = commentDriver()->replyToComment(commentDriver()->account('fa'), '17800000000000001', 'سلام');

    expect($result->isUnknown())->toBeTrue()
        ->and($result->externalId)->toBeNull();
});

it('rejects a reply on an account with no token, without calling out', function () {
    Http::fake();

    $driver = new InstagramDriver(['enabled' => true], ['fa' => ['id' => '1', 'token' => '']], 'instagram');
    $result = $driver->replyToComment($driver->account('fa'), '1', 'سلام');

    expect($result->isRejected())->toBeTrue();
    Http::assertNothingSent();
});

it('treats a 200 without success:true as a failed hide', function () {
    // Graph answers this endpoint with {"success":true}. A caller that trusted
    // the status alone would report a hide that never happened.
    Http::fake(['*' => Http::response(['id' => '17800000000000001'])]);

    expect(commentDriver()->hideComment(commentDriver()->account('fa'), '17800000000000001', true))->toBeFalse();
});

it('hides, unhides and deletes when Instagram confirms it', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    $driver = commentDriver();
    $account = $driver->account('fa');

    expect($driver->hideComment($account, '1', true))->toBeTrue()
        ->and($driver->hideComment($account, '1', false))->toBeTrue()
        ->and($driver->deleteComment($account, '1'))->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains((string) $request->body(), 'hide=true'));

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains((string) $request->body(), 'hide=false'));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE');
});

it('reports a hide or delete as failed when the host is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $driver = commentDriver();

    expect($driver->hideComment($driver->account('fa'), '1', true))->toBeFalse()
        ->and($driver->deleteComment($driver->account('fa'), '1'))->toBeFalse();
});

it('paces comments separately from publishing', function () {
    // Instagram governs the two differently: publishing is a daily quota with no
    // per-request pacing, commenting is policed by a burst heuristic. Folding one
    // into the other would silently slow the Reel publisher.
    expect(commentDriver()->rateProfile()->spacingMs)->toBe(0)
        ->and(commentDriver()->commentRateProfile()->spacingMs)->toBeGreaterThan(0);
});

it('reads named media fields', function () {
    Http::fake(['*' => Http::response(['id' => '5', 'caption' => 'متن', 'permalink' => 'https://instagram.com/reel/x/'])]);

    expect(commentDriver()->media(commentDriver()->account('fa'), '5', ['caption', 'permalink']))
        ->toMatchArray(['caption' => 'متن']);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'fields=caption%2Cpermalink'));
});

it('answers an empty array when the media read fails', function () {
    // A failed read is [] rather than an exception: nothing here is worth failing
    // a publish, a cache fill or a comment claim over.
    Http::fake(['*' => Http::response([], 400)]);

    expect(commentDriver()->media(commentDriver()->account('fa'), '5', ['caption']))->toBe([]);
});

it('answers isSpamBlock false for a response with no error object', function () {
    expect(InstagramClient::isSpamBlock(new Illuminate\Http\Client\Response(
        new GuzzleHttp\Psr7\Response(200, [], '{"success":true}'),
    )))->toBeFalse();
});
