<?php

namespace Hojabbr\Social\Contracts;

use Hojabbr\Social\Values\Account;

/**
 * A network whose accounts contain addressable sub-destinations the API can
 * manage — Telegram's forum topics being the case this was drawn from.
 *
 * Posting INTO a topic needs none of this (that is `Destination::$topic`); this
 * is the create/rename/close/delete privilege, which is a different thing and
 * which most networks simply do not have.
 */
interface SupportsTopics
{
    /**
     * Create a topic and return its id, or null when the network refused.
     *
     * `$iconColor` is separate from `$iconId` because it is CREATE-ONLY on
     * Telegram — hence its absence from syncTopic(), which can re-assert
     * everything else about a topic but never its colour.
     */
    public function createTopic(Account $account, string $name, ?string $iconId = null, ?int $iconColor = null): int|string|null;

    /**
     * Push a topic's name and icon, and report whether the topic still exists.
     *
     * TRI-STATE, and the third state is the point: null means "could not tell"
     * (a network blip), and a caller must NOT read it as deleted — recreating a
     * topic that is actually alive leaves a duplicate nobody notices.
     */
    public function syncTopic(Account $account, int|string $topic, string $name, ?string $iconId = null): ?bool;

    public function closeTopic(Account $account, int|string $topic): bool;

    public function deleteTopic(Account $account, int|string $topic): bool;
}
