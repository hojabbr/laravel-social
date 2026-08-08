<?php

namespace Hojabbr\Social\Enums;

/**
 * WHAT is being published, in terms every network can be asked about — not in
 * any one network's product vocabulary. A driver declares the placements it
 * serves in its Capabilities, so the caller asks "can you take a Reel?" instead
 * of branching on the network's name.
 */
enum Placement: string
{
    /** A chat message: text, or text with one photo/video/album. */
    case Message = 'message';

    /** A short vertical video: an Instagram Reel, a YouTube Short. */
    case Reel = 'reel';

    /** An ephemeral post: an Instagram Story. */
    case Story = 'story';

    /** A permanent image post: one picture or a carousel. */
    case Feed = 'feed';

    /** Long-form video: an ordinary YouTube upload. */
    case LongVideo = 'long_video';
}
