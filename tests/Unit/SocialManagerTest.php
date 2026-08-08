<?php

use Hojabbr\Social\Contracts\Driver;
use Hojabbr\Social\Drivers\Instagram\InstagramDriver;
use Hojabbr\Social\Drivers\Telegram\TelegramDriver;
use Hojabbr\Social\Drivers\YouTube\YouTubeDriver;
use Hojabbr\Social\Exceptions\UnknownNetwork;
use Hojabbr\Social\SocialManager;
use Hojabbr\Social\Testing\FakeDriver;
use Illuminate\Config\Repository;

function manager(array $config = []): SocialManager
{
    return new SocialManager(new Repository(['social' => $config === [] ? [
        'networks' => [
            'instagram' => ['driver' => 'instagram', 'enabled' => true],
            'youtube' => ['driver' => 'youtube', 'enabled' => true, 'client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh'],
            'telegram' => ['driver' => 'telegram', 'enabled' => false, 'token' => ''],
        ],
        'accounts' => [
            'instagram' => ['fa' => ['id' => '1784', 'token' => 'IGAA-token']],
            'youtube' => ['default' => ['id' => 'UC123']],
            'telegram' => ['default' => ['id' => '-100999']],
        ],
    ] : $config]));
}

test('each built-in network resolves to its driver', function (): void {
    $manager = manager();

    expect($manager->driver('instagram'))->toBeInstanceOf(InstagramDriver::class)
        ->and($manager->driver('youtube'))->toBeInstanceOf(YouTubeDriver::class)
        ->and($manager->driver('telegram'))->toBeInstanceOf(TelegramDriver::class);
});

test('a driver is memoised so two resolves are the same instance', function (): void {
    $manager = manager();

    expect($manager->driver('instagram'))->toBe($manager->driver('instagram'));
});

test('forgetting a network drops the memoised driver', function (): void {
    // What a token refresh calls: a driver holds the credentials it was built
    // with, so a renewed token would otherwise stay unused for the process.
    $manager = manager();
    $first = $manager->driver('instagram');

    expect($manager->forget('instagram')->driver('instagram'))->not->toBe($first);
});

test('a network entry can point at another network\'s driver with its own credentials', function (): void {
    $manager = manager([
        'networks' => ['instagram_agency' => ['driver' => 'instagram', 'enabled' => true]],
        'accounts' => ['instagram_agency' => ['fa' => ['id' => '999', 'token' => 'token']]],
    ]);

    $driver = $manager->driver('instagram_agency');

    expect($driver)->toBeInstanceOf(InstagramDriver::class)
        ->and($driver->network())->toBe('instagram_agency')
        ->and($driver->account('fa')->id)->toBe('999');
});

test('extend registers a driver the package has never heard of', function (): void {
    $manager = manager([
        'networks' => ['mastodon' => ['driver' => 'mastodon', 'enabled' => true, 'queue' => 'social']],
        'accounts' => ['mastodon' => ['default' => ['id' => '@sahmino']]],
    ]);

    $manager->extend('mastodon', fn (array $config, array $accounts, string $network): Driver => new FakeDriver($network, (string) $config['queue']));

    expect($manager->driver('mastodon')->network())->toBe('mastodon')
        ->and($manager->driver('mastodon')->queue())->toBe('social');
});

test('an unconfigured network name is an exception naming the ones that exist', function (): void {
    expect(fn () => manager()->driver('threads'))
        ->toThrow(UnknownNetwork::class, 'threads');
});

test('usable reports only the networks that are switched on and hold credentials', function (): void {
    // Telegram is configured with an empty token and disabled; Instagram has an
    // account; YouTube has a client and a refresh token.
    expect(manager()->usable())->toBe(['instagram', 'youtube']);
});

test('networks lists every configured key in config order', function (): void {
    expect(manager()->networks())->toBe(['instagram', 'youtube', 'telegram']);
});
