<?php

namespace Hojabbr\Social\Contracts;

use Hojabbr\Social\Values\Account;

/**
 * A network whose already-published object can be given a NEW cover image.
 *
 * Optional, and the absence carries the meaning, exactly as with
 * {@see SupportsDeletion}. Instagram does not implement it: a Reel's cover is
 * fixed at container creation and there is no endpoint to change it afterwards,
 * so a caller that checks `instanceof` is forced to say "this one cannot be
 * fixed in place" rather than believing a no-op worked.
 *
 * This exists because a cover outlives the publish that carried it. A design
 * change, a re-render, or — the case that produced it — a cover that turned out
 * to be the wrong SHAPE for the network's slot all leave a live post wearing an
 * image the app has since replaced, and re-publishing to fix a picture would
 * mean deleting and re-uploading the video itself.
 */
interface SupportsCoverUpdate
{
    /**
     * Replace one published object's cover with the image at `$imagePath`.
     *
     * Returns false when the network refused or could not be reached — never
     * throws, for the same reason delete() does not: a caller walking several
     * posts has to be able to continue past one failure.
     */
    public function updateCover(Account $account, int|string $externalId, string $imagePath): bool;
}
