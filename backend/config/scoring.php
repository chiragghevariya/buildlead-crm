<?php

/*
|--------------------------------------------------------------------------
| BuildLead hybrid lead scoring
|--------------------------------------------------------------------------
| Rule score (0–100) = sum of factor weights × factor fraction (0.0–1.0).
| Final score = ai × ai_weight + rules × (1 − ai_weight).
| Admins can override any key at runtime via the scoring_settings table
| (key = dot path into this file, e.g. "weights.budget").
*/

return [

    // Factor weights — must sum to 100.
    'weights' => [
        'budget' => 30,
        'project_type' => 20,
        'location' => 15,
        'urgency' => 20,
        'source' => 10,
        'completeness' => 5,
    ],

    // Temperature bands (inclusive lower bounds).
    'bands' => [
        'hot' => (int) env('SCORING_HOT_THRESHOLD', 75),
        'warm' => (int) env('SCORING_WARM_THRESHOLD', 50),
    ],

    // Share of the final score that comes from OpenAI (rules get the rest).
    'ai_weight' => (float) env('SCORING_AI_WEIGHT', 0.6),

    'ai' => [
        'enabled' => (bool) env('SCORING_AI_ENABLED', true), // still requires OPENAI_API_KEY
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 20),
        'retries' => 2,            // extra attempts on connection errors, 429 and 5xx
        'retry_delay_ms' => 800,
        'temperature' => 0.2,
        // GDPR: personal contact data is NEVER sent to OpenAI. Only the
        // whitelisted project fields in OpenAiScorer::payload() leave the server.
    ],

    /*
    | Budget: uses budget_max (falls back to budget_min). First band whose
    | "min" the budget reaches wins. Missing budget = 0.
    */
    'budget_bands' => [
        ['min' => 500_000, 'fraction' => 1.0],
        ['min' => 250_000, 'fraction' => 0.9],
        ['min' => 100_000, 'fraction' => 0.75],
        ['min' => 50_000, 'fraction' => 0.6],
        ['min' => 25_000, 'fraction' => 0.4],
        ['min' => 10_000, 'fraction' => 0.25],
        ['min' => 0, 'fraction' => 0.1],
    ],

    'project_types' => [
        'new_build' => 1.0,
        'commercial_fit_out' => 1.0,
        'extension' => 0.8,
        'renovation' => 0.75,
        'roofing' => 0.6,
        'interior' => 0.5,
        'repair' => 0.3,
    ],

    // Postcode prefixes (first two digits).
    'location' => [
        'core_prefixes' => ['10', '12', '13', '14'],       // Berlin + Potsdam: full points
        'secondary_prefixes' => ['15', '16', '03', '04'],  // wider Brandenburg
        'secondary_fraction' => 0.6,
        'other_fraction' => 0.0,
    ],

    'urgency' => [
        'asap' => 1.0,
        'within_3_months' => 0.9,
        'within_6_months' => 0.65,
        'this_year' => 0.4,
        'just_researching' => 0.1,
    ],

    'sources' => [
        'referral' => 1.0,
        'phone_call' => 0.9,
        'trade_fair' => 0.75,
        'website_form' => 0.7,
        'email' => 0.6,
        'portal' => 0.4,
    ],

    // Each present field contributes equally to the completeness factor.
    'completeness' => [
        'fields' => ['phone', 'email', 'budget_max', 'postcode', 'area_sqm', 'description'],
        'min_description_length' => 40,
    ],

    // Auto-assignment after scoring.
    'assignment' => [
        'enabled' => (bool) env('SCORING_AUTO_ASSIGN', true),
        'win_rate_window_days' => 180,
    ],

    'queues' => [
        'scoring' => 'scoring',
        'notifications' => 'notifications',
    ],

    'alerts' => [
        'slack_webhook_url' => env('SLACK_HOT_LEAD_WEBHOOK_URL'),
    ],
];
