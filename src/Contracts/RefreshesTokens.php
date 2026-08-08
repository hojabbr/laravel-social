<?php

namespace Hojabbr\Social\Contracts;

use Hojabbr\Social\Values\Account;
use Hojabbr\Social\Values\Credentials;

/**
 * A network whose credentials expire and can be renewed without a human.
 *
 * The driver renews and RETURNS the material; persisting it is the consumer's
 * job, because only the consumer knows where its secrets live. A package that
 * wrote the token itself would be a second writer to somebody else's store.
 */
interface RefreshesTokens
{
    /**
     * What this account's credentials look like now — read from config, and
     * renewed first if they are close enough to expiry to be worth renewing.
     * Null when the account cannot be refreshed (no credentials, or the network
     * refused), which is a reportable state rather than an exception.
     */
    public function refresh(Account $account): ?Credentials;

    /**
     * The current credentials WITHOUT renewing them — what a status read-out
     * needs so opening an admin page cannot rotate a live token.
     */
    public function credentials(Account $account): ?Credentials;
}
