<?php

namespace Hojabbr\Social\Values;

/**
 * A flat bag of numbers read back from a network, and deliberately nothing more.
 *
 * Flat because every network names its own metrics and a typed property per
 * metric would be a schema that is wrong the week a platform renames one. Flat
 * ALSO because these get cached: `toArray()` is the cache-safe shape, and a
 * consumer whose cache store serialises must store the array and rehydrate.
 */
final readonly class Metrics
{
    /**
     * @param  array<string, int|float|string|null>  $values
     */
    public function __construct(
        public string $network,
        public array $values = [],
        public ?string $label = null,
        public ?string $error = null,
    ) {}

    public static function unavailable(string $network, string $error): self
    {
        return new self($network, error: $error);
    }

    public function get(string $key): int|float|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return array{network: string, label: string|null, error: string|null, values: array<string, int|float|string|null>}
     */
    public function toArray(): array
    {
        return [
            'network' => $this->network,
            'label' => $this->label,
            'error' => $this->error,
            'values' => $this->values,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, int|float|string|null> $values */
        $values = is_array($data['values'] ?? null) ? $data['values'] : [];

        return new self(
            network: (string) ($data['network'] ?? ''),
            values: $values,
            label: is_string($data['label'] ?? null) ? $data['label'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
        );
    }
}
