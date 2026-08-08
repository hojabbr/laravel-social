<?php

namespace Hojabbr\Social\Tests;

use Hojabbr\Social\SocialServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A minimal Laravel application with only this package registered.
 *
 * Testbench is here so the suite runs on its own, in CI for this repository,
 * rather than only inside whichever application happens to consume the package —
 * a test that needs someone else's app to run is a test nobody runs.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SocialServiceProvider::class];
    }
}
