<?php

namespace Tests\Feature\Scoring;

use App\Enums\LeadTemperature;
use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Services\Scoring\RuleBasedScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScoringSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_overrides_are_merged_over_file_config(): void
    {
        ScoringSetting::put('bands.hot', 80);
        ScoringSetting::put('location.core_prefixes', ['10']);

        $this->assertSame(80, ScoringSetting::config('bands.hot'));
        $this->assertSame(50, ScoringSetting::config('bands.warm')); // untouched
        $this->assertSame(['10'], ScoringSetting::config('location.core_prefixes'));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore(78));

        $points = (new RuleBasedScorer)->score(new Lead(['postcode' => '14467']))['factors']['location']['points'];
        $this->assertSame(0.0, $points); // 14 is no longer core
    }

    public function test_overrides_are_cached_and_cache_is_flushed_on_change(): void
    {
        ScoringSetting::put('ai_weight', 0.5);
        $this->assertSame(0.5, ScoringSetting::config('ai_weight'));

        DB::enableQueryLog();
        ScoringSetting::config('ai_weight');
        ScoringSetting::config('bands');
        $this->assertCount(0, DB::getQueryLog(), 'reads should come from cache');

        ScoringSetting::put('ai_weight', 0.7);
        $this->assertSame(0.7, ScoringSetting::config('ai_weight'));

        ScoringSetting::where('key', 'ai_weight')->first()->delete();
        $this->assertSame(0.6, ScoringSetting::config('ai_weight'));
        $this->assertTrue(Cache::has(ScoringSetting::CACHE_KEY));
    }
}
