<?php

namespace Database\Seeders;

use App\Actions\RegisterUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ModelRegistrySeeder::class);

        // Local test account, with its personal workspace.
        app(RegisterUser::class)->handle('Test User', 'test@example.com', 'password');
    }
}
