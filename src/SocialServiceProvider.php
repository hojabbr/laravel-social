<?php

namespace Hojabbr\Social;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class SocialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/social.php', 'social');

        $this->app->singleton(SocialManager::class, static fn ($app): SocialManager => new SocialManager($app['config']));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/social.php' => config_path('social.php'),
        ], 'social-config');

        AboutCommand::add('Social', fn (): array => [
            'Networks' => implode(', ', $this->app->make(SocialManager::class)->networks()) ?: '(none)',
            'Usable' => implode(', ', $this->app->make(SocialManager::class)->usable()) ?: '(none)',
        ]);
    }
}
