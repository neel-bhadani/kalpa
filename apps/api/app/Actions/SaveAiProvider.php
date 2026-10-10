<?php

namespace App\Actions;

use App\Models\AiProviderConfig;
use App\Models\User;

class SaveAiProvider
{
    /**
     * Add or change one of the user's providers. An empty api_key on update keeps the current key.
     *
     * @param  array{provider?: string, name?: string, base_url?: string, api_key?: ?string, models?: list<string>, enabled?: bool}  $data
     */
    public function handle(User $user, array $data, ?AiProviderConfig $provider = null): AiProviderConfig
    {
        if ($provider !== null && array_key_exists('api_key', $data) && blank($data['api_key'])) {
            unset($data['api_key']);
        }

        $provider ??= new AiProviderConfig(['user_id' => $user->id]);
        $provider->fill($data)->save();

        return $provider;
    }
}
