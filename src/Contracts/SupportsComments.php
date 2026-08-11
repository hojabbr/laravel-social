<?php

namespace Hojabbr\Social\Contracts;

use Hojabbr\Social\Values\Account;
use Hojabbr\Social\Values\PublishResult;

/**
 * A network whose comment threads can be written to through its API.
 *
 * WRITE-ONLY ON PURPOSE, and the missing method is the important one. There is
 * deliberately no `comments($mediaId)` here, because on Instagram Login it
 * cannot be written honestly: `GET /{media-id}/comments` answers 200 with an
 * empty list on a post carrying dozens of real comments, and answers empty even
 * for a comment the account itself created one second earlier. A read method
 * would therefore return `[]` forever, and every caller would read that as "this
 * post has no comments" and act on it — a silent wrong answer is worse than an
 * absent method. Reading is the inbound webhook's job and belongs to the
 * application, not to a driver.
 *
 * Optional, like {@see SupportsDeletion}, and tested with `instanceof` rather
 * than a Capabilities flag: a flag is a second statement about the same fact,
 * and it is the one that can be wrong.
 *
 * WHY replyToComment() RETURNS A PublishResult AND THE OTHER TWO RETURN bool. A
 * reply CREATES a public object, so it has a publish's three outcomes: it was
 * created, it was refused before anything existed, or we never heard back and
 * something may be live. Hiding and deleting mutate an object that already
 * exists, where "it did not happen" is the only failure worth distinguishing.
 */
interface SupportsComments
{
    /**
     * Reply to one comment, creating a public object under it.
     *
     * Never throws: a transient network failure must arrive as the Unknown
     * outcome so the caller can refuse to retry, rather than as an exception a
     * queue worker turns into an automatic one.
     */
    public function replyToComment(Account $account, int|string $commentId, string $text): PublishResult;

    /**
     * Hide or unhide a comment. False means the network refused or was not
     * reached — never that the comment was already in that state.
     */
    public function hideComment(Account $account, int|string $commentId, bool $hidden): bool;

    /**
     * Delete a comment. On Instagram this works for a comment the account owns
     * AND for a reader's comment on the account's own media, which is why it is
     * here while whole-POST deletion is not.
     */
    public function deleteComment(Account $account, int|string $commentId): bool;
}
