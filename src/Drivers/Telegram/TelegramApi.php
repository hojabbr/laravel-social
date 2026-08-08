<?php

namespace Hojabbr\Social\Drivers\Telegram;

use Hojabbr\Social\Exceptions\MissingDependency;
use Hojabbr\Social\Values\PublishResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;
use Throwable;

/**
 * The Bot API transport.
 *
 * Wraps irazasyed/telegram-bot-sdk rather than hand-rolling HTTP: the SDK covers
 * the whole surface a broadcast bot needs (forum topics, chat permissions, member
 * lookups) and is maintained. What stays ours is the ERROR SEMANTICS, because the
 * three-state outcome depends on a distinction the SDK expresses as two exception
 * classes and a caller must not lose:
 *
 *   TelegramResponseException — Telegram answered and refused. Deterministic:
 *                               no message exists, so a claim may be released.
 *   TelegramSDKException      — no response at all (a dropped connection). The
 *                               message MAY be live, so the claim is KEPT.
 *
 * Nothing here throws for a send; every send returns a PublishResult.
 */
class TelegramApi
{
    /** Telegram answers 429 with parameters.retry_after; honour it once, then give up. */
    private const FLOOD_RETRIES = 1;

    /** Added to retry_after so a clock skew cannot make the retry premature. */
    private const FLOOD_JITTER_SECONDS = 1;

    private ?Api $api = null;

    public function __construct(private readonly string $token) {}

    /**
     * Swap the underlying SDK instance. Tests inject a mocked Guzzle stack here,
     * which keeps the seam at the HTTP boundary rather than at our own methods.
     */
    public function setApi(Api $api): self
    {
        $this->api = $api;

        return $this;
    }

    /**
     * Run a send and translate the outcome into the three-state result.
     *
     * @param  array<string, mixed>  $params
     */
    public function send(string $method, array $params): PublishResult
    {
        try {
            /** @var Message $message */
            $message = $this->invoke(fn (Api $api) => $api->{$method}($params));

            $messageId = $message->get('message_id');

            return PublishResult::sent(is_numeric($messageId) ? (int) $messageId : null);
        } catch (TelegramResponseException $exception) {
            return PublishResult::rejected($this->describe($exception));
        } catch (TelegramSDKException $exception) {
            // No response ever arrived, so the send may or may not have landed.
            // The caller must keep its claim and ask a human.
            Log::warning('Telegram call outcome unknown.', ['method' => $method, 'error' => $exception->getMessage()]);

            return PublishResult::unknown(
                "Could not confirm the Telegram post ({$exception->getMessage()}) — a message may already be live in the group; check before re-posting.",
            );
        }
    }

    /**
     * sendMediaGroup, whose result is a LIST of Messages rather than one.
     *
     * The SDK types it as a single MessageObject and flags the return type as a
     * TODO in its own source; what actually arrives is BaseObject wrapping
     * `result`, which for an album is a list of message arrays. So the ids are
     * read positionally instead of through ->get('message_id'), which would
     * return null here and strand every page id.
     *
     * Error semantics are identical to send(): a refusal is deterministic, a
     * dropped connection is not and must never be blind-retried — an album that
     * may be half-live in the group is exactly the case where a retry doubles it.
     *
     * @param  array<string, mixed>  $params
     */
    public function sendAlbum(array $params): PublishResult
    {
        try {
            /** @var Message $messages */
            $messages = $this->invoke(fn (Api $api) => $api->sendMediaGroup($params));

            $ids = [];

            foreach ($messages->all() as $message) {
                $id = is_array($message) ? ($message['message_id'] ?? null) : null;

                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }

            if ($ids === []) {
                return PublishResult::unknown('Telegram accepted the album but returned no message ids — check the group before re-posting.');
            }

            return PublishResult::sentMany($ids);
        } catch (TelegramResponseException $exception) {
            return PublishResult::rejected($this->describe($exception));
        } catch (TelegramSDKException $exception) {
            Log::warning('Telegram album outcome unknown.', ['error' => $exception->getMessage()]);

            return PublishResult::unknown(
                "Could not confirm the Telegram album ({$exception->getMessage()}) — some pages may already be live in the group; check before re-posting.",
            );
        }
    }

    /**
     * Run a non-send call, returning null instead of throwing. Used by the setup
     * and diagnostic paths, where a boolean or an object is enough and there is
     * no claim to protect.
     *
     * @template TReturn
     *
     * @param  callable(Api): TReturn  $call
     * @return TReturn|null
     */
    public function attempt(callable $call): mixed
    {
        try {
            return $this->invoke($call);
        } catch (Throwable $exception) {
            Log::warning('Telegram call failed.', [
                'error' => $exception instanceof TelegramResponseException
                    ? $this->describe($exception)
                    : $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Run a call, honouring a 429 `retry_after` once before giving up. Every
     * outbound call goes through here — the ceiling is per CHAT and topic
     * creation counts against it too, so a setup burst floods just as readily
     * as a send does.
     *
     * @template TReturn
     *
     * @param  callable(Api): TReturn  $call
     * @return TReturn
     */
    public function invoke(callable $call): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $call($this->api());
            } catch (TelegramResponseException $exception) {
                $retryAfter = $this->retryAfter($exception);

                if ($retryAfter <= 0 || $attempt >= self::FLOOD_RETRIES) {
                    throw $exception;
                }

                Log::warning('Telegram flood limit hit; waiting.', ['retry_after' => $retryAfter]);

                Sleep::for($retryAfter + self::FLOOD_JITTER_SECONDS)->seconds();
                $attempt++;
            }
        }
    }

    /**
     * Seconds Telegram asked us to wait, or 0 when this was not a flood error.
     */
    private function retryAfter(TelegramResponseException $exception): int
    {
        $parameters = $exception->get('parameters');

        return is_array($parameters) && is_numeric($parameters['retry_after'] ?? null)
            ? (int) $parameters['retry_after']
            : 0;
    }

    /**
     * Turn an API refusal into something an operator can act on.
     */
    public function describe(TelegramResponseException $exception): string
    {
        $description = $exception->getMessage();
        $code = (int) $exception->get('error_code', 0);

        return match (true) {
            $code === 401 => 'The bot token was rejected — regenerate it with @BotFather and update it in Settings.',
            $code === 403 => 'The bot was removed from the group, or it cannot post in that topic. Re-add it as an admin with Manage Topics.',
            $code === 429 => "Telegram is rate limiting the bot and the retry window was exhausted: {$description}",
            str_contains($description, 'message thread not found') => 'That topic no longer exists in the group — re-run the topic setup to repair the topic map.',
            str_contains($description, 'chat not found') => 'The configured chat id is wrong, or the bot is not a member of the group.',
            str_contains($description, 'not enough rights') => 'The bot is missing an admin right for this action (most often Manage Topics).',
            default => "Telegram refused the request (HTTP {$code}): {$description}",
        };
    }

    private function api(): Api
    {
        if (! class_exists(Api::class)) {
            throw MissingDependency::package('Telegram', Api::class, 'irazasyed/telegram-bot-sdk');
        }

        return $this->api ??= (new Api($this->token))
            ->setTimeOut(120)
            ->setConnectTimeOut(5);
    }
}
