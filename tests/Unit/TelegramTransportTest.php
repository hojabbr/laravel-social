<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Hojabbr\Social\Drivers\Telegram\TelegramApi;
use Hojabbr\Social\Enums\Outcome;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;

/**
 * A transport whose only answer is the given exception.
 */
function failingTelegram(Throwable $failure): TelegramApi
{
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([$failure, $failure]))]);

    return (new TelegramApi('123:token'))->setApi(new Api('123:token', false, new GuzzleHttpClient($client)));
}

/**
 * @param  array<string, mixed>  $context
 */
function curlFailure(string $message, array $context, bool $connect = true): Throwable
{
    $request = new Request('POST', 'https://api.telegram.org/bot123:token/sendMessage');

    return $connect
        ? new ConnectException($message, $request, null, $context)
        : new RequestException($message, $request, null, null, $context);
}

test('a connection that was never opened is a rejection, so the claim is released', function (): void {
    $failure = curlFailure('cURL error 28: Connection timed out after 5005 milliseconds', ['errno' => 28, 'request_size' => 0]);

    $message = failingTelegram($failure)->send('sendMessage', ['chat_id' => -100, 'text' => 'hi']);
    $album = failingTelegram($failure)->sendAlbum(['chat_id' => -100, 'media' => []]);

    expect($message->outcome)->toBe(Outcome::Rejected)
        ->and($message->error)->toContain('never reached')
        ->and($album->outcome)->toBe(Outcome::Rejected);
});

test('a TLS handshake reset before the request is written is a rejection too', function (): void {
    $failure = curlFailure('cURL error 35: Recv failure: Connection reset by peer', ['errno' => 35, 'request_size' => 0]);

    expect(failingTelegram($failure)->send('sendMessage', ['chat_id' => -100, 'text' => 'hi'])->outcome)
        ->toBe(Outcome::Rejected);
});

test('a failure after request bytes were written stays unknown', function (int $errno, string $message, bool $connect): void {
    $failure = curlFailure($message, ['errno' => $errno, 'request_size' => 412], $connect);

    expect(failingTelegram($failure)->send('sendMessage', ['chat_id' => -100, 'text' => 'hi'])->outcome)
        ->toBe(Outcome::Unknown);
})->with([
    'timed out waiting for the answer' => [28, 'cURL error 28: Operation timed out after 30001 milliseconds', true],
    'nothing came back' => [52, 'cURL error 52: Empty reply from server', true],
    'the connection dropped mid-answer' => [56, 'cURL error 56: Recv failure: Connection reset by peer', false],
]);

test('a failure with no transport context stays unknown', function (): void {
    $failure = curlFailure('cURL error 28: Connection timed out after 5005 milliseconds', ['errno' => 28]);

    expect(failingTelegram($failure)->send('sendMessage', ['chat_id' => -100, 'text' => 'hi'])->outcome)
        ->toBe(Outcome::Unknown);
});
