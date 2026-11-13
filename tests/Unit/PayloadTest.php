<?php

use Hojabbr\Social\Support\Payload;

it('reads a scalar as text and anything else as the default', function (): void {
    expect(Payload::string('reel'))->toBe('reel')
        ->and(Payload::string(17841400))->toBe('17841400')
        ->and(Payload::string(null, 'telegram'))->toBe('telegram')
        ->and(Payload::string(['id' => 1]))->toBe('');
});

it('reads a number from an int, a float or a numeric string only', function (): void {
    expect(Payload::int(368))->toBe(368)
        ->and(Payload::int('9'))->toBe(9)
        ->and(Payload::int(2.0))->toBe(2)
        ->and(Payload::int('OAuthException'))->toBe(0)
        ->and(Payload::int(null, -1))->toBe(-1);
});

it('keeps a map\'s string keys and drops the rest', function (): void {
    expect(Payload::map(['id' => 1, 0 => 'stray']))->toBe(['id' => 1])
        ->and(Payload::map('not an array'))->toBe([]);
});

it('reads the first element of a list as a map', function (): void {
    expect(Payload::first([['reason' => 'quotaExceeded'], ['reason' => 'later']]))->toBe(['reason' => 'quotaExceeded'])
        ->and(Payload::first(['reason' => 'a map, not a list']))->toBe([])
        ->and(Payload::first([]))->toBe([])
        ->and(Payload::first(['scalar']))->toBe([]);
});
