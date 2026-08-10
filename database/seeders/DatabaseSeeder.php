<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->withoutTwoFactor()->make([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ])->getAttributes()
        );

        $this->call([
            RoleSeeder::class,
            PointageDemoSeeder::class,
            PointageDeclarationDemoSeeder::class,
        ]);
    }
}
