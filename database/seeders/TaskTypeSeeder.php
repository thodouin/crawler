<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TaskType;

class TaskTypeSeeder extends Seeder
{
    public function run(): void
    {
        TaskType::firstOrCreate(
            ['slug' => 'crawl'],
            [
                'name' => 'Crawler le site complet',
                'description' => 'Lance un crawl complet du site, en suivant les liens internes.',
                'is_active' => true,
            ]
        );

        TaskType::firstOrCreate(
            ['slug' => 'check_existence'],
            [
                'name' => 'Vérifier l\'existence du site',
                'description' => 'Effectue une simple requête pour vérifier que le site répond et existe.',
                'is_active' => true,
            ]
        );
    }
}