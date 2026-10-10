<?php

namespace App\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CreatePairingCode
{
    // No 0/O, 1/I/L: easy to read and type on another machine.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * A short one-time code (e.g. "K7PM-2XQD") a local worker exchanges for its token.
     *
     * @return array{code: string, expires_at: Carbon}
     */
    public function handle(User $user, Workspace $workspace): array
    {
        $code = $this->randomPart().'-'.$this->randomPart();
        $expires = now()->addMinutes(config('jobs.pairing_code_minutes'));

        Cache::put(self::cacheKey($code), ['user_id' => $user->id, 'workspace_id' => $workspace->id], $expires);

        return ['code' => $code, 'expires_at' => $expires];
    }

    public static function cacheKey(string $code): string
    {
        return 'worker-pairing:'.strtoupper(trim($code));
    }

    private function randomPart(): string
    {
        $part = '';
        for ($i = 0; $i < 4; $i++) {
            $part .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $part;
    }
}
