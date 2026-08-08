<?php

namespace Hojabbr\Social\Facades;

use Closure;
use Hojabbr\Social\Contracts\Driver;
use Hojabbr\Social\SocialManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Driver driver(string $network)
 * @method static SocialManager extend(string $driver, Closure $factory)
 * @method static list<string> networks()
 * @method static list<string> usable()
 * @method static SocialManager forget(?string $network = null)
 *
 * @see SocialManager
 */
class Social extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SocialManager::class;
    }
}
