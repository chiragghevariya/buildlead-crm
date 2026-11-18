<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Services\Scoring\OpenAiScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Admin-only: tune the scoring engine at runtime. Values are stored as
 * overrides of config/scoring.php (scoring_settings table); a value equal to
 * the file default removes the override.
 */
class ScoringSettingsController extends Controller
{
    private const FACTORS = ['budget', 'project_type', 'location', 'urgency', 'source', 'completeness'];

    /** Dot paths this API manages. */
    private const KEYS = [
        'weights.budget', 'weights.project_type', 'weights.location', 'weights.urgency', 'weights.source', 'weights.completeness',
        'bands.hot', 'bands.warm', 'ai_weight', 'ai.enabled',
    ];

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'weights' => ['sometimes', 'array:'.implode(',', self::FACTORS)],
            'bands' => ['sometimes', 'array:hot,warm'],
            'bands.hot' => ['required_with:bands', 'integer', 'min:1', 'max:100'],
            'bands.warm' => ['required_with:bands', 'integer', 'min:0', 'max:99'],
            'ai_weight' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'rescore' => ['sometimes', 'boolean'],
        ];
        foreach (self::FACTORS as $factor) {
            $rules["weights.{$factor}"] = ['required_with:weights', 'integer', 'min:0', 'max:100'];
        }

        $data = $request->validate($rules, [
            'weights.array' => 'Weights must contain exactly: '.implode(', ', self::FACTORS).'.',
        ]);

        validator($data, [])->after(function (Validator $v) use ($data) {
            if (isset($data['weights']) && array_sum($data['weights']) !== 100) {
                $v->errors()->add('weights', 'Weights must add up to 100 (currently '.array_sum($data['weights']).').');
            }
            if (isset($data['bands']) && $data['bands']['hot'] <= $data['bands']['warm']) {
                $v->errors()->add('bands.hot', 'The hot threshold must be higher than the warm threshold.');
            }
        })->validate();

        $values = [];
        foreach ($data['weights'] ?? [] as $factor => $weight) {
            $values["weights.{$factor}"] = (int) $weight;
        }
        if (isset($data['bands'])) {
            $values['bands.hot'] = (int) $data['bands']['hot'];
            $values['bands.warm'] = (int) $data['bands']['warm'];
        }
        if (array_key_exists('ai_weight', $data)) {
            $values['ai_weight'] = round((float) $data['ai_weight'], 2);
        }
        if (array_key_exists('ai_enabled', $data)) {
            $values['ai.enabled'] = (bool) $data['ai_enabled'];
        }

        foreach ($values as $key => $value) {
            // Store only real deviations from config/scoring.php.
            if ($value == Arr::get(config('scoring'), $key)) {
                ScoringSetting::where('key', $key)->first()?->delete();
            } else {
                ScoringSetting::put($key, $value);
            }
        }

        $queued = ($data['rescore'] ?? false) ? $this->rescoreOpenLeads() : null;

        return response()->json($this->payload() + ['rescore_queued' => $queued]);
    }

    public function reset(Request $request): JsonResponse
    {
        ScoringSetting::query()->whereIn('key', self::KEYS)->delete();
        ScoringSetting::flushCache();

        $queued = $request->boolean('rescore') ? $this->rescoreOpenLeads() : null;

        return response()->json($this->payload() + ['rescore_queued' => $queued]);
    }

    public function rescore(): JsonResponse
    {
        return response()->json(['rescore_queued' => $this->rescoreOpenLeads()], 202);
    }

    private function rescoreOpenLeads(): int
    {
        $count = 0;
        Lead::query()->open()->select('id')->chunkById(200, function ($leads) use (&$count) {
            foreach (Lead::query()->whereIn('id', $leads->pluck('id'))->get() as $lead) {
                ScoreLeadJob::dispatch($lead, autoAssign: false);
                $count++;
            }
        });

        return $count;
    }

    private function payload(): array
    {
        $effective = ScoringSetting::config();
        $defaults = config('scoring');
        $overrides = array_intersect_key(ScoringSetting::overrides(), array_flip(self::KEYS));

        $shape = fn (array $c) => [
            'weights' => Arr::only($c['weights'], self::FACTORS),
            'bands' => Arr::only($c['bands'], ['hot', 'warm']),
            'ai_weight' => (float) $c['ai_weight'],
            'ai_enabled' => (bool) ($c['ai']['enabled'] ?? true),
        ];

        return $shape($effective) + [
            'ai_available' => app(OpenAiScorer::class)->isEnabled(),
            'ai_model' => $effective['ai']['model'] ?? null,
            'defaults' => $shape($defaults),
            'overridden' => array_keys($overrides),
            'open_leads' => Lead::query()->open()->count(),
        ];
    }
}
