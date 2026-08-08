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

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(5);
    }
}
