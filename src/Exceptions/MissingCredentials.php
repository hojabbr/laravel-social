<?php

namespace Hojabbr\Social\Exceptions;

class MissingCredentials extends SocialException
{
    /**
     * @param  list<string>  $known
     */
    public static function account(string $network, string $key, array $known): self
    {
        return new self(sprintf(
            'No [%s] account is configured under the key [%s]. Configured keys: %s.',
            $network,
            $key,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }
}
