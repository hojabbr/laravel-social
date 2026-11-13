<?php

namespace Hojabbr\Social\Support;

/**
 * Untyped values read with the types they mean: a network's decoded JSON and
 * the package's config entries.
 *
 * A network answers in whatever shape it chooses that day, so an unusable
 * value becomes the empty answer of its type rather than an error mid-publish.
 * A number is accepted from a numeric string, since config arrives from the
 * environment as strings and some APIs quote their ids.
 *
 * @internal
 */
final class Payload
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : $default;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) || is_float($value) ? (int) $value : $default;
    }

    /**
     * The value as a map, its non-string keys dropped.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * A list's first element as a map: the shape Graph and YouTube use for a
     * single answer (`data[0]`, `items[0]`).
     *
     * @return array<string, mixed>
     */
    public static function first(mixed $value): array
    {
        return is_array($value) && array_is_list($value) && $value !== [] ? self::map($value[0]) : [];
    }
}
