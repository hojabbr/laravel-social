<?php

namespace Hojabbr\Social\Testing;

use Hojabbr\Social\Contracts\Driver;
use Hojabbr\Social\Enums\Placement;
use Hojabbr\Social\SocialManager;
use Hojabbr\Social\Values\Account;
use Hojabbr\Social\Values\Capabilities;
use Hojabbr\Social\Values\Destination;
use Hojabbr\Social\Values\Health;
use Hojabbr\Social\Values\PublishRequest;
use Hojabbr\Social\Values\PublishResult;
use Hojabbr\Social\Values\RateProfile;

/**
 * A driver that publishes nothing, records what it was asked to publish, and
 * returns whatever outcome the test scripted.
 *
 * This is for testing the CALLER: that a rejection releases a claim, that an
 * unknown keeps it, that a caption was composed the way it should be. Testing a
 * real driver is a different job and belongs on the real driver with a faked HTTP
 * layer — a fake that reimplements Instagram's container ladder would only ever
 * prove that the fake agrees with itself.
 *
 * It implements `Driver` and NOTHING else on purpose. If it also claimed
 * SupportsDeletion or SupportsTopics, every `instanceof` check in the caller would
 * pass under test and fail in production — the Instagram retraction path, which
 * exists precisely because Instagram cannot delete, would take the wrong branch
 * and the test would call it correct.
 */
final class FakeDriver implements Driver
{
    /** @var list<PublishRequest> */
    public array $requests = [];

    /** @var list<PublishResult> */
    protected array $results = [];

    public function __construct(
        protected string $network,
        protected string $queue = 'default',
        protected bool $usable = true,
        protected ?Capabilities $capabilities = null,
    ) {}

    /**
     * Build one and register it on the manager for the given network, so the code
     * under test resolves the fake through the ordinary `Social::driver()` path.
     *
     * The driver name is read from config, because a network entry may name a
     * driver that differs from it — replacing the driver is what makes the swap
     * invisible to the caller.
     */
    public static function fake(string $network, string $queue = 'default'): self
    {
        $manager = app(SocialManager::class);
        $driver = (string) config("social.networks.{$network}.driver", $network);

        $fake = new self($network, $queue);

        $manager->extend($driver, static fn (): Driver => $fake)->forget($network);

        return $fake;
    }

    /**
     * Script the next outcome. Called several times, the results are returned in
     * order and the last one repeats — so a test that publishes twice can make the
     * first attempt fail without describing the second.
     */
    public function willReturn(PublishResult $result): static
    {
        $this->results[] = $result;

        return $this;
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $this->requests[] = $request;

        if ($this->results === []) {
            return PublishResult::sent('fake-'.count($this->requests));
        }

        return count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
    }

    public function lastRequest(): ?PublishRequest
    {
        return $this->requests[array_key_last($this->requests) ?? 0] ?? null;
    }

    public function network(): string
    {
        return $this->network;
    }

    public function label(): string
    {
        return ucfirst($this->network).' (fake)';
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities ??= new Capabilities(
            placements: Placement::cases(),
            bodyLimit: 4096,
            captionLimit: 1024,
            tagLimit: 5,
            maxItemsPerMessage: 10,
            mimeTypes: ['image/jpeg', 'image/png', 'video/mp4'],
            maxVideoBytes: 1024 * 1024 * 1024,
            maxImageBytes: 8 * 1024 * 1024,
        );
    }

    public function rateProfile(): RateProfile
    {
        // Zero, so a test never actually sleeps between sends.
        return new RateProfile(0);
    }

    public function queue(): string
    {
        return $this->queue;
    }

    public function isUsable(): bool
    {
        return $this->usable;
    }

    public function unusable(): static
    {
        $this->usable = false;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function accountKeys(): array
    {
        return ['fa', 'en', 'default'];
    }

    public function hasAccount(string $key): bool
    {
        return in_array($key, $this->accountKeys(), true);
    }

    public function account(string $key): Account
    {
        return new Account($this->network, $key, id: 'fake-account', handle: $key, token: 'fake-token');
    }

    public function destination(string $key, Placement $placement, int|string|null $topic = null): Destination
    {
        return new Destination($this->account($key), $placement, $topic);
    }

    public function health(): Health
    {
        return new Health($this->network, true, $this->usable, ['fake' => true]);
    }
}
