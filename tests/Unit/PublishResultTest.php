<?php

use Hojabbr\Social\Enums\Outcome;
use Hojabbr\Social\Values\PublishResult;

test('a sent result carries its id in both the singular and plural fields', function (): void {
    $result = PublishResult::sent(4211, 'https://t.me/c/1/4211');

    expect($result->outcome)->toBe(Outcome::Sent)
        ->and($result->isSent())->toBeTrue()
        ->and($result->externalId)->toBe(4211)
        ->and($result->externalIds)->toBe([4211])
        ->and($result->url)->toBe('https://t.me/c/1/4211')
        ->and($result->error)->toBeNull();
});

test('a multi-object send keeps every id and reports the first as the primary', function (): void {
    $result = PublishResult::sentMany([11, 12, 13]);

    expect($result->externalId)->toBe(11)
        ->and($result->externalIds)->toBe([11, 12, 13]);
});

test('a rejection and an unknown are different outcomes, never one failure', function (): void {
    // This is the distinction the whole package exists to preserve: a rejection
    // is safe to retry, an unknown may already be live.
    $rejected = PublishResult::rejected('the caption was too long');
    $unknown = PublishResult::unknown('the connection dropped mid-publish');

    expect($rejected->isRejected())->toBeTrue()
        ->and($rejected->isUnknown())->toBeFalse()
        ->and($rejected->externalId)->toBeNull()
        ->and($unknown->isUnknown())->toBeTrue()
        ->and($unknown->isRejected())->toBeFalse()
        ->and($unknown->outcome)->toBe(Outcome::Unknown);
});

test('a send with no id reports no ids rather than a list holding null', function (): void {
    expect(PublishResult::sent(null)->externalIds)->toBe([]);
});
