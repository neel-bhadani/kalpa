<?php

namespace App\Core\Database;

use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adds a CHECK constraint that allows only the values of a PHP backed enum,
 * so the enum and the database column can't drift apart.
 * Use inside a migration, after the table is created.
 */
class EnumCheck
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function add(string $table, string $column, string $enum): void
    {
        foreach ([$table, $column] as $name) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException("Invalid name [{$name}].");
            }
        }

        $values = collect($enum::cases())
            ->map(fn (BackedEnum $case) => DB::getPdo()->quote((string) $case->value))
            ->implode(', ');

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$values}))");
    }
}
