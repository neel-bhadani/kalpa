<?php

namespace Database\Seeders;

use App\Core\DevTools;
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
            if (($model['dev_only'] ?? false) && ! DevTools::enabled()) {
                // Never left behind outside development, even if an earlier seed added it.
                RegisteredModel::where('key', $key)->delete();

                continue;
            }

            unset($model['dev_only']);

            RegisteredModel::updateOrCreate(['key' => $key], [...$model, 'enabled' => $model['enabled'] ?? false]);
        }
    }
}
