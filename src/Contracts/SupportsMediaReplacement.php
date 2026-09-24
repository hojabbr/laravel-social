<?php

namespace Hojabbr\Social\Contracts;

use Hojabbr\Social\Enums\MediaReplacement;
use Hojabbr\Social\Values\Account;
use Hojabbr\Social\Values\PublishRequest;

/**
 * A network whose already-published object can carry NEW media in place: the
 * same post, the same link, the same place in the feed, a different file.
 *
 * Optional, and the absence carries the meaning, exactly as with
 * {@see SupportsDeletion} and {@see SupportsCoverUpdate}. Instagram does not
 * implement it: a Reel's video is fixed when its container is created. A caller
 * checking `instanceof` learns that a post whose film was replaced can only be
 * posted again, never repaired.
 *
 * This exists because media outlives the publish that carried it. A re-render
 * that fixes the narration leaves the live post playing the old film, and the
 * only other remedy is to delete the post and send a new one, which spends the
 * post's link, its views and its reactions, and breaks a consumer's
 * one-post-per-content invariant.
 */
interface SupportsMediaReplacement
{
    /**
     * Replace one published object's media with the request's first media item,
     * and its caption with the request's body.
     *
     * The body is sent again because the networks that can do this clear the
     * caption when a replacement omits it. The destination's topic is ignored:
     * the object keeps the place it already has.
     *
     * Never throws, for the same reason delete() does not: a caller walking
     * several posts has to be able to continue past one failure. `Missing`
     * means the network confirmed the object is gone; `Failed` means it may
     * still be live, and a replacement is idempotent, so a caller may try again.
     */
    public function replaceMedia(Account $account, int|string $externalId, PublishRequest $request): MediaReplacement;
}
