<?php

namespace App\Core\Database;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a PostgreSQL text[] column to a PHP list of strings and back.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
class TextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '{}') {
            return [];
        }

        // Postgres quotes elements with spaces, commas or quotes, and escapes " and \ with a backslash.
        return array_map(
            fn (string $item) => stripcslashes($item),
            str_getcsv(substr($value, 1, -1), ',', '"', '\\'),
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $items = array_map(fn ($item) => '"'.addcslashes((string) $item, '"\\').'"', array_values($value ?? []));

        return '{'.implode(',', $items).'}';
    }
}
