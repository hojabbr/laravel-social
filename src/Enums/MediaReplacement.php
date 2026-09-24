<?php

namespace Hojabbr\Social\Enums;

/**
 * What became of an attempt to give a published object new media.
 *
 * Three states because a caller acts on each differently. `Missing` is the one
 * a boolean would lose: the network answered that the object is no longer
 * there (someone deleted it by hand), which is the only answer that makes a
 * fresh post safe, since nothing of ours is live to be doubled.
 */
enum MediaReplacement: string
{
    /** The object now carries the new media. */
    case Replaced = 'replaced';

    /** The network says the object no longer exists. */
    case Missing = 'missing';

    /** Refused for another reason, or the network could not be reached: the object may still be live. */
    case Failed = 'failed';
}
