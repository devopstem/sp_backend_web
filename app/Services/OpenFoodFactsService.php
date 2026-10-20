<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ProductPackageTypes;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OpenFoodFactsService
{
    private const PRODUCT_PATH = '/api/v2/product/%s.json';

    private const SEARCH_PATH = '/cgi/search.pl';

    private const PRODUCT_FIELDS = 'code,product_name,product_name_ru,product_name_en,generic_name,brands,categories,categories_ru,categories_tags,quantity,product_quantity,product_quantity_unit,packaging,packaging_tags,packaging_text,image_front_small_url,image_small_url,image_url';

    /**
     * @return array{
     *     found: bool,
     *     barcode: string,
     *     name: string|null,
     *     category: string|null,
     *     brand: string|null,
     *     quantity: string|null,
     *     volume_ml: int|null,
     *     weight_g: int|null,
     *     package_type: string|null,
     *     image_url: string|null,
     *     source_url: string|null,
     *     match_by: string|null
     * }
     */
    public function lookup(string $barcode): array
    {
        $barcode = preg_replace('/\D+/', '', $barcode) ?? '';
        $empty = $this->emptyResult($barcode);

        if ($barcode === '' || ! preg_match('/^\d{8,14}$/', $barcode)) {
            return $empty;
        }

        $cacheKey = 'off:v2:barcode:'.$barcode;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            unset($cached['_miss']);

            return array_merge($empty, $cached);
        }

        $this->throttle();
        $mapped = null;

        foreach ($this->hosts() as $host) {
            try {
                $response = $this->http()
                    ->get($host.sprintf(self::PRODUCT_PATH, $barcode), [
                        'fields' => self::PRODUCT_FIELDS,
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $payload = $response->json();
                if (! is_array($payload) || (int) ($payload['status'] ?? 0) !== 1) {
                    continue;
                }

                $product = $payload['product'] ?? null;
                if (! is_array($product)) {
                    continue;
                }

                $mapped = $this->mapProduct($product, $barcode);
                $mapped['match_by'] = 'barcode';
                $mapped['source_url'] = rtrim($host, '/').'/product/'.$barcode;
                break;
            } catch (Throwable $exception) {
                Log::warning('Open Food Facts lookup failed', [
                    'host' => $host,
                    'barcode' => $barcode,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($mapped === null) {
            Cache::put($cacheKey, $empty + ['_miss' => true], $this->missCacheTtl());

            return $empty;
        }

        Cache::put($cacheKey, $mapped, $this->cacheTtl());

        return array_merge($empty, $mapped);
    }

    /**
     * Search OFF by product name (useful when CIS/US barcodes differ).
     *
     * @return array{
     *     found: bool,
     *     barcode: string,
     *     name: string|null,
     *     category: string|null,
     *     brand: string|null,
     *     quantity: string|null,
     *     volume_ml: int|null,
     *     weight_g: int|null,
     *     package_type: string|null,
     *     image_url: string|null,
     *     source_url: string|null,
     *     match_by: string|null
     * }
     */
    public function searchByName(string $query, ?int $preferVolumeMl = null): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        $empty = $this->emptyResult('');

        if (mb_strlen($query) < 3) {
            return $empty;
        }

        $cacheKey = 'off:v2:name:'.hash('sha256', mb_strtolower($query).'|'.($preferVolumeMl ?? ''));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            unset($cached['_miss']);

            return array_merge($empty, $cached);
        }

        $this->throttle();
        $mapped = null;

        foreach ($this->hosts() as $host) {
            try {
                $response = $this->http()
                    ->get($host.self::SEARCH_PATH, [
                        'search_terms' => $query,
                        'search_simple' => 1,
                        'action' => 'process',
                        'json' => 1,
                        'page_size' => 10,
                        'fields' => self::PRODUCT_FIELDS,
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $payload = $response->json();
                $products = is_array($payload) ? ($payload['products'] ?? []) : [];
                if (! is_array($products) || $products === []) {
                    continue;
                }

                $best = null;
                $bestScore = -1.0;

                foreach ($products as $product) {
                    if (! is_array($product)) {
                        continue;
                    }

                    $score = $this->scoreNameMatch($query, $product, $preferVolumeMl);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $product;
                    }
                }

                if ($best === null || $bestScore < 0.35) {
                    continue;
                }

                $code = preg_replace('/\D+/', '', (string) ($best['code'] ?? '')) ?? '';
                $mapped = $this->mapProduct($best, $code);
                $mapped['match_by'] = 'name';
                $mapped['found'] = $mapped['found']
                    || ($mapped['package_type'] !== null || $mapped['volume_ml'] !== null);
                if ($code !== '') {
                    $mapped['source_url'] = rtrim($host, '/').'/product/'.$code;
                }
                break;
            } catch (Throwable $exception) {
                Log::warning('Open Food Facts name search failed', [
                    'host' => $host,
                    'query' => $query,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($mapped === null) {
            Cache::put($cacheKey, $empty + ['_miss' => true], $this->missCacheTtl());

            return $empty;
        }

        Cache::put($cacheKey, $mapped, $this->cacheTtl());

        return array_merge($empty, $mapped);
    }

    /**
     * Barcode first, then name fallback / gap fill.
     *
     * @return array{
     *     found: bool,
     *     barcode: string,
     *     name: string|null,
     *     category: string|null,
     *     brand: string|null,
     *     quantity: string|null,
     *     volume_ml: int|null,
     *     weight_g: int|null,
     *     package_type: string|null,
     *     image_url: string|null,
     *     source_url: string|null,
     *     match_by: string|null
     * }
     */
    public function lookupProduct(?string $barcode, ?string $name, ?int $volumeMl = null): array
    {
        $byBarcode = $barcode ? $this->lookup($barcode) : $this->emptyResult('');

        $needsMore = ! ($byBarcode['found'] ?? false)
            || empty($byBarcode['package_type'])
            || empty($byBarcode['volume_ml']);

        if (! $needsMore || $name === null || trim($name) === '') {
            return $byBarcode;
        }

        $byName = $this->searchByName($name, $volumeMl ?? ($byBarcode['volume_ml'] ?? null));

        if (! ($byName['found'] ?? false)) {
            return $byBarcode;
        }

        if (! ($byBarcode['found'] ?? false)) {
            return $byName;
        }

        foreach (['name', 'category', 'brand', 'quantity', 'volume_ml', 'weight_g', 'package_type', 'image_url', 'source_url'] as $field) {
            if (empty($byBarcode[$field]) && ! empty($byName[$field])) {
                $byBarcode[$field] = $byName[$field];
            }
        }

        $byBarcode['match_by'] = 'barcode+name';

        return $byBarcode;
    }

    /**
     * @return array{
     *     found: bool,
     *     barcode: string,
     *     name: string|null,
     *     category: string|null,
     *     brand: string|null,
     *     quantity: string|null,
     *     volume_ml: int|null,
     *     weight_g: int|null,
     *     package_type: string|null,
     *     image_url: string|null,
     *     source_url: string|null,
     *     match_by: string|null
     * }
     */
    private function emptyResult(string $barcode): array
    {
        return [
            'found' => false,
            'barcode' => $barcode,
            'name' => null,
            'category' => null,
            'brand' => null,
            'quantity' => null,
            'volume_ml' => null,
            'weight_g' => null,
            'package_type' => null,
            'image_url' => null,
            'source_url' => null,
            'match_by' => null,
        ];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeout())
            ->withHeaders([
                'User-Agent' => $this->userAgent(),
            ]);
    }

    /**
     * @return list<string>
     */
    private function hosts(): array
    {
        $configured = config('services.openfoodfacts.hosts');
        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter(array_map(
                static fn ($host): string => rtrim((string) $host, '/'),
                $configured,
            )));
        }

        // RU first — better CIS coverage for BY/RU retail barcodes.
        return [
            'https://ru.openfoodfacts.org',
            'https://world.openfoodfacts.org',
        ];
    }

    private function cacheTtl(): int
    {
        return max(300, (int) config('services.openfoodfacts.cache_ttl', 86400));
    }

    private function missCacheTtl(): int
    {
        return max(60, (int) config('services.openfoodfacts.miss_cache_ttl', 3600));
    }

    private function timeout(): int
    {
        return max(3, (int) config('services.openfoodfacts.timeout', 8));
    }

    private function userAgent(): string
    {
        return (string) config(
            'services.openfoodfacts.user_agent',
            'SmartPlanogramControlCenter/2.4 (product-bot; https://github.com/devopstem/sp_backend_web)',
        );
    }

    private function throttle(): void
    {
        $minGapMs = max(0, (int) config('services.openfoodfacts.min_interval_ms', 250));
        if ($minGapMs === 0) {
            return;
        }

        $key = 'off:throttle:last';
        $last = Cache::get($key);
        if (is_numeric($last)) {
            $elapsedMs = (microtime(true) - (float) $last) * 1000;
            if ($elapsedMs < $minGapMs) {
                usleep((int) (($minGapMs - $elapsedMs) * 1000));
            }
        }

        Cache::put($key, microtime(true), 60);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{
     *     found: bool,
     *     barcode: string,
     *     name: string|null,
     *     category: string|null,
     *     brand: string|null,
     *     quantity: string|null,
     *     volume_ml: int|null,
     *     weight_g: int|null,
     *     package_type: string|null,
     *     image_url: string|null,
     *     source_url: string|null,
     *     match_by: string|null
     * }
     */
    private function mapProduct(array $product, string $barcode): array
    {
        $name = $this->firstNonEmpty([
            $product['product_name_ru'] ?? null,
            $product['product_name'] ?? null,
            $product['product_name_en'] ?? null,
            $product['generic_name'] ?? null,
        ]);

        $brand = $this->firstNonEmpty([
            $product['brands'] ?? null,
        ]);

        if ($name && $brand && ! str_contains(mb_strtolower($name), mb_strtolower($brand))) {
            $name = trim($brand.' '.$name);
        }

        $category = $this->resolveCategory($product);
        $quantity = $this->firstNonEmpty([
            $product['quantity'] ?? null,
            $product['packaging_text'] ?? null,
        ]);

        [$volumeMl, $weightG] = $this->resolveQuantity($product, $quantity);
        $packageType = $this->resolvePackageType($product);

        return [
            'found' => ($name !== null && $name !== '') || $volumeMl !== null || $packageType !== null,
            'barcode' => $barcode,
            'name' => $name,
            'category' => $category,
            'brand' => $brand,
            'quantity' => is_string($quantity) ? $quantity : (is_numeric($quantity) ? (string) $quantity : null),
            'volume_ml' => $volumeMl,
            'weight_g' => $weightG,
            'package_type' => $packageType,
            'image_url' => $this->firstNonEmpty([
                $product['image_front_small_url'] ?? null,
                $product['image_small_url'] ?? null,
                $product['image_url'] ?? null,
            ]),
            'source_url' => $barcode !== ''
                ? 'https://world.openfoodfacts.org/product/'.$barcode
                : null,
            'match_by' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function resolvePackageType(array $product): ?string
    {
        $candidates = [];

        if (is_array($product['packaging_tags'] ?? null)) {
            foreach ($product['packaging_tags'] as $tag) {
                if (is_string($tag) || is_numeric($tag)) {
                    $candidates[] = (string) $tag;
                }
            }
        }

        if (is_string($product['packaging'] ?? null) || is_numeric($product['packaging'] ?? null)) {
            $candidates[] = (string) $product['packaging'];
        }

        if (is_string($product['packaging_text'] ?? null)) {
            $candidates[] = $product['packaging_text'];
        }

        foreach ($candidates as $candidate) {
            $normalized = ProductPackageTypes::normalize($candidate);
            if ($normalized !== null && $normalized !== ProductPackageTypes::OTHER) {
                return $normalized;
            }
        }

        foreach ($candidates as $candidate) {
            $normalized = ProductPackageTypes::normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveQuantity(array $product, ?string $quantityText): array
    {
        [$fromTextMl, $fromTextG] = $this->parseQuantity($quantityText);
        if ($fromTextMl !== null || $fromTextG !== null) {
            return [$fromTextMl, $fromTextG];
        }

        $rawQty = $product['product_quantity'] ?? null;
        $unit = mb_strtolower(trim((string) ($product['product_quantity_unit'] ?? '')));

        if (! is_numeric($rawQty)) {
            return [null, null];
        }

        $value = (float) $rawQty;
        if ($value <= 0) {
            return [null, null];
        }

        return match (true) {
            $unit === '' && $value >= 50 && $value <= 5000 => [(int) round($value), null], // bare ml-ish
            in_array($unit, ['ml', 'мл', 'milliliter', 'millilitre'], true) => [(int) round($value), null],
            in_array($unit, ['l', 'л', 'litre', 'liter'], true) => [(int) round($value * 1000), null],
            in_array($unit, ['cl', 'сл'], true) => [(int) round($value * 10), null],
            in_array($unit, ['g', 'гр', 'г', 'gram', 'grams'], true) => [null, (int) round($value)],
            in_array($unit, ['kg', 'кг'], true) => [null, (int) round($value * 1000)],
            default => [null, null],
        };
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function scoreNameMatch(string $query, array $product, ?int $preferVolumeMl): float
    {
        $candidate = mb_strtolower((string) $this->firstNonEmpty([
            $product['product_name_ru'] ?? null,
            $product['product_name'] ?? null,
            $product['product_name_en'] ?? null,
            $product['generic_name'] ?? null,
        ]));

        $needle = mb_strtolower($query);
        if ($candidate === '') {
            return 0.0;
        }

        similar_text($needle, $candidate, $percent);
        $score = $percent / 100;

        if (str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
            $score = max($score, 0.72);
        }

        $queryTokens = $this->tokens($needle);
        $candTokens = $this->tokens($candidate);
        if ($queryTokens !== [] && $candTokens !== []) {
            $overlap = count(array_intersect($queryTokens, $candTokens)) / max(count($queryTokens), 1);
            $score = max($score, $overlap * 0.9);
        }

        if ($preferVolumeMl !== null) {
            [$volumeMl] = $this->resolveQuantity($product, $this->firstNonEmpty([
                $product['quantity'] ?? null,
                $product['packaging_text'] ?? null,
            ]));
            if ($volumeMl !== null && abs($volumeMl - $preferVolumeMl) <= 50) {
                $score += 0.15;
            }
        }

        return min(1.0, $score);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $value) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $part): string => mb_strtolower($part), $parts),
            static fn (string $part): bool => mb_strlen($part) >= 3,
        ));
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function resolveCategory(array $product): ?string
    {
        $direct = $this->firstNonEmpty([
            $product['categories_ru'] ?? null,
            $product['categories'] ?? null,
        ]);

        if ($direct) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $direct))));

            return $parts[0] ?? $direct;
        }

        $tags = $product['categories_tags'] ?? null;
        if (is_array($tags) && isset($tags[0]) && is_string($tags[0])) {
            return trim(str_replace(['en:', 'ru:', '-'], ['', '', ' '], $tags[0]));
        }

        return null;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function parseQuantity(?string $quantity): array
    {
        if ($quantity === null || $quantity === '') {
            return [null, null];
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*m(?:l|л)\b/ui', $quantity, $match)) {
            $ml = (int) round((float) str_replace(',', '.', $match[1]));

            return [$ml > 0 ? $ml : null, null];
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*c(?:l|л)\b/ui', $quantity, $match)) {
            $ml = (int) round(((float) str_replace(',', '.', $match[1])) * 10);

            return [$ml > 0 ? $ml : null, null];
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*l(?:\b|ит)/ui', $quantity, $match)) {
            $ml = (int) round(((float) str_replace(',', '.', $match[1])) * 1000);

            return [$ml > 0 ? $ml : null, null];
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:g|гр|г)\b/ui', $quantity, $match)) {
            $grams = (int) round((float) str_replace(',', '.', $match[1]));

            return [null, $grams > 0 ? $grams : null];
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*kg\b/ui', $quantity, $match)) {
            $grams = (int) round(((float) str_replace(',', '.', $match[1])) * 1000);

            return [null, $grams > 0 ? $grams : null];
        }

        return [null, null];
    }
}
