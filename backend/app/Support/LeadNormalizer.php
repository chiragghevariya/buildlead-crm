<?php

namespace App\Support;

use App\Enums\ProjectType;
use App\Enums\Timeline;

/**
 * Turns messy inbound form/email/phone input (German or English) into clean lead fields.
 */
class LeadNormalizer
{
    /**
     * Parse a free-text budget into [min, max] euros.
     *
     *  "150k" → [150000, 150000]        "80.000-120.000 €" → [80000, 120000]
     *  "80–120k" → [80000, 120000]       "1,2 Mio" → [1200000, 1200000]
     *  "bis 50.000" / "up to 50k" → [null, 50000]
     *  "ab 200k" / "from 200k" → [200000, null]
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function budget(int|float|string|null $input): array
    {
        if ($input === null || $input === '') {
            return [null, null];
        }
        if (is_int($input) || is_float($input)) {
            $v = (int) round($input);

            return $v > 0 ? [$v, $v] : [null, null];
        }

        $text = mb_strtolower(trim($input));
        $pattern = '/(\d+(?:[.,\s]\d+)*)\s*(k|tsd\.?|tausend|t€|mio\.?|millionen|million|m)?(?![a-zäöü])/u';
        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [null, null];
        }

        $values = [];
        foreach (array_slice($matches, 0, 2) as $m) {
            $values[] = ['number' => self::number($m[1]), 'multiplier' => self::multiplier($m[2] ?? '')];
        }

        // "80-120k": a unit on one side of a range applies to both.
        if (count($values) === 2) {
            [$a, $b] = $values;
            if ($a['multiplier'] === 1 && $b['multiplier'] > 1 && $a['number'] < 1000) {
                $values[0]['multiplier'] = $b['multiplier'];
            }
        }

        $amounts = array_map(fn ($v) => (int) round($v['number'] * $v['multiplier']), $values);
        $amounts = array_values(array_filter($amounts, fn ($v) => $v > 0));
        if ($amounts === []) {
            return [null, null];
        }

        if (count($amounts) === 2) {
            sort($amounts);

            return [$amounts[0], $amounts[1]];
        }

        $value = $amounts[0];

        return match (true) {
            (bool) preg_match('/\b(bis|max\.?|maximal|höchstens|unter|up to|max|under|below|less than)\b|^</u', $text) => [null, $value],
            (bool) preg_match('/\b(ab|mindestens|über|from|min\.?|at least|over|more than)\b|^>|\+$/u', $text) => [$value, null],
            default => [$value, $value],
        };
    }

    /** "80.000" → 80000, "1,2" → 1.2, "1.250,50" → 1250.5, "1 200 000" → 1200000 */
    private static function number(string $raw): float
    {
        $raw = preg_replace('/\s+/', '', $raw);
        $hasDot = str_contains($raw, '.');
        $hasComma = str_contains($raw, ',');

        if ($hasDot && $hasComma) {
            // Whichever comes last is the decimal separator.
            $decimal = strrpos($raw, ',') > strrpos($raw, '.') ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $raw = str_replace([$thousands, $decimal], ['', '.'], $raw);

            return (float) $raw;
        }

        if ($hasDot || $hasComma) {
            $sep = $hasDot ? '.' : ',';
            $parts = explode($sep, $raw);
            $groupsOfThree = count($parts) > 1 && collect(array_slice($parts, 1))->every(fn ($p) => strlen($p) === 3);

            return (float) ($groupsOfThree ? implode('', $parts) : str_replace($sep, '.', $raw));
        }

        return (float) $raw;
    }

    private static function multiplier(string $unit): int
    {
        $unit = rtrim($unit, '.');

        return match ($unit) {
            'k', 'tsd', 'tausend', 't€' => 1_000,
            'm', 'mio', 'million', 'millionen' => 1_000_000,
            default => 1,
        };
    }

    /** German/English project type → enum value (null if unrecognised; AI may classify later). */
    public static function projectType(?string $input): ?string
    {
        $text = self::key($input);
        if ($text === '') {
            return null;
        }
        $enum = ProjectType::tryFrom(mb_strtolower(trim((string) $input)));
        if ($enum) {
            return $enum->value;
        }

        // Order matters: "Dachausbau" is an extension (not roofing, and it contains "hausbau"); "Gewerbe" beats "Umbau".
        $map = [
            'commercial_fit_out' => ['fit out', 'fitout', 'commercial', 'office', 'büro', 'buero', 'gewerbe', 'ladenbau', 'laden', 'praxis', 'restaurant', 'retail', 'shop'],
            'extension' => ['extension', 'anbau', 'erweiterung', 'aufstockung', 'dachausbau', 'dachgeschoss', 'loft conversion', 'wintergarten', 'conservatory'],
            'new_build' => ['new build', 'newbuild', 'neubau', 'hausbau', 'einfamilienhaus', 'mehrfamilienhaus', 'build a house', 'new house', 'construction of'],
            'roofing' => ['roof', 'dach', 'dachdecker', 'flachdach'],
            'interior' => ['interior', 'innenausbau', 'küche', 'kueche', 'kitchen', 'trockenbau', 'drywall', 'joinery', 'tischler', 'innenarchitektur'],
            'repair' => ['repair', 'reparatur', 'schaden', 'instandsetzung', 'fix', 'leak', 'crack', 'riss', 'feucht', 'damage'],
            'renovation' => ['renovation', 'renovierung', 'sanierung', 'modernisierung', 'refurb', 'remodel', 'umbau', 'altbau'],
        ];

        foreach ($map as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /** German/English timeline → enum value (null if unrecognised). */
    public static function timeline(?string $input): ?string
    {
        $text = self::key($input);
        if ($text === '') {
            return null;
        }
        $enum = Timeline::tryFrom(mb_strtolower(trim((string) $input)));
        if ($enum) {
            return $enum->value;
        }

        $map = [
            'just_researching' => ['research', 'informier', 'information', 'nur schauen', 'orientier', 'unklar', 'not sure', 'unsure', 'irgendwann', 'no rush', 'keine eile', 'just looking', 'noch offen', 'idee'],
            'asap' => ['asap', 'sofort', 'so schnell', 'dringend', 'urgent', 'immediately', 'right away', 'umgehend', 'schnellstmöglich', 'now'],
            'within_3_months' => ['3 month', '3 monat', 'drei monat', 'three month', 'quarter', 'quartal', '1 3', '2 3', 'bald', 'soon', 'next month', 'nächsten monat', 'naechsten monat', 'weeks', 'wochen'],
            'within_6_months' => ['6 month', '6 monat', 'sechs monat', 'six month', 'half', 'halbes jahr', 'halbjahr', '3 6', '4 6'],
            'this_year' => ['this year', 'dieses jahr', 'diesem jahr', 'next year', 'nächstes jahr', 'naechstes jahr', '12 month', '12 monat', 'later', 'später', 'spaeter'],
        ];

        foreach ($map as $timeline => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $timeline;
                }
            }
        }

        return null;
    }

    /** Digits only, German numbers in international form: "030 123-45" → "4930 12345". */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '' || strlen($digits) < 6) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '49'.substr($digits, 1);
        }

        return $digits;
    }

    /** Lower-cased, trimmed; hyphens/underscores become spaces ("Fit-Out" → "fit out"). */
    private static function key(?string $input): string
    {
        return str_replace(['-', '_', '–'], ' ', mb_strtolower(trim((string) $input)));
    }
}
