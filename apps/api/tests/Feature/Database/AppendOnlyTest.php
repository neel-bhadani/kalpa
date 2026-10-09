<?php

use App\Core\Database\AppendOnly;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A throwaway table; RefreshDatabase rolls it back after each test.
beforeEach(function () {
    Schema::create('test_append_only', function (Blueprint $table) {
        $table->id();
        $table->string('note');
    });

    AppendOnly::apply('test_append_only');

    DB::table('test_append_only')->insert(['note' => 'first']);
});

// Each attempt runs in a savepoint, so the failed statement doesn't abort the test transaction.
function attempt(callable $statement): void
{
    DB::transaction($statement);
}

it('allows inserts and reads', function () {
    DB::table('test_append_only')->insert(['note' => 'second']);

    expect(DB::table('test_append_only')->count())->toBe(2);
});

it('blocks update', function () {
    expect(fn () => attempt(fn () => DB::table('test_append_only')->update(['note' => 'changed'])))
        ->toThrow(QueryException::class, 'append-only: UPDATE is not allowed');
});

it('blocks delete', function () {
    expect(fn () => attempt(fn () => DB::table('test_append_only')->delete()))
        ->toThrow(QueryException::class, 'append-only: DELETE is not allowed');
});

it('blocks truncate', function () {
    expect(fn () => attempt(fn () => DB::statement('TRUNCATE test_append_only')))
        ->toThrow(QueryException::class, 'append-only: TRUNCATE is not allowed');

    expect(DB::table('test_append_only')->count())->toBe(1);
});
