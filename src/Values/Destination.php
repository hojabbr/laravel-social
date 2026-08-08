<?php

namespace Hojabbr\Social\Values;

use Hojabbr\Social\Enums\Placement;

/**
 * WHERE a publish lands: an account, what kind of post it is, and optionally a
 * sub-destination inside that account.
 *
 * `$topic` is the generalisation of Telegram's `message_thread_id`. A forum
 * supergroup is ONE chat whose topics are a routing parameter, which is exactly
 * the shape "a section inside an account" takes; Instagram and YouTube have no
 * equivalent and ignore it. Managing topics is a separate optional contract
 * (SupportsTopics) because posting into one and creating one are different
 * privileges.
 */
final readonly class Destination
{
    public function __construct(
        public Account $account,
        public Placement $placement,
        public int|string|null $topic = null,
    ) {}
}
