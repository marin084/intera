<?php

namespace App\Services\Geo;

class CountryNormalizer
{
    /**
     * Complete catalog of ISO 3166-1 alpha-2 codes and their common names/aliases (lowercase, unaccented).
     *
     * @var array<string, array<string>>
     */
    protected const COUNTRIES = [
        // Central America & Caribbean
        'CR' => ['costa rica', 'costarica', 'costarrica', 'cr'],
        'GT' => ['guatemala', 'guat', 'gt'],
        'SV' => ['el salvador', 'salvador', 'sv'],
        'HN' => ['honduras', 'hn'],
        'NI' => ['nicaragua', 'ni'],
        'PA' => ['panama', 'pa'],
        'BZ' => ['belice', 'belize', 'bz'],
        'DO' => ['republica dominicana', 'rep dominicana', 'dominicana', 'do'],
        'PR' => ['puerto rico', 'puertorico', 'pr'],
        'CU' => ['cuba', 'cu'],
        'HT' => ['haiti', 'ht'],
        'JM' => ['jamaica', 'jm'],
        'TT' => ['trinidad y tobago', 'trinidad and tobago', 'trinidad', 'tobago', 'tt'],

        // North America
        'MX' => ['mexico', 'mejico', 'mx'],
        'US' => ['estados unidos', 'united states of america', 'united states', 'ee uu', 'eeuu', 'usa', 'us', 'estadosunidos'],
        'CA' => ['canada', 'ca'],

        // South America
        'CO' => ['colombia', 'colmbia', 'co'],
        'AR' => ['argentina', 'ar'],
        'CL' => ['chile', 'cl'],
        'PE' => ['peru', 'pe'],
        'EC' => ['ecuador', 'ec'],
        'BO' => ['bolivia', 'bo'],
        'PY' => ['paraguay', 'py'],
        'UY' => ['uruguay', 'uy'],
        'VE' => ['venezuela', 've'],
        'BR' => ['brasil', 'brazil', 'br'],
        'GY' => ['guyana', 'gy'],
        'SR' => ['surinam', 'suriname', 'sr'],

        // Europe
        'ES' => ['espana', 'espanya', 'spain', 'reino de espana'],
        'PT' => ['portugal', 'pt'],
        'FR' => ['francia', 'france', 'fr'],
        'IT' => ['italia', 'italy', 'it'],
        'DE' => ['alemania', 'germany', 'deutschland'],
        'GB' => ['reino unido', 'gran bretana', 'united kingdom', 'great britain', 'uk', 'gb', 'england', 'inglaterra'],
        'NL' => ['paises bajos', 'holanda', 'netherlands', 'nl'],
        'BE' => ['belgica', 'belgium', 'be'],
        'CH' => ['suiza', 'switzerland', 'ch'],
        'AT' => ['austria', 'at'],
        'IE' => ['irlanda', 'ireland', 'ie'],
        'SE' => ['suecia', 'sweden', 'se'],
        'NO' => ['noruega', 'norway'],
        'DK' => ['dinamarca', 'denmark', 'dk'],
        'FI' => ['finlandia', 'finland', 'fi'],
        'PL' => ['polonia', 'poland', 'pl'],
        'RU' => ['rusia', 'russia', 'ru'],

        // Asia & Oceania
        'CN' => ['china', 'cn'],
        'JP' => ['japon', 'japan', 'jp'],
        'KR' => ['corea del sur', 'corea', 'south korea', 'kr'],
        'IN' => ['india'],
        'AU' => ['australia', 'au'],
        'NZ' => ['nueva zelanda', 'new zealand', 'nz'],
    ];

    /**
     * Cache for compiled matching rules.
     *
     * @var array<array{iso: string, alias: string, words: int, length: int}>|null
     */
    protected static ?array $compiledRules = null;

    /**
     * Normalize conversational input or raw country name to ISO 3166-1 alpha-2 code.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $raw = trim($input);
        if ($raw === '') {
            return null;
        }

        // Direct check: If input is already an exact 2-letter uppercase or lowercase ISO code
        if (preg_match('/^[A-Za-z]{2}$/', $raw)) {
            $upper = strtoupper($raw);
            if (isset(self::COUNTRIES[$upper])) {
                return $upper;
            }
        }

        // Paso A - Sanitización: convertir a minúsculas, remover diacríticos y normalizar signos de puntuación
        $clean = mb_strtolower($raw, 'UTF-8');
        $clean = strtr($clean, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ã' => 'a', 'õ' => 'o', 'ñ' => 'n',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o',
        ]);

        // Specific acronym normalization: "ee.uu." -> "eeuu", "u.s.a." -> "usa"
        $clean = preg_replace('/\b([a-z])\.([a-z])\.([a-z])\.([a-z])\./i', '$1$2$3$4', $clean);
        $clean = preg_replace('/\b([a-z])\.([a-z])\.([a-z])\./i', '$1$2$3', $clean);
        $clean = preg_replace('/\b([a-z])\.([a-z])\./i', '$1$2', $clean);

        // Replace non-alphanumeric characters with spaces
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $clean);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        if ($clean === '') {
            return null;
        }

        // Paso B - Remoción de prefijos conversacionales
        $prefixPattern = '/^(hola|buenos\s+dias|buenas\s+tardes|buenas\s+noches|buenas)?\s*(yo\s+)?(soy\s+de|vivo\s+en|estoy\s+en|radico\s+en|vengo\s+de|hablo\s+desde|desde|de|en)\s+/i';
        $withoutPrefix = trim(preg_replace($prefixPattern, '', $clean));

        // If after prefix removal we have an exact 2-letter ISO code:
        if (preg_match('/^[a-z]{2}$/', $withoutPrefix)) {
            $codeUpper = strtoupper($withoutPrefix);
            if (isset(self::COUNTRIES[$codeUpper])) {
                return $codeUpper;
            }
        }

        // Paso C - Catálogo y Coincidencia Inteligente
        $rules = self::getCompiledRules();

        // 1. Evaluate first against $withoutPrefix (higher priority when prefix was removed)
        if ($withoutPrefix !== '') {
            foreach ($rules as $rule) {
                $pattern = '/\b'.preg_quote($rule['alias'], '/').'\b/i';
                if (preg_match($pattern, $withoutPrefix)) {
                    return $rule['iso'];
                }
            }
        }

        // 2. Evaluate against cleaned full text (handles cases where prefix was not matched or country is anywhere)
        foreach ($rules as $rule) {
            $pattern = '/\b'.preg_quote($rule['alias'], '/').'\b/i';
            if (preg_match($pattern, $clean)) {
                return $rule['iso'];
            }
        }

        return null;
    }

    /**
     * Get compiled rules sorted by multi-word count descending, then string length descending.
     *
     * @return array<array{iso: string, alias: string, words: int, length: int}>
     */
    protected static function getCompiledRules(): array
    {
        if (self::$compiledRules !== null) {
            return self::$compiledRules;
        }

        $rules = [];
        foreach (self::COUNTRIES as $iso => $aliases) {
            foreach ($aliases as $alias) {
                $wordCount = count(explode(' ', $alias));
                $rules[] = [
                    'iso' => $iso,
                    'alias' => $alias,
                    'words' => $wordCount,
                    'length' => mb_strlen($alias),
                ];
            }
        }

        // Sort: Multiple words first, then longer string length first
        usort($rules, function ($a, $b) {
            if ($a['words'] !== $b['words']) {
                return $b['words'] <=> $a['words'];
            }

            return $b['length'] <=> $a['length'];
        });

        return self::$compiledRules = $rules;
    }
}
