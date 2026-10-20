<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBotJob;
use App\Models\ProductBotTrainingRule;
use App\Support\ProductPackageTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProductBotService
{
    public function __construct(
        private readonly OpenFoodFactsService $openFoodFacts,
    ) {}

    /**
     * Scan incomplete products and create review jobs.
     *
     * @return array{scanned: int, created: int, skipped: int}
     */
    public function scan(int $limit = 50): array
    {
        $products = $this->incompleteProductsQuery()
            ->whereDoesntHave('botJobs', function ($query): void {
                $query->where('status', ProductBotJob::STATUS_PENDING);
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $job = $this->enrichProduct($product);

            if ($job === null) {
                $skipped++;

                continue;
            }

            $created++;
        }

        return [
            'scanned' => $products->count(),
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    public function enrichProduct(Product $product): ?ProductBotJob
    {
        $hasPending = ProductBotJob::query()
            ->where('product_id', $product->id)
            ->where('status', ProductBotJob::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            return null;
        }

        $suggestion = $this->buildSuggestion($product);

        if ($suggestion === null) {
            return null;
        }

        return ProductBotJob::query()->create([
            'product_id' => $product->id,
            'status' => ProductBotJob::STATUS_PENDING,
            ...$suggestion,
        ]);
    }

    /**
     * @return array{
     *     suggested_name: string|null,
     *     suggested_category: string|null,
     *     suggested_package_type: string|null,
     *     suggested_width_mm: int|null,
     *     suggested_height_mm: int|null,
     *     suggested_depth_mm: int|null,
     *     suggested_volume_ml: int|null,
     *     confidence: int,
     *     source: string,
     *     reason: string
     * }|null
     */
    public function buildSuggestion(Product $product): ?array
    {
        $reasons = [];
        $confidence = 0;
        $source = 'heuristic';

        $name = $product->name;
        $category = $product->category;
        $packageType = ProductPackageTypes::normalize($product->package_type);
        $volumeMl = $product->volume_ml;
        $width = $product->width_mm;
        $height = $product->height_mm;
        $depth = $product->depth_mm;

        $off = null;
        try {
            $off = $this->openFoodFacts->lookupProduct(
                $product->barcode ? (string) $product->barcode : null,
                $product->name ? (string) $product->name : null,
                $product->volume_ml ? (int) $product->volume_ml : null,
            );
        } catch (\Throwable $exception) {
            Log::warning('Product bot OFF lookup failed', [
                'product_id' => $product->id,
                'message' => $exception->getMessage(),
            ]);
        }

        if (is_array($off) && ($off['found'] ?? false)) {
            $matchBy = (string) ($off['match_by'] ?? 'barcode');
            $source = 'openfoodfacts';
            $confidence += $matchBy === 'name' ? 18 : 25;

            $reasons[] = match ($matchBy) {
                'name' => 'Найден в Open Food Facts по названию',
                'barcode+name' => 'Найден в OFF по штрихкоду + дополнен по названию',
                default => 'Найден в Open Food Facts по штрихкоду',
            };

            if (! $name && ! empty($off['name'])) {
                $name = $off['name'];
                $confidence += 10;
            }

            if (! $category && ! empty($off['category'])) {
                $category = $off['category'];
                $confidence += 5;
            }

            if (! $volumeMl && ! empty($off['volume_ml'])) {
                $volumeMl = (int) $off['volume_ml'];
                $confidence += 15;
                $reasons[] = 'Объём из OFF';
            }

            $offPackage = ProductPackageTypes::normalize($off['package_type'] ?? null);
            if (! $packageType && $offPackage) {
                $packageType = $offPackage;
                $confidence += 20;
                $reasons[] = 'Тип упаковки из OFF → '.$offPackage;
            }
        }

        $rule = $this->matchTrainingRule($name, $category, $volumeMl, $packageType);
        $dimsFromTraining = false;
        if ($rule) {
            $source = $source === 'openfoodfacts' ? 'off+training' : 'training';
            $confidence += 30;
            $reasons[] = 'Правило обучения: '.$rule->name;

            if (! $packageType && $rule->package_type) {
                $packageType = ProductPackageTypes::normalize($rule->package_type) ?? $rule->package_type;
            }

            if (! $width && $rule->width_mm) {
                $width = $rule->width_mm;
                $dimsFromTraining = true;
            }
            if (! $height && $rule->height_mm) {
                $height = $rule->height_mm;
                $dimsFromTraining = true;
            }
            if (! $depth && $rule->depth_mm) {
                $depth = $rule->depth_mm;
                $dimsFromTraining = true;
            }

            if ($dimsFromTraining) {
                $confidence += 20;
                $reasons[] = 'Габариты из правила обучения (предпочтительнее оценки)';
            }
        }

        if (! $packageType && $volumeMl) {
            $guessed = $this->guessPackageTypeFromContext($name, $category, $volumeMl);
            if ($guessed) {
                $packageType = $guessed;
                $confidence += 10;
                $reasons[] = 'Тип упаковки по эвристике: '.$guessed;
            }
        }

        if ((! $width || ! $height || ! $depth) && $packageType && $volumeMl) {
            [$estW, $estH, $estD, $exactVolume] = $this->estimateDimensions($packageType, $volumeMl);
            if ($estW && ! $width) {
                $width = $estW;
            }
            if ($estH && ! $height) {
                $height = $estH;
            }
            if ($estD && ! $depth) {
                $depth = $estD;
            }
            if ($estW || $estH || $estD) {
                $confidence += $exactVolume ? 22 : 12;
                $reasons[] = $exactVolume
                    ? 'Габариты по типовой таблице для '.$volumeMl.' мл / '.$packageType
                    : 'Габариты оценены по ближайшему объёму и типу упаковки';
            }
        }

        $fillsGap = (
            ($packageType && ! $product->package_type)
            || ($volumeMl && ! $product->volume_ml)
            || ($width && ! $product->width_mm)
            || ($height && ! $product->height_mm)
            || ($depth && ! $product->depth_mm)
            || ($name && $name !== $product->name && (! $product->name || trim((string) $product->name) === ''))
            || ($category && ! $product->category)
        );

        if (! $fillsGap) {
            return null;
        }

        $confidence = max(5, min(95, $confidence));

        if ($confidence < 35) {
            $reasons[] = 'Низкая уверенность — нужна ручная проверка';
        }

        return [
            'suggested_name' => $name !== $product->name ? $name : null,
            'suggested_category' => $category && $category !== $product->category ? $category : ($product->category ? null : $category),
            'suggested_package_type' => $packageType && $packageType !== $product->package_type ? $packageType : null,
            'suggested_width_mm' => $width && $width !== $product->width_mm ? $width : null,
            'suggested_height_mm' => $height && $height !== $product->height_mm ? $height : null,
            'suggested_depth_mm' => $depth && $depth !== $product->depth_mm ? $depth : null,
            'suggested_volume_ml' => $volumeMl && $volumeMl !== $product->volume_ml ? $volumeMl : null,
            'confidence' => $confidence,
            'source' => $source,
            'reason' => implode('; ', $reasons) ?: 'Предложение по эвристике',
        ];
    }

    /**
     * @param  array{
     *     name?: string|null,
     *     category?: string|null,
     *     package_type?: string|null,
     *     width_mm?: int|null,
     *     height_mm?: int|null,
     *     depth_mm?: int|null,
     *     volume_ml?: int|null,
     *     mark_checked?: bool,
     *     save_as_training?: bool,
     *     training_name?: string|null,
     *     training_keyword?: string|null
     * }  $overrides
     */
    public function accept(ProductBotJob $job, array $overrides, string $userId): Product
    {
        if (! $job->isPending()) {
            throw new \InvalidArgumentException('Задание уже обработано.');
        }

        return DB::transaction(function () use ($job, $overrides, $userId): Product {
            /** @var Product $product */
            $product = Product::query()->lockForUpdate()->findOrFail($job->product_id);

            $packageType = ProductPackageTypes::normalize(
                array_key_exists('package_type', $overrides)
                    ? ($overrides['package_type'] ?: null)
                    : $job->suggested_package_type
            );

            if ($packageType !== null && ! ProductPackageTypes::isValid($packageType)) {
                throw new \InvalidArgumentException('Недопустимый тип упаковки.');
            }

            $payload = array_filter([
                'name' => array_key_exists('name', $overrides)
                    ? ($overrides['name'] ?: null)
                    : $job->suggested_name,
                'category' => array_key_exists('category', $overrides)
                    ? ($overrides['category'] ?: null)
                    : $job->suggested_category,
                'package_type' => $packageType,
                'width_mm' => array_key_exists('width_mm', $overrides)
                    ? $this->nullableInt($overrides['width_mm'] ?? null)
                    : $job->suggested_width_mm,
                'height_mm' => array_key_exists('height_mm', $overrides)
                    ? $this->nullableInt($overrides['height_mm'] ?? null)
                    : $job->suggested_height_mm,
                'depth_mm' => array_key_exists('depth_mm', $overrides)
                    ? $this->nullableInt($overrides['depth_mm'] ?? null)
                    : $job->suggested_depth_mm,
                'volume_ml' => array_key_exists('volume_ml', $overrides)
                    ? $this->nullableInt($overrides['volume_ml'] ?? null)
                    : $job->suggested_volume_ml,
            ], static fn ($value) => $value !== null && $value !== '');

            if (! empty($overrides['mark_checked'])) {
                $payload['checked'] = true;
            }

            if ($payload !== []) {
                $product->fill($payload);
                $product->save();
            }

            $job->forceFill([
                'status' => ProductBotJob::STATUS_ACCEPTED,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'suggested_name' => $payload['name'] ?? $job->suggested_name,
                'suggested_category' => $payload['category'] ?? $job->suggested_category,
                'suggested_package_type' => $payload['package_type'] ?? $job->suggested_package_type,
                'suggested_width_mm' => $payload['width_mm'] ?? $job->suggested_width_mm,
                'suggested_height_mm' => $payload['height_mm'] ?? $job->suggested_height_mm,
                'suggested_depth_mm' => $payload['depth_mm'] ?? $job->suggested_depth_mm,
                'suggested_volume_ml' => $payload['volume_ml'] ?? $job->suggested_volume_ml,
            ])->save();

            if (! empty($overrides['save_as_training']) && ($payload['package_type'] ?? null)) {
                $this->createTrainingRuleFromAccept($product, $payload, $userId, $overrides);
            }

            return $product->fresh();
        });
    }

    public function reject(ProductBotJob $job, string $userId): void
    {
        if (! $job->isPending()) {
            throw new \InvalidArgumentException('Задание уже обработано.');
        }

        $job->forceFill([
            'status' => ProductBotJob::STATUS_REJECTED,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeTrainingRule(array $data, string $userId): ProductBotTrainingRule
    {
        $packageType = ProductPackageTypes::normalize($data['package_type'] ?? null);
        if ($packageType === null || ! ProductPackageTypes::isValid($packageType)) {
            throw new \InvalidArgumentException('Укажите корректный тип упаковки.');
        }

        return ProductBotTrainingRule::query()->create([
            'name' => trim((string) ($data['name'] ?? 'Правило')),
            'keyword' => ($kw = trim((string) ($data['keyword'] ?? ''))) !== '' ? $kw : null,
            'volume_ml_min' => $this->nullableInt($data['volume_ml_min'] ?? null),
            'volume_ml_max' => $this->nullableInt($data['volume_ml_max'] ?? null),
            'package_type' => $packageType,
            'width_mm' => $this->nullableInt($data['width_mm'] ?? null),
            'height_mm' => $this->nullableInt($data['height_mm'] ?? null),
            'depth_mm' => $this->nullableInt($data['depth_mm'] ?? null),
            'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $userId,
        ]);
    }

    public function deleteTrainingRule(ProductBotTrainingRule $rule): void
    {
        $rule->delete();
    }

    /**
     * @return array{pending: int, accepted: int, rejected: int, incomplete_products: int, training_rules: int}
     */
    public function stats(): array
    {
        return [
            'pending' => ProductBotJob::query()->where('status', ProductBotJob::STATUS_PENDING)->count(),
            'accepted' => ProductBotJob::query()->where('status', ProductBotJob::STATUS_ACCEPTED)->count(),
            'rejected' => ProductBotJob::query()->where('status', ProductBotJob::STATUS_REJECTED)->count(),
            'incomplete_products' => $this->incompleteProductsQuery()->count(),
            'training_rules' => ProductBotTrainingRule::query()->where('is_active', true)->count(),
        ];
    }

    private function incompleteProductsQuery()
    {
        return Product::query()
            ->where(function ($query): void {
                $query->whereNull('package_type')
                    ->orWhere('package_type', '')
                    ->orWhereNull('width_mm')
                    ->orWhereNull('height_mm')
                    ->orWhereNull('depth_mm')
                    ->orWhereNull('volume_ml')
                    ->orWhere('checked', false);
            });
    }

    private function matchTrainingRule(
        ?string $name,
        ?string $category,
        ?int $volumeMl,
        ?string $packageType,
    ): ?ProductBotTrainingRule {
        /** @var Collection<int, ProductBotTrainingRule> $rules */
        $rules = ProductBotTrainingRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();

        $haystack = mb_strtolower(trim(($name ?? '').' '.($category ?? '')));

        foreach ($rules as $rule) {
            if ($volumeMl !== null) {
                if ($rule->volume_ml_min !== null && $volumeMl < $rule->volume_ml_min) {
                    continue;
                }
                if ($rule->volume_ml_max !== null && $volumeMl > $rule->volume_ml_max) {
                    continue;
                }
            } elseif ($rule->volume_ml_min !== null || $rule->volume_ml_max !== null) {
                continue;
            }

            if ($rule->keyword) {
                $keyword = mb_strtolower(trim($rule->keyword));
                if ($keyword === '' || ! str_contains($haystack, $keyword)) {
                    continue;
                }
            }

            if ($packageType && $rule->package_type && $packageType !== $rule->package_type && $rule->keyword === null) {
                // volume-only rule for a specific package: skip if package already differs
                continue;
            }

            return $rule;
        }

        return null;
    }

    private function guessPackageTypeFromContext(?string $name, ?string $category, int $volumeMl): ?string
    {
        $haystack = mb_strtolower(($name ?? '').' '.($category ?? ''));

        if (str_contains($haystack, 'банк') || str_contains($haystack, 'can')) {
            return ProductPackageTypes::CAN;
        }
        if (str_contains($haystack, 'пэт') || str_contains($haystack, 'pet')) {
            return ProductPackageTypes::PET;
        }
        if (str_contains($haystack, 'пакет') || str_contains($haystack, 'sachet')) {
            return ProductPackageTypes::BAG;
        }
        if (str_contains($haystack, 'короб') || str_contains($haystack, 'тетра') || str_contains($haystack, 'carton')) {
            return ProductPackageTypes::BOX;
        }
        if (str_contains($haystack, 'бутыл') || str_contains($haystack, 'bottle')) {
            return ProductPackageTypes::BOTTLE;
        }

        if ($volumeMl >= 250 && $volumeMl <= 550) {
            return ProductPackageTypes::CAN;
        }
        if ($volumeMl >= 900) {
            return ProductPackageTypes::PET;
        }

        return null;
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: int|null, 3: bool}
     *         width, height, depth, exactVolumeMatch
     */
    private function estimateDimensions(string $packageType, int $volumeMl): array
    {
        // Typical retail shelf footprints (W×H×D mm) for CIS beverages / dairy.
        $table = match ($packageType) {
            ProductPackageTypes::CAN => [
                [250, 53, 134, 53],
                [330, 66, 115, 66],
                [450, 66, 155, 66],
                [500, 66, 168, 66],
                [568, 73, 168, 73],
                [750, 73, 190, 73],
                [1000, 84, 175, 84],
            ],
            ProductPackageTypes::BOTTLE => [
                [250, 55, 180, 55],
                [330, 60, 200, 60],
                [450, 65, 220, 65],
                [500, 70, 230, 70],
                [750, 75, 280, 75],
                [1000, 80, 300, 80],
            ],
            ProductPackageTypes::PET => [
                [250, 55, 180, 55],
                [330, 60, 195, 60],
                [500, 65, 220, 65],
                [750, 72, 250, 72],
                [1000, 80, 280, 80],
                [1500, 90, 320, 90],
                [2000, 100, 340, 100],
            ],
            ProductPackageTypes::BOX => [
                [200, 50, 110, 40],
                [250, 52, 120, 42],
                [500, 60, 150, 50],
                [750, 65, 175, 60],
                [1000, 70, 200, 70],
            ],
            ProductPackageTypes::BAG => [
                [100, 80, 120, 30],
                [200, 90, 140, 35],
                [250, 100, 160, 40],
                [500, 120, 200, 50],
                [1000, 140, 250, 60],
            ],
            default => [
                [330, 66, 115, 66],
                [500, 70, 200, 70],
            ],
        };

        $best = $table[0];
        $bestDiff = PHP_INT_MAX;
        foreach ($table as $row) {
            $diff = abs($row[0] - $volumeMl);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $row;
            }
        }

        $exact = $bestDiff <= 20;

        return [$best[1], $best[2], $best[3], $exact];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     */
    private function createTrainingRuleFromAccept(
        Product $product,
        array $payload,
        string $userId,
        array $overrides,
    ): void {
        $keyword = trim((string) ($overrides['training_keyword'] ?? ''));
        if ($keyword === '' && $product->category) {
            $keyword = mb_strtolower((string) $product->category);
        }

        $volume = $payload['volume_ml'] ?? $product->volume_ml;
        $tolerance = 50;

        ProductBotTrainingRule::query()->create([
            'name' => trim((string) ($overrides['training_name'] ?? ''))
                ?: ('Из принятия: '.($product->name ?: $product->barcode)),
            'keyword' => $keyword !== '' ? $keyword : null,
            'volume_ml_min' => $volume ? max(0, $volume - $tolerance) : null,
            'volume_ml_max' => $volume ? $volume + $tolerance : null,
            'package_type' => $payload['package_type'],
            'width_mm' => $payload['width_mm'] ?? null,
            'height_mm' => $payload['height_mm'] ?? null,
            'depth_mm' => $payload['depth_mm'] ?? null,
            'priority' => 50,
            'is_active' => true,
            'created_by' => $userId,
        ]);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
