<?php

namespace Database\Seeders;

use App\Models\Cupon;
use App\Models\User;
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
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Cupon::query()->updateOrCreate(
            ['codigo' => 'DESC10'],
            [
                'tipo' => 'porcentaje',
                'valor' => 10,
                'activo' => true,
                'caduca_en' => null,
                'max_usos' => null,
                'usos_actuales' => 0,
            ]
        );

        Cupon::query()->updateOrCreate(
            ['codigo' => '5USD'],
            [
                'tipo' => 'fijo',
                'valor' => 5,
                'activo' => true,
                'caduca_en' => null,
                'max_usos' => null,
                'usos_actuales' => 0,
            ]
        );
    }
}
