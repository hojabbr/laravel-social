<?php

namespace Hojabbr\Social\Drivers\YouTube;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The transport for the YouTube Data API, YouTube Analytics and Google's OAuth
 * token endpoint. No SDK: the whole path is an OAuth refresh, two upload calls,
 * a thumbnail POST and two reads, and `google/apiclient` is a large dependency
 * (with its own HTTP stack) to own six URLs.
 *
 * Two things live here rather than in the driver:
 *
 *  - The ACCESS TOKEN. It lasts an hour, the refresh token is open-ended, and a
 *    driver that refreshed per call would burn a request on every publish. It is
 *    cached under a hash of the refresh token, so rotating the refresh token
 *    invalidates the cache by construction instead of by remembering to flush.
 *  - The resumable UPLOAD, whose two halves have to stay together: the session
 *    URI arrives in a `Location` RESPONSE HEADER, not in the body, and the second
 *    call PUTs the bytes to that URI with no `Content-Range` (a whole-file PUT
 *    needs none, and sending one invites a 308 dance we do not implement).
 *
 * Nothing here throws except ConnectionException, which the driver reads as "we
 * never heard back" and maps to the Unknown outcome.
 */
class YouTubeClient
{
    /**
     * Access tokens last 3600s. 55 minutes keeps a cached token from expiring
     * mid-upload, which is the failure this margin exists for: a 50MB PUT can
     * take minutes, and the token is checked before it starts.
     */
    private const TOKEN_CACHE_SECONDS = 3300;

