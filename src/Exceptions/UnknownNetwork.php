<?php

namespace Hojabbr\Social\Exceptions;

class UnknownNetwork extends SocialException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $network, array $known): self
    {
        return new self(sprintf(
            'No social network [%s] is configured. Known networks: %s.',
            $network,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }
}
