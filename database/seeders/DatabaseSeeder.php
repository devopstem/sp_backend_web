<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->command?->info('Базовые сидеры: роли, админ, тарифы, legal, обучение Product Bot.');

        $this->call([
            RoleSeeder::class,
            SuperAdminSeeder::class,
            PlanSeeder::class,
            LegalDocumentSeeder::class,
            BotTrainingSeeder::class,
        ]);
    }
}
