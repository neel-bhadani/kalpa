<?php

namespace Database\Seeders;

use App\Models\RegisteredModel;
use Illuminate\Database\Seeder;

/**
 * Fills model_registry from config/models.php (safe to run again: rows are updated by key).
 * Dev-only models (the fake brain) are left out outside local and testing.
 */
class ModelRegistrySeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('models') as $key => $model) {
            if (($model['dev_only'] ?? false) && ! app()->environment('local', 'testing')) {
                continue;
            }

            unset($model['dev_only']);

            RegisteredModel::updateOrCreate(['key' => $key], [...$model, 'enabled' => $model['enabled'] ?? false]);
        }
    }
}
