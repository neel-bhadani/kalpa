<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makes a table append-only: rows can be inserted and read, never changed or removed.
 * Use inside a migration, after the table is created.
 */
class AppendOnly
{
    public static function apply(string $table): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("Invalid table name [{$table}].");
        }

        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_block_update_delete
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION kalpa_block_mutation();

            CREATE TRIGGER {$table}_block_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION kalpa_block_mutation();
            SQL);
    }
}
