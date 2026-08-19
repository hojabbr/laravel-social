<?php

namespace Hojabbr\Social\Drivers\Instagram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP seam for graph.instagram.com — Instagram Login, not the
 * Facebook-login Graph host. Which host matters: the token shape (IGAA…), the
 * available fields and the deletion behaviour all differ between the two, and
 * only this one accepts our tokens.
 *
 * No SDK: the whole publishing path is four calls and two reads, and a Graph SDK
 * would be a large dependency to own six URLs.
 *
 * Every method returns the decoded body plus the HTTP status, and NOTHING throws
 * except a genuine transport failure (ConnectionException), which the driver
 * turns into the Unknown outcome. That split is the whole reason this class
 * exists separately from the driver: "Instagram said no" and "we never heard
 * back" have to arrive at the driver as two different things.
 */
class InstagramClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    public function post(string $path, array $payload, string $token): Response
    {
        return $this->request($token)->asForm()->post($this->url($path), $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     */
    public function get(string $path, array $query, string $token): Response
    {
        return $this->request($token)->get($this->url($path), $query);
    }

    /**
     * @throws ConnectionException
     */
    public function delete(string $path, string $token): Response
    {
        return $this->request($token)->delete($this->url($path));
    }

    /**
     * The one call on this host that refuses a Bearer header.
     *
     * `refresh_access_token` is an OAuth operation wearing a Graph URL. The
     * token is the SUBJECT of the request rather than the authentication for
     * it, and Meta reads it only from the query string: sent as a header, the
     * endpoint answers `400 IGApiException code 100 — The parameter
     * access_token is required`, on both the versioned and unversioned paths.
     *
     * That refusal is indistinguishable, at every surface above this line, from
     * the one a genuinely dead token earns. So a rotation that never once
     * worked reported "the network refused the renewal" every night, which
     * reads as an account needing re-consent — and an Instagram long-lived
     * token is renewable ONLY while it is still valid, so the failure mode of
     * believing that report is a publishing path that stops dead at sixty days
     * with no way back but a human re-authorising.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     */
    public function getWithTokenInQuery(string $path, array $query, string $token): Response
    {
        return $this->pending()->get($this->url($path), [...$query, 'access_token' => $token]);
    }

    /**
     * The human-readable half of a Graph error. Graph nests it under `error`,
     * with `error_user_msg` being the one written for a person when it exists.
     */
    public static function errorOf(Response $response): string
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return 'Instagram returned HTTP '.$response->status().' with no error detail.';
        }

        $message = $error['error_user_msg'] ?? $error['message'] ?? 'the request was refused';
        $code = $error['code'] ?? $response->status();
        $subcode = $error['error_subcode'] ?? null;

        return sprintf(
            'Instagram refused the request (code %s%s): %s',
            is_scalar($code) ? $code : '?',
            $subcode === null ? '' : '/'.(is_scalar($subcode) ? $subcode : '?'),
            is_scalar($message) ? $message : 'the request was refused',
        );
    }

    /**
     * Whether a refusal is Meta's anti-spam block rather than an ordinary error.
     *
     * Error 368 ("Action Blocked") with OAuthException code 9 is an undocumented,
     * account-level judgement about POSTING BEHAVIOUR — it scores burst rate and
     * repetitive text, not a daily total — and nothing reports whether it has
     * lifted. It is exposed here as a question rather than an outcome because it
     * does not change what happened to the request: nothing was created, so it is
     * still a rejection. What it changes is what the CALLER should do next, which
     * is stop writing comments for a while.
     */
    public static function isSpamBlock(Response $response): bool
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return false;
        }

        return (int) ($error['code'] ?? 0) === 368
            || ((int) ($error['code'] ?? 0) === 9 && ($error['type'] ?? null) === 'OAuthException');
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function request(string $token): PendingRequest
    {
        return $this->pending()->withToken($token);
    }

    private function pending(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(5);
    }
}
