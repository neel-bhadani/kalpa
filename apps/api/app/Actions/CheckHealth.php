<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CheckHealth
{
    /**
     * Report whether the app, the database and Redis are reachable.
     *
     * @return array{app: string, db: string, redis: string}
     */
    public function handle(): array
    {
        return [
            'app' => 'ok',
            'db' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
        ];
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }
}
