<?php

namespace Database\Seeders;

use App\Models\DocumentType;
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

        $this->call(AccessControlSeeder::class);

        foreach (['Ofício', 'Contrato', 'Relatório', 'Requerimento', 'Correspondência', 'Comprovante', 'Outros'] as $name) {
            DocumentType::firstOrCreate(['name' => $name]);
        }
    }
}
