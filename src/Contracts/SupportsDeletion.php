<?php

namespace Hojabbr\Social\Contracts;

use Hojabbr\Social\Values\Account;

/**
 * A network whose published objects can be removed through its API.
 *
 * Optional on purpose, and the absence carries the meaning: an Instagram post
 * made through Instagram Login CANNOT be deleted programmatically (the API
 * answers "This api only supports Instagram API with Facebook login only"), so
 * InstagramDriver does not implement this and a retraction path that checks
 * `instanceof` is forced to deal with that instead of silently believing a
 * delete happened.
 */
interface SupportsDeletion
{
    /**
     * Remove one published object. Returns false when the network refused or
     * could not be reached — never throws, for the same reason publish() does
     * not: a retraction walking several ids must be able to continue.
     */
    public function delete(Account $account, int|string $externalId): bool;
}
