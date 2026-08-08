<?php

namespace Hojabbr\Social\Exceptions;

class MissingDependency extends SocialException
{
    public static function package(string $driver, string $class, string $composerPackage): self
    {
        return new self(sprintf(
            'The %s driver needs [%s], which is not installed. Run `composer require %s`.',
            $driver,
            $class,
            $composerPackage,
        ));
    }
}
