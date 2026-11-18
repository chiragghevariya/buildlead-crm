<?php

namespace Tests\Unit;

use App\Support\LeadNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LeadNormalizerTest extends TestCase
{
    public static function budgets(): array
    {
        return [
            ['150k', [150000, 150000]],
            ['80.000-120.000 €', [80000, 120000]],
            ['€80k–120k', [80000, 120000]],
            ['80-120k', [80000, 120000]],
            ['300-400 Tsd', [300000, 400000]],
            ['1,2 Mio', [1200000, 1200000]],
            ['1.2M', [1200000, 1200000]],
            ['1.250.000', [1250000, 1250000]],
            ['250 000 €', [250000, 250000]],
            ['€ 45.500,00', [45500, 45500]],
            ['ca. 100.000 €', [100000, 100000]],
            ['bis 50.000', [null, 50000]],
            ['up to 50k', [null, 50000]],
            ['unter 30.000', [null, 30000]],
            ['ab 200k', [200000, null]],
            ['from 200.000 EUR', [200000, null]],
            ['120.000 - 80.000', [80000, 120000]],
            ['keine Ahnung', [null, null]],
            ['', [null, null]],
        ];
    }

    #[DataProvider('budgets')]
    public function test_budget_parsing(string $input, array $expected): void
    {
        $this->assertSame($expected, LeadNormalizer::budget($input));
    }

    public function test_numeric_budget(): void
    {
        $this->assertSame([75000, 75000], LeadNormalizer::budget(75000));
        $this->assertSame([null, null], LeadNormalizer::budget(0));
    }

    public static function projectTypes(): array
    {
        return [
            ['Neubau', 'new_build'], ['Einfamilienhaus bauen', 'new_build'], ['New build', 'new_build'],
            ['Dachgeschossausbau', 'extension'], ['Dachausbau mit Gaube', 'extension'], ['Anbau', 'extension'],
            ['Dach neu eindecken', 'roofing'], ['Flat roof', 'roofing'],
            ['Büroausbau', 'commercial_fit_out'], ['Commercial Fit-Out', 'commercial_fit_out'], ['Ladenbau', 'commercial_fit_out'],
            ['Sanierung Altbau', 'renovation'], ['Renovierung', 'renovation'], ['refurbishment', 'renovation'],
            ['Küche', 'interior'], ['Innenausbau', 'interior'],
            ['Wasserschaden', 'repair'], ['Reparatur', 'repair'],
            ['renovation', 'renovation'], ['commercial_fit_out', 'commercial_fit_out'],
            ['Garden shed', null], ['', null],
        ];
    }

    #[DataProvider('projectTypes')]
    public function test_project_type_normalisation(string $input, ?string $expected): void
    {
        $this->assertSame($expected, LeadNormalizer::projectType($input));
    }

    public static function timelines(): array
    {
        return [
            ['ASAP', 'asap'], ['so schnell wie möglich', 'asap'], ['sofort', 'asap'], ['dringend!', 'asap'],
            ['in 1-3 Monaten', 'within_3_months'], ['within 3 months', 'within_3_months'], ['next quarter', 'within_3_months'],
            ['within 6 months', 'within_6_months'], ['halbes Jahr', 'within_6_months'],
            ['dieses Jahr', 'this_year'], ['next year', 'this_year'],
            ['nur informieren', 'just_researching'], ['Ich informiere mich nur', 'just_researching'], ['In 3–6 Monaten', 'within_6_months'], ['just researching', 'just_researching'], ['irgendwann', 'just_researching'],
            ['within_3_months', 'within_3_months'], ['Mittwoch', null],
        ];
    }

    #[DataProvider('timelines')]
    public function test_timeline_normalisation(string $input, ?string $expected): void
    {
        $this->assertSame($expected, LeadNormalizer::timeline($input));
    }

    public function test_phone_key_normalises_german_formats(): void
    {
        $this->assertSame('49301234567', LeadNormalizer::phoneKey('030 123 456 7'));
        $this->assertSame('49301234567', LeadNormalizer::phoneKey('+49 30 1234567'));
        $this->assertSame('49301234567', LeadNormalizer::phoneKey('0049 (30) 123-4567'));
        $this->assertNull(LeadNormalizer::phoneKey('123'));
        $this->assertNull(LeadNormalizer::phoneKey(null));
    }
}
