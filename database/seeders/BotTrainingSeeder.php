<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProductBotTrainingRule;
use App\Support\ProductPackageTypes;
use Illuminate\Database\Seeder;

/**
 * Правила обучения Product Bot: типовые габариты (мм) для розничных SKU BY/RU.
 *
 * php artisan db:seed --class=BotTrainingSeeder
 */
class BotTrainingSeeder extends Seeder
{
    public function run(): void
    {
        $rules = array_merge(
            $this->brandRules(),
            $this->genericPackageRules(),
        );

        $created = 0;
        $updated = 0;

        foreach ($rules as $rule) {
            $confidence = (float) ($rule['confidence'] ?? 0.9);
            // Выше confidence → раньше в матчинге (меньше priority).
            $priority = (int) ($rule['priority'] ?? max(1, round((1 - $confidence) * 1000)));

            $name = $rule['name'] ?? sprintf(
                '%s · %s · %d–%d мл',
                $rule['label'] ?? ($rule['keyword'] ?? 'Общее'),
                $rule['package_type'],
                $rule['volume_ml_min'],
                $rule['volume_ml_max'],
            );

            $model = ProductBotTrainingRule::query()->updateOrCreate(
                [
                    'keyword' => $rule['keyword'],
                    'package_type' => $rule['package_type'],
                    'volume_ml_min' => $rule['volume_ml_min'],
                    'volume_ml_max' => $rule['volume_ml_max'],
                ],
                [
                    'name' => $name,
                    'width_mm' => $rule['width_mm'],
                    'height_mm' => $rule['height_mm'],
                    'depth_mm' => $rule['depth_mm'],
                    'priority' => $priority,
                    'is_active' => true,
                ],
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        $this->command?->info("Bot training rules: created {$created}, updated {$updated} (всего ".count($rules).').');
    }

    /**
     * Брендовые / категорийные правила (высокий приоритет).
     *
     * @return list<array<string, mixed>>
     */
    private function brandRules(): array
    {
        $out = [];

        // keyword, label, rows: [min, max, package, w, h, d, confidence]
        $groups = [
            'пиво' => [
                'label' => 'Пиво',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.95],
                    [450, 550, ProductPackageTypes::CAN, 70, 170, 70, 0.95],
                    [400, 550, ProductPackageTypes::BOTTLE, 70, 250, 70, 0.93],
                    [1000, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.93],
                    [1900, 2100, ProductPackageTypes::PET, 100, 340, 100, 0.91],
                ],
            ],
            'кола' => [
                'label' => 'Газированные напитки',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.98],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.96],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.96],
                    [1400, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.96],
                    [1900, 2100, ProductPackageTypes::PET, 100, 350, 100, 0.94],
                ],
            ],
            'coca' => [
                'label' => 'Coca-Cola',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.98],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.96],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.96],
                    [1400, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.96],
                    [1900, 2100, ProductPackageTypes::PET, 100, 350, 100, 0.94],
                ],
            ],
            'пепси' => [
                'label' => 'Pepsi',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.97],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.95],
                    [900, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.95],
                    [1900, 2100, ProductPackageTypes::PET, 100, 350, 100, 0.93],
                ],
            ],
            'pepsi' => [
                'label' => 'Pepsi',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.97],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.95],
                    [900, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.95],
                ],
            ],
            'фанта' => [
                'label' => 'Газированные напитки',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.97],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.95],
                    [900, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.95],
                ],
            ],
            'fanta' => [
                'label' => 'Газированные напитки',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.97],
                    [900, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.95],
                ],
            ],
            'спрайт' => [
                'label' => 'Газированные напитки',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.97],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.95],
                    [900, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.95],
                ],
            ],
            'sprite' => [
                'label' => 'Газированные напитки',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.97],
                    [900, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.95],
                ],
            ],
            'энергетик' => [
                'label' => 'Энергетические напитки',
                'rows' => [
                    [240, 260, ProductPackageTypes::CAN, 53, 135, 53, 0.95],
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.95],
                    [440, 550, ProductPackageTypes::CAN, 70, 170, 70, 0.94],
                    [400, 550, ProductPackageTypes::PET, 70, 230, 70, 0.93],
                ],
            ],
            'red bull' => [
                'label' => 'Энергетические напитки',
                'rows' => [
                    [240, 260, ProductPackageTypes::CAN, 53, 135, 53, 0.97],
                    [350, 360, ProductPackageTypes::CAN, 58, 168, 58, 0.96],
                    [470, 500, ProductPackageTypes::CAN, 66, 168, 66, 0.95],
                ],
            ],
            'redbull' => [
                'label' => 'Энергетические напитки',
                'rows' => [
                    [240, 260, ProductPackageTypes::CAN, 53, 135, 53, 0.97],
                    [350, 360, ProductPackageTypes::CAN, 58, 168, 58, 0.96],
                ],
            ],
            'burn' => [
                'label' => 'Энергетические напитки',
                'rows' => [
                    [240, 260, ProductPackageTypes::CAN, 53, 135, 53, 0.95],
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.95],
                    [440, 500, ProductPackageTypes::CAN, 70, 170, 70, 0.94],
                ],
            ],
            'вода' => [
                'label' => 'Вода',
                'rows' => [
                    [300, 350, ProductPackageTypes::PET, 65, 200, 65, 0.95],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.96],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.96],
                    [1400, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.96],
                    [1900, 2100, ProductPackageTypes::PET, 100, 350, 100, 0.94],
                    [4500, 5500, ProductPackageTypes::PET, 150, 400, 150, 0.92],
                ],
            ],
            'минерал' => [
                'label' => 'Минеральная вода',
                'rows' => [
                    [300, 350, ProductPackageTypes::PET, 65, 200, 65, 0.94],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.95],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.95],
                    [1400, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.95],
                    [1900, 2100, ProductPackageTypes::PET, 100, 350, 100, 0.93],
                ],
            ],
            'минск' => [
                'label' => 'Вода Минская',
                'rows' => [
                    [300, 350, ProductPackageTypes::PET, 65, 200, 65, 0.95],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.95],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.95],
                    [1400, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.95],
                    [1900, 2100, ProductPackageTypes::PET, 100, 350, 100, 0.93],
                ],
            ],
            'квас' => [
                'label' => 'Квас',
                'rows' => [
                    [400, 550, ProductPackageTypes::PET, 70, 230, 70, 0.94],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.94],
                    [1350, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.94],
                    [1900, 2100, ProductPackageTypes::PET, 100, 350, 100, 0.93],
                ],
            ],
            'сок' => [
                'label' => 'Соки',
                'rows' => [
                    [150, 250, ProductPackageTypes::BOX, 40, 150, 40, 0.93],
                    [450, 550, ProductPackageTypes::BOX, 70, 180, 70, 0.93],
                    [900, 1100, ProductPackageTypes::BOX, 70, 200, 70, 0.92],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.90],
                    [1800, 2100, ProductPackageTypes::BOX, 95, 240, 70, 0.90],
                ],
            ],
            'нектар' => [
                'label' => 'Соки / нектары',
                'rows' => [
                    [150, 250, ProductPackageTypes::BOX, 40, 150, 40, 0.92],
                    [450, 550, ProductPackageTypes::BOX, 70, 180, 70, 0.92],
                    [900, 1100, ProductPackageTypes::BOX, 70, 200, 70, 0.91],
                ],
            ],
            'лимонад' => [
                'label' => 'Газированные напитки',
                'rows' => [
                    [300, 350, ProductPackageTypes::CAN, 66, 115, 66, 0.92],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.92],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.92],
                    [1400, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.91],
                ],
            ],
            'чай' => [
                'label' => 'Холодный чай',
                'rows' => [
                    [300, 350, ProductPackageTypes::PET, 65, 200, 65, 0.91],
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.92],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.91],
                    [1400, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.90],
                ],
            ],
            'icetea' => [
                'label' => 'Холодный чай',
                'rows' => [
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.92],
                    [900, 1600, ProductPackageTypes::PET, 90, 320, 90, 0.91],
                ],
            ],
            'молоко' => [
                'label' => 'Молоко',
                'rows' => [
                    [200, 250, ProductPackageTypes::BOX, 50, 110, 40, 0.93],
                    [450, 550, ProductPackageTypes::BOX, 60, 150, 50, 0.94],
                    [900, 1100, ProductPackageTypes::BOX, 70, 200, 70, 0.94],
                    [900, 1100, ProductPackageTypes::PET, 80, 260, 80, 0.91],
                ],
            ],
            'кефир' => [
                'label' => 'Кисломолочные',
                'rows' => [
                    [200, 250, ProductPackageTypes::BOX, 50, 110, 40, 0.92],
                    [450, 550, ProductPackageTypes::BOX, 60, 150, 50, 0.93],
                    [900, 1100, ProductPackageTypes::BOX, 70, 200, 70, 0.93],
                    [900, 1100, ProductPackageTypes::PET, 80, 260, 80, 0.90],
                ],
            ],
            'йогурт' => [
                'label' => 'Йогурт',
                'rows' => [
                    [100, 150, ProductPackageTypes::BAG, 70, 100, 35, 0.90],
                    [200, 300, ProductPackageTypes::BAG, 80, 120, 40, 0.90],
                    [400, 550, ProductPackageTypes::BOTTLE, 70, 180, 70, 0.91],
                    [800, 1000, ProductPackageTypes::BOTTLE, 80, 230, 80, 0.90],
                ],
            ],
            'сметана' => [
                'label' => 'Сметана',
                'rows' => [
                    [150, 250, ProductPackageTypes::BAG, 80, 90, 45, 0.90],
                    [300, 450, ProductPackageTypes::BAG, 95, 110, 50, 0.90],
                ],
            ],
            'масло' => [
                'label' => 'Масло растительное',
                'rows' => [
                    [450, 550, ProductPackageTypes::PET, 70, 230, 70, 0.91],
                    [900, 1100, ProductPackageTypes::PET, 80, 280, 80, 0.92],
                    [1800, 2100, ProductPackageTypes::PET, 100, 320, 100, 0.90],
                ],
            ],
            'вино' => [
                'label' => 'Вино',
                'rows' => [
                    [700, 800, ProductPackageTypes::BOTTLE, 75, 300, 75, 0.93],
                    [1800, 2100, ProductPackageTypes::BOX, 100, 250, 80, 0.88],
                ],
            ],
            'водка' => [
                'label' => 'Крепкий алкоголь',
                'rows' => [
                    [450, 550, ProductPackageTypes::BOTTLE, 70, 240, 70, 0.93],
                    [700, 800, ProductPackageTypes::BOTTLE, 75, 280, 75, 0.93],
                    [900, 1100, ProductPackageTypes::BOTTLE, 80, 300, 80, 0.92],
                ],
            ],
            'чипс' => [
                'label' => 'Снеки',
                'rows' => [
                    [40, 80, ProductPackageTypes::BAG, 120, 180, 40, 0.88],
                    [80, 150, ProductPackageTypes::BAG, 140, 220, 50, 0.88],
                    [150, 250, ProductPackageTypes::BAG, 160, 260, 60, 0.87],
                ],
            ],
        ];

        foreach ($groups as $keyword => $group) {
            foreach ($group['rows'] as $row) {
                [$min, $max, $package, $w, $h, $d, $confidence] = $row;
                $out[] = [
                    'keyword' => $keyword,
                    'label' => $group['label'],
                    'package_type' => $package,
                    'volume_ml_min' => $min,
                    'volume_ml_max' => $max,
                    'width_mm' => $w,
                    'height_mm' => $h,
                    'depth_mm' => $d,
                    'confidence' => $confidence,
                ];
            }
        }

        return $out;
    }

    /**
     * Универсальные правила без keyword: срабатывают, когда OFF/эвристика уже
     * дали package_type + volume, но бренд в названии не распознан.
     * Priority выше (хуже), чем у brandRules — сначала матчится бренд.
     *
     * @return list<array<string, mixed>>
     */
    private function genericPackageRules(): array
    {
        $sets = [
            ProductPackageTypes::CAN => [
                [240, 260, 53, 134, 53],
                [300, 350, 66, 115, 66],
                [440, 500, 66, 168, 66],
                [500, 550, 70, 168, 70],
                [700, 800, 73, 190, 73],
            ],
            ProductPackageTypes::BOTTLE => [
                [250, 350, 60, 200, 60],
                [400, 550, 70, 230, 70],
                [700, 800, 75, 280, 75],
                [900, 1100, 80, 300, 80],
            ],
            ProductPackageTypes::PET => [
                [300, 350, 65, 200, 65],
                [450, 550, 70, 230, 70],
                [900, 1100, 80, 280, 80],
                [1400, 1600, 90, 320, 90],
                [1900, 2100, 100, 340, 100],
                [4500, 5500, 150, 400, 150],
            ],
            ProductPackageTypes::BOX => [
                [150, 250, 50, 110, 40],
                [450, 550, 60, 150, 50],
                [900, 1100, 70, 200, 70],
                [1800, 2100, 95, 240, 70],
            ],
            ProductPackageTypes::BAG => [
                [50, 120, 100, 150, 35],
                [150, 300, 120, 200, 45],
                [400, 600, 140, 240, 55],
            ],
        ];

        $out = [];
        foreach ($sets as $package => $rows) {
            foreach ($rows as $row) {
                [$min, $max, $w, $h, $d] = $row;
                $out[] = [
                    'keyword' => null,
                    'name' => sprintf('Общее · %s · %d–%d мл', $package, $min, $max),
                    'package_type' => $package,
                    'volume_ml_min' => $min,
                    'volume_ml_max' => $max,
                    'width_mm' => $w,
                    'height_mm' => $h,
                    'depth_mm' => $d,
                    'confidence' => 0.82,
                    // После брендовых правил (priority ~20–100).
                    'priority' => 250,
                ];
            }
        }

        return $out;
    }
}
