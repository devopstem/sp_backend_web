<?php

declare(strict_types=1);

namespace App\Support;

final class ProductPackageTypes
{
    public const BOTTLE = 'Бутылка';

    public const CAN = 'Банка';

    public const PET = 'ПЭТ';

    public const BAG = 'Пакет';

    public const BOX = 'Коробка';

    public const OTHER = 'Другое';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::BOTTLE,
            self::CAN,
            self::PET,
            self::BAG,
            self::BOX,
            self::OTHER,
        ];
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::all(), true);
    }

    /**
     * Normalize raw packaging string (Open Food Facts / EN / RU) to Russian catalog value.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = mb_strtolower(trim($raw));
        $value = str_replace(['en:', 'ru:', 'fr:', 'de:', '-'], ['', '', '', '', ' '], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        if ($value === '' || str_contains($value, 'unknown')) {
            return null;
        }

        if (self::isValid($raw)) {
            return $raw;
        }

        foreach (self::all() as $label) {
            if (mb_strtolower($label) === $value) {
                return $label;
            }
        }

        // Longer / specific phrases first so "plastic bottle" ≠ generic "plastic".
        $map = [
            'glass bottle' => self::BOTTLE,
            'plastic bottle' => self::BOTTLE,
            'aluminium can' => self::CAN,
            'aluminum can' => self::CAN,
            'metal can' => self::CAN,
            'drink can' => self::CAN,
            'жестяная банка' => self::CAN,
            'алюминиевая банка' => self::CAN,
            'стеклянная бутылка' => self::BOTTLE,
            'пластиковая бутылка' => self::PET,
            'pet bottle' => self::PET,
            'plastic bag' => self::BAG,
            'tetra pak' => self::BOX,
            'tetrapak' => self::BOX,
            'тетра пак' => self::BOX,
            'тетрапак' => self::BOX,
            'пакет асептический' => self::BOX,
            'bottle' => self::BOTTLE,
            'бутылка' => self::BOTTLE,
            'стекло' => self::BOTTLE,
            'glass' => self::BOTTLE,
            'can' => self::CAN,
            'tin' => self::CAN,
            'банка' => self::CAN,
            'pet' => self::PET,
            'пэт' => self::PET,
            'пластик' => self::PET,
            'plastic' => self::PET,
            'sachet' => self::BAG,
            'pouch' => self::BAG,
            'пакетик' => self::BAG,
            'дойпак' => self::BAG,
            'пакет' => self::BAG,
            'bag' => self::BAG,
            'carton' => self::BOX,
            'cardboard' => self::BOX,
            'brick' => self::BOX,
            'коробка' => self::BOX,
            'box' => self::BOX,
            'другое' => self::OTHER,
            'other' => self::OTHER,
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        // Word-boundary match — avoids "packaging" → "pack" false positive.
        foreach ($map as $needle => $label) {
            if ($needle === 'other' || $needle === 'другое') {
                continue;
            }

            $pattern = '/(?:^|[^\p{L}\p{N}])'.preg_quote($needle, '/').'(?:$|[^\p{L}\p{N}])/u';
            if (preg_match($pattern, ' '.$value.' ')) {
                return $label;
            }
        }

        return null;
    }
}