    /**
     * @param  array<string, string>  $endpoints  api_base, upload_base, analytics_base, token_endpoint, authorize_endpoint
     */
    public function __construct(
        private readonly array $endpoints,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    // -----------------------------------------------------------------
    // OAuth
    // -----------------------------------------------------------------

    /**
     * Where to send the owner once, to grant the channel.
     *
     * `access_type=offline` + `prompt=consent` is what actually returns a refresh
     * token: without the prompt, Google re-grants a previously consented scope
     * and omits `refresh_token`, so a re-connect silently yields credentials that
     * cannot be renewed.
     */
    public function authorizationUrl(string $redirectUri, string $state, string $scope): string
    {
        return $this->endpoint('authorize_endpoint').'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Trade the one-time authorization code for a refresh token.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}|null
     */
    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        try {
            $response = Http::asForm()->timeout(30)->post($this->endpoint('token_endpoint'), [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        $refresh = $response->json('refresh_token');
        $expires = $response->json('expires_in');

        return [
            'access_token' => $token,
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'expires_in' => is_numeric($expires) ? (int) $expires : null,
        ];
    }

    /**
     * A live access token for this refresh token, from cache when we have one.
     *
     * @return array{access_token: string, expires_in: int}|null
     */
    public function accessToken(string $refreshToken, bool $fresh = false): ?array
    {
        $key = 'social:youtube:access-token:'.hash('xxh128', $refreshToken);

        if ($fresh) {
            Cache::forget($key);
        }

        // Whatever is in the cache is whatever was written there, possibly by an
        // older shape of this method, so the row is validated and rebuilt rather
        // than trusted and returned.
        $cached = Cache::get($key);

        if (is_array($cached)) {
            $token = $cached['access_token'] ?? null;
            $expires = $cached['expires_in'] ?? null;

            if (is_string($token) && $token !== '' && is_numeric($expires)) {
                return ['access_token' => $token, 'expires_in' => (int) $expires];
            }
        }

        $token = $this->requestAccessToken($refreshToken);

        if ($token !== null) {
            // An ARRAY, never an object: a serialising cache store hands an
            // object back as __PHP_Incomplete_Class on the HIT only.
            Cache::put($key, $token, min(self::TOKEN_CACHE_SECONDS, max(60, $token['expires_in'] - 300)));
        }

        return $token;
    }

    /**
     * @return array{access_token: string, expires_in: int}|null
     */
    private function requestAccessToken(string $refreshToken): ?array
    {
        try {
            $response = Http::asForm()->timeout(30)->post($this->endpoint('token_endpoint'), [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        $expires = $response->json('expires_in');

        return ['access_token' => $token, 'expires_in' => is_numeric($expires) ? (int) $expires : 3600];
    }

    // -----------------------------------------------------------------
    // Data API
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     */
    public function get(string $path, array $query, string $accessToken): Response
    {
        return $this->request($accessToken)->get($this->endpoint('api_base').'/'.ltrim($path, '/'), $query);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    public function post(string $path, array $query, array $payload, string $accessToken): Response
    {
        return $this->request($accessToken)
            ->asJson()
            ->post($this->endpoint('api_base').'/'.ltrim($path, '/').'?'.http_build_query($query), $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     */
    public function delete(string $path, array $query, string $accessToken): Response
    {
        return $this->request($accessToken)->delete($this->endpoint('api_base').'/'.ltrim($path, '/'), $query);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     */
    public function analytics(array $query, string $accessToken): Response
    {
        return $this->request($accessToken)->get($this->endpoint('analytics_base').'/reports', $query);
    }

    // -----------------------------------------------------------------
    // Resumable upload
    // -----------------------------------------------------------------

    /**
     * Open an upload session and return its URI, which arrives in the `Location`
     * RESPONSE HEADER. A 2xx with no such header is a failure, not a success —
     * the driver turns it into a Rejected rather than uploading to nowhere.
     *
     * @param  array<string, mixed>  $metadata  The videos.insert resource (snippet, status).
     *
     * @throws ConnectionException
     */
    public function openUploadSession(array $metadata, string $mimeType, int $bytes, string $accessToken): Response
    {
        $url = $this->endpoint('upload_base').'/videos?'.http_build_query([
            'uploadType' => 'resumable',
            'part' => 'snippet,status',
        ]);

        return $this->request($accessToken)
            ->withHeaders([
                'X-Upload-Content-Type' => $mimeType,
                'X-Upload-Content-Length' => (string) $bytes,
            ])
            ->asJson()
            ->post($url, $metadata);
    }

    /**
     * PUT the whole file to the session URI.
     *
     * The body is a STREAM, not a string: a long video read into memory would
     * hold its full size in a queue worker. `withBody()` takes a string or a PSR
     * stream, so the handle is wrapped — handing it the raw resource works by
     * accident today and is not the documented contract.
     *
     * @throws ConnectionException
     */
    public function uploadBytes(string $sessionUri, string $path, string $mimeType, int $bytes, string $accessToken): Response
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new ConnectionException("Could not open {$path} for upload.");
        }

        try {
            return Http::withToken($accessToken)
                ->timeout(1800)
                ->connectTimeout(10)
                ->withHeaders(['Content-Length' => (string) $bytes])
                ->withBody(Utils::streamFor($handle), $mimeType)
                ->put($sessionUri);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * thumbnails.set takes the image as a RAW BINARY body, not multipart and not
     * base64 — a form upload here answers with a generic media error.
     *
     * @throws ConnectionException
     */
    public function setThumbnail(string $videoId, string $path, string $mimeType, string $accessToken): Response
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ConnectionException("Could not read the thumbnail at {$path}.");
        }

        $url = $this->endpoint('upload_base').'/thumbnails/set?'.http_build_query(['videoId' => $videoId]);

        return Http::withToken($accessToken)
            ->timeout(120)
            ->connectTimeout(5)
            ->withBody($contents, $mimeType)
            ->post($url);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * The human-readable half of a Google API error.
     */
    public static function errorOf(Response $response): string
    {
        $error = $response->json('error');

        if (is_string($error)) {
            // The OAuth endpoint answers with a flat {error, error_description}.
            $description = $response->json('error_description');

            return sprintf('Google refused the request (%s)%s.', $error, is_string($description) ? ': '.$description : '');
        }

        if (! is_array($error)) {
            return 'YouTube returned HTTP '.$response->status().' with no error detail.';
        }

        $reason = $error['errors'][0]['reason'] ?? null;
        $message = $error['message'] ?? 'the request was refused';

        return sprintf(
            'YouTube refused the request (HTTP %d%s): %s',
            $response->status(),
            is_string($reason) ? '/'.$reason : '',
            is_scalar($message) ? $message : 'the request was refused',
        );
    }

    private function endpoint(string $key): string
    {
        return rtrim($this->endpoints[$key] ?? '', '/');
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(60)->connectTimeout(5);
    }
}
