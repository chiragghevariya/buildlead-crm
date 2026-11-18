<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\InvoiceStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\QuoteStatus;
use App\Enums\Timeline;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Models\Quote;
use App\Models\ScoringSetting;
use App\Models\User;
use App\Services\Scoring\LeadScoringService;
use App\Services\Scoring\RuleBasedScorer;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo company "BuildLead Bau GmbH", Berlin.
 *
 * 1 admin, 1 manager, 6 sales reps, 60 leads over the last 12 weeks,
 * full activity timelines, 12 quotes, 6 projects, 15 invoices.
 * Deterministic: same data on every run (fixed seed), relative to "now".
 */
class DemoSeeder extends Seeder
{
    private Generator $faker;

    private Carbon $now;

    /** @var array<string, list<string>> companies not yet used */
    private array $companyPool = self::COMPANIES;

    /** Historic win rates (used to route hot leads to the best closer). */
    private const WIN_RATES = [
        'anna.weber@buildlead.test' => 0.44,
        'felix.krueger@buildlead.test' => 0.41,
        'lukas.schneider@buildlead.test' => 0.38,
        'sophie.wagner@buildlead.test' => 0.35,
        'jonas.fischer@buildlead.test' => 0.31,
        'mia.schulz@buildlead.test' => 0.29,
    ];

    private const LOCATIONS = [
        // postcode, city, district/area (for project names)
        ['10115', 'Berlin', 'Mitte'], ['10247', 'Berlin', 'Friedrichshain'], ['10437', 'Berlin', 'Prenzlauer Berg'],
        ['10585', 'Berlin', 'Charlottenburg'], ['10717', 'Berlin', 'Wilmersdorf'], ['10827', 'Berlin', 'Schöneberg'],
        ['10999', 'Berlin', 'Kreuzberg'], ['12043', 'Berlin', 'Neukölln'], ['12161', 'Berlin', 'Friedenau'],
        ['12347', 'Berlin', 'Britz'], ['12555', 'Berlin', 'Köpenick'], ['12683', 'Berlin', 'Biesdorf'],
        ['13086', 'Berlin', 'Weißensee'], ['13187', 'Berlin', 'Pankow'], ['13353', 'Berlin', 'Wedding'],
        ['13591', 'Berlin', 'Spandau'], ['14050', 'Berlin', 'Westend'], ['14163', 'Berlin', 'Zehlendorf'],
        ['14193', 'Berlin', 'Grunewald'], ['14467', 'Potsdam', 'Potsdam'], ['14482', 'Potsdam', 'Babelsberg'],
        ['14513', 'Teltow', 'Teltow'], ['14612', 'Falkensee', 'Falkensee'],
        ['15230', 'Frankfurt (Oder)', 'Frankfurt (Oder)'], ['15344', 'Strausberg', 'Strausberg'],
        ['15711', 'Königs Wusterhausen', 'Königs Wusterhausen'], ['15517', 'Fürstenwalde', 'Fürstenwalde'],
        ['16225', 'Eberswalde', 'Eberswalde'], ['16515', 'Oranienburg', 'Oranienburg'], ['16761', 'Hennigsdorf', 'Hennigsdorf'],
        ['03046', 'Cottbus', 'Cottbus'], ['03238', 'Finsterwalde', 'Finsterwalde'],
        ['04916', 'Herzberg (Elster)', 'Herzberg'], ['04910', 'Elsterwerda', 'Elsterwerda'],
        ['17291', 'Prenzlau', 'Prenzlau'], ['19348', 'Perleberg', 'Perleberg'],
    ];

    private const COMPANIES = [
        'commercial' => [
            'Nordlicht Software GmbH', 'Kiezküche Gastro GmbH', 'Lindenhof Praxisgemeinschaft',
            'Prenzlberg Coworking UG', 'Kreuzberg Studios GmbH', 'Tempelhof Fitness GmbH',
            'Charlotte Retail GmbH', 'Dr. Voss Zahnarztpraxis', 'Potsdamer Hof Hotelbetriebs GmbH',
            'Spreeufer Kanzlei Lehmann & Roth', 'Grünwerk Kita gGmbH', 'Atelier Brandt Architektur',
            'Havelblick Bürohaus KG', 'Weißensee Optik GmbH',
        ],
        'developer' => [
            'Märkische Wohnungsbau eG', 'Spreeblick Immobilien GmbH', 'Havelland Wohnbau GmbH',
            'Hausverwaltung Neumann & Partner', 'Brandenburger Logistikpark GmbH', 'Lausitz Energie Service GmbH',
            'Eberswalder Agrar GmbH', 'Seeblick Ferienhäuser GbR', 'Autohaus Kessler GmbH',
        ],
    ];

    private const DESCRIPTIONS = [
        'new_build' => [
            'We own a plot in {area_name} and want to build a detached family home (~{sqm} m², KfW 40 standard). Planning permission is in progress, looking for a general contractor.',
            'Neubau eines Mehrfamilienhauses mit 6 Wohneinheiten, ca. {sqm} m² Wohnfläche. Baugenehmigung liegt vor, Baubeginn so schnell wie möglich.',
            'Looking for a turnkey offer for a new office building ({sqm} m²) incl. underground parking. Architect drawings are ready.',
            'Einfamilienhaus mit Keller auf unserem Grundstück, ca. {sqm} m². Wir suchen einen zuverlässigen Bauträger aus der Region.',
        ],
        'renovation' => [
            'Komplettsanierung einer Altbauwohnung ({sqm} m²): Elektrik, Bäder, Böden, Fenster. Wohnung steht leer, Start flexibel.',
            'Full renovation of a 1920s apartment in {area_name}, ~{sqm} m². New kitchen, two bathrooms, refinishing original parquet.',
            'Energetische Sanierung unseres Reihenhauses: Dämmung, neue Fenster, Wärmepumpe. Ca. {sqm} m² Wohnfläche.',
            'We bought a house in {area_name} and need a complete refurbishment before moving in — roughly {sqm} m².',
        ],
        'extension' => [
            'Anbau eines Wintergartens bzw. Erweiterung des Wohnzimmers um ca. {sqm} m². Statik müsste noch geprüft werden.',
            'Two-storey rear extension (~{sqm} m²) for a growing family, planning approval granted last month.',
            'Dachgeschossausbau mit Gaube, ca. {sqm} m² zusätzliche Wohnfläche. Bauantrag wird gerade vorbereitet.',
        ],
        'commercial_fit_out' => [
            'Office fit-out for our new HQ, {sqm} m² open space with meeting rooms, kitchen and server room. Move-in planned for Q2.',
            'Ausbau einer Gewerbefläche ({sqm} m²) zu einem Restaurant inkl. Lüftung, Küche und Sanitär. Mietvertrag ist unterschrieben.',
            'Umbau einer Praxisfläche ({sqm} m²) für eine Zahnarztpraxis: Trockenbau, Elektro, medizinische Installationen.',
            'Retail store fit-out, ~{sqm} m² in {area_name}. We need the shop ready before the Christmas season.',
        ],
        'roofing' => [
            'Dach neu eindecken (ca. {sqm} m²), alte Ziegel aus den 70ern, an einigen Stellen undicht.',
            'Flat roof renewal with new insulation and waterproofing, approx. {sqm} m², commercial building.',
            'Dachsanierung inkl. Dämmung und Photovoltaik-Vorbereitung, Einfamilienhaus in {area_name}.',
        ],
        'interior' => [
            'Interior design and build for our loft: custom joinery, lighting concept, flooring (~{sqm} m²).',
            'Innenausbau Büroetage: neue Böden, Akustikdecken, Glaswände. Fläche ca. {sqm} m².',
            'Kitchen and living area redesign, ~{sqm} m². Open to suggestions, budget is still flexible.',
        ],
        'repair' => [
            'Wasserschaden im Bad, Fliesen und Estrich müssen erneuert werden. Ca. {sqm} m².',
            'Cracks in the facade and a damaged balcony slab — need an assessment and repair offer.',
            'Feuchte Kellerwand, ca. {sqm} m², bitte um Besichtigung und Angebot.',
            'Small repair: broken stairs and some plaster work in the hallway. Just want a rough price.',
        ],
    ];

    /** Quote line templates: description, unit, share of net total. */
    private const QUOTE_LINES = [
        'new_build' => [
            ['Site setup, excavation & foundation', 'flat', 0.14],
            ['Shell construction (masonry, concrete, ceilings)', 'm²', 0.38],
            ['Roof structure & covering', 'm²', 0.12],
            ['Building services (heating, plumbing, electrics)', 'flat', 0.24],
            ['Project management & site supervision', 'h', 0.12],
        ],
        'renovation' => [
            ['Demolition & disposal', 'flat', 0.10],
            ['Electrical installation (new)', 'm²', 0.20],
            ['Bathroom renovation incl. tiling', 'pcs', 0.30],
            ['Flooring & wall finishes', 'm²', 0.30],
            ['Site management', 'h', 0.10],
        ],
        'extension' => [
            ['Foundation & structural works', 'flat', 0.25],
            ['Timber frame / masonry extension', 'm²', 0.40],
            ['Windows & glazing', 'pcs', 0.20],
            ['Interior finishing', 'm²', 0.15],
        ],
        'commercial_fit_out' => [
            ['Drywall partitions & acoustic ceilings', 'm²', 0.32],
            ['HVAC & ventilation', 'flat', 0.24],
            ['Electrical, data & lighting', 'm²', 0.22],
            ['Flooring', 'm²', 0.12],
            ['Project management', 'h', 0.10],
        ],
        'roofing' => [
            ['Scaffolding', 'flat', 0.12],
            ['Removal of existing roof covering', 'm²', 0.15],
            ['Insulation & vapour barrier', 'm²', 0.30],
            ['New roof covering incl. flashing', 'm²', 0.43],
        ],
        'interior' => [
            ['Custom joinery', 'pcs', 0.35],
            ['Flooring', 'm²', 0.25],
            ['Lighting concept & installation', 'flat', 0.25],
            ['Painting & finishes', 'm²', 0.15],
        ],
        'repair' => [
            ['Damage assessment', 'h', 0.10],
            ['Removal of damaged materials', 'flat', 0.25],
            ['Repair works', 'm²', 0.50],
            ['Finishing & cleanup', 'flat', 0.15],
        ],
    ];

    private const LOST_REASONS = [
        'Chose another contractor',
        'Budget too low for the requested scope',
        'Project postponed to next year',
        'No response after three follow-ups',
        'Price — competitor 12% cheaper',
        'Financing not approved',
    ];

    public function run(): void
    {
        $this->faker = Factory::create('de_DE');
        $this->faker->seed(2026);
        mt_srand(2026); // array_rand()
        $this->now = now()->startOfHour();

        DB::transaction(function () {
            [$admin, $manager, $reps] = $this->seedUsers();
            $plans = $this->planLeads();
            $leads = $this->createLeads($plans, $reps);
            $quotes = $this->createQuotes($plans, $leads);
            $this->createProjectsAndInvoices($plans, $leads, $quotes, $manager);
            $this->createActivities($plans, $leads, $quotes);
        });
    }

    // ------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------

    /** @return array{0: User, 1: User, 2: Collection<int, User>} */
    private function seedUsers(): array
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Katrin Hoffmann',
            'email' => 'admin@buildlead.test',
            'phone' => '+49 30 5550 1000',
        ]);

        $manager = User::factory()->manager()->create([
            'name' => 'Thomas Becker',
            'email' => 'manager@buildlead.test',
            'phone' => '+49 30 5550 1001',
        ]);

        $repData = [
            ['Lukas Schneider', 'lukas.schneider', ['10', '13'], ['new_build', 'commercial_fit_out', 'extension']],
            ['Anna Weber', 'anna.weber', ['10', '12'], ['renovation', 'interior', 'extension']],
            ['Jonas Fischer', 'jonas.fischer', ['12', '15', '03'], ['roofing', 'repair', 'renovation']],
            ['Sophie Wagner', 'sophie.wagner', ['13', '16'], ['new_build', 'extension', 'renovation']],
            ['Felix Krüger', 'felix.krueger', ['14', '04'], ['commercial_fit_out', 'new_build', 'roofing']],
            ['Mia Schulz', 'mia.schulz', ['10', '14'], ['interior', 'renovation', 'repair']],
        ];

        $reps = collect($repData)->map(fn (array $r, int $i) => User::factory()->sales()->create([
            'name' => $r[0],
            'email' => $r[1].'@buildlead.test',
            'phone' => '+49 30 5550 10'.str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT),
            'regions' => $r[2],
            'project_types' => $r[3],
            'max_open_leads' => 15,
        ]));

        return [$admin, $manager, $reps];
    }

    // ------------------------------------------------------------------
    // Lead planning (attributes, scores, status, timeline)
    // ------------------------------------------------------------------

    /** @return list<array<string, mixed>> chronological */
    private function planLeads(): array
    {
        $f = $this->faker;
        $scorer = app(RuleBasedScorer::class);
        $plans = [];

        for ($i = 0; $i < 60; $i++) {
            $profile = $f->randomElement(['premium', 'premium', 'mid', 'mid', 'mid', 'mid', 'low', 'low', 'low']);
            $plans[] = $this->planLead($profile, $scorer);
        }

        // Spread arrivals over the last 12 calendar weeks (Mon-based, same window as the
        // dashboard chart), slightly more recent volume.
        $span = (int) $this->now->copy()->startOfWeek()->subWeeks(11)->diffInDays($this->now->copy()->startOfDay());
        // Evenly spaced quantiles + jitter (not pure random): steady weekly volume with
        // mild growth, so week-over-week trends look like a real, healthy business.
        foreach ($plans as $n => &$plan) {
            $u = ($n + $f->randomFloat(2, 0.05, 0.95)) / count($plans);
            $daysAgo = (int) floor($span * (1 - $u ** 0.92));
            $plan['created_at'] = $this->now->copy()->subDays($daysAgo)
                ->setTime($f->numberBetween(7, 19), $f->numberBetween(0, 59));
            if ($plan['created_at']->gt($this->now)) {
                $plan['created_at'] = $this->now->copy()->subHours($f->numberBetween(1, 6));
            }
        }
        unset($plan);

        usort($plans, fn ($a, $b) => $a['created_at'] <=> $b['created_at']);

        $this->assignStatuses($plans);

        foreach ($plans as &$plan) {
            $this->planTimeline($plan);
        }

        return $plans;
    }

    private function planLead(string $profile, RuleBasedScorer $scorer): array
    {
        $f = $this->faker;

        [$types, $budgetRange, $timelines, $sources, $locationPool] = match ($profile) {
            'premium' => [
                ['new_build', 'commercial_fit_out', 'extension', 'renovation'],
                [180_000, 1_200_000],
                ['asap', 'within_3_months', 'within_3_months', 'within_6_months'],
                ['referral', 'phone_call', 'trade_fair', 'website_form'],
                'core',
            ],
            'mid' => [
                ['renovation', 'extension', 'roofing', 'interior', 'commercial_fit_out', 'new_build'],
                [30_000, 220_000],
                ['within_3_months', 'within_6_months', 'this_year', 'asap'],
                ['website_form', 'email', 'phone_call', 'portal', 'referral'],
                'mixed',
            ],
            default => [
                ['repair', 'interior', 'roofing', 'renovation'],
                [8_000, 45_000],
                ['this_year', 'just_researching', 'within_6_months'],
                ['portal', 'email', 'website_form'],
                'outer',
            ],
        };

        $type = $f->randomElement($types);
        $location = match ($locationPool) {
            'core' => $f->randomElement(array_slice(self::LOCATIONS, 0, 23)),
            'mixed' => $f->randomElement(array_slice(self::LOCATIONS, 0, 32)),
            default => $f->randomElement(array_slice(self::LOCATIONS, 15)),
        };

        $budgetMax = (int) round($f->numberBetween(...$budgetRange), -3);
        $budgetMin = (int) max(8_000, round($budgetMax * $f->randomFloat(2, 0.6, 0.85), -3));
        $hasBudget = $profile !== 'low' || $f->boolean(75);

        $sqm = match ($type) {
            'repair' => $f->numberBetween(10, 80),
            'interior' => $f->numberBetween(40, 250),
            'roofing' => $f->numberBetween(80, 400),
            'renovation' => $f->numberBetween(60, 300),
            'extension' => $f->numberBetween(25, 120),
            'new_build' => $f->numberBetween(140, 900),
            'commercial_fit_out' => $f->numberBetween(150, 1500),
        };

        $isBusiness = in_array($type, ['commercial_fit_out'], true)
            || ($type === 'new_build' && $f->boolean(50))
            || $f->boolean(15);
        $company = $isBusiness
            ? $this->takeCompany($type === 'commercial_fit_out' ? 'commercial' : $f->randomElement(['commercial', 'developer']))
            : null;

        $first = $f->firstName();
        $last = $f->lastName();
        $emailLocal = Str::slug(Str::ascii($first.'.'.$last), '.');
        $email = $company
            ? $emailLocal.'@'.Str::slug(Str::ascii(preg_replace('/\b(GmbH|UG|KG|eG|gGmbH|GbR)\b/', '', $company))).'.example'
            : $emailLocal.'@example.com';

        $areaCode = match (true) {
            $location[1] === 'Berlin' => '30',
            str_starts_with($location[0], '14') => '331',
            str_starts_with($location[0], '03') => '355',
            default => '33'.$f->numberBetween(20, 99),
        };
        $phone = $f->boolean(55)
            ? '+49 '.$areaCode.' '.$f->numerify('### ####')
            : '+49 1'.$f->randomElement(['51', '52', '57', '60', '70', '76']).' '.$f->numerify('#######');

        $source = $f->randomElement($sources);
        $missingPhone = $profile === 'low' && $source !== 'phone_call' && $f->boolean(35);
        $missingEmail = $source === 'phone_call' && $f->boolean(30);

        $description = str_replace(
            ['{sqm}', '{area_name}'],
            [(string) $sqm, $location[2]],
            $f->randomElement(self::DESCRIPTIONS[$type])
        );

        $attributes = [
            'contact_name' => "$first $last",
            'company' => $company,
            'email' => $missingEmail ? null : mb_strtolower($email),
            'phone' => $missingPhone ? null : $phone,
            'source' => $source,
            'project_type' => $type,
            'description' => $description,
            'budget_min' => $hasBudget ? $budgetMin : null,
            'budget_max' => $hasBudget ? $budgetMax : null,
            'timeline' => $f->randomElement($timelines),
            'street' => $f->streetName().' '.$f->buildingNumber(),
            'postcode' => $location[0],
            'city' => $location[1],
            'area_sqm' => ($profile === 'low' && $f->boolean(30)) ? null : $sqm,
            'consent_given' => true,
        ];

        // Real rule score (same scorer as production), blended with a simulated
        // OpenAI opinion through the production blend → identical breakdown shape.
        $rules = $scorer->score(new Lead($attributes));
        $blender = app(LeadScoringService::class);
        $aiScore = (int) max(5, min(98, $rules['score'] + $f->numberBetween(-24, 6)));

        $draft = ['attributes' => $attributes, 'area_name' => $location[2]];
        $temperature = $blender->blend($rules, ['score' => $aiScore])->temperature;
        $result = $blender->blend($rules, [
            'score' => $aiScore,
            'reasons' => $this->aiReasons($draft),
            'summary' => $this->aiSummary($draft),
            'next_action' => LeadScoringService::defaultNextAction($temperature),
            'project_type' => $type,
        ]);

        return [
            'profile' => $profile,
            'attributes' => $attributes,
            'area_name' => $location[2],
            'result' => $result,
            'rule_score' => $result->ruleScore,
            'ai_score' => $result->aiScore,
            'score' => $result->score,
            'temperature' => $result->temperature,
            'estimated_value' => $hasBudget
                ? (int) round(($budgetMin + $budgetMax) / 2, -3)
                : (int) round($sqm * $f->numberBetween(350, 900), -3),
        ];
    }

    /** Older leads are further down the pipeline; better leads are likelier to be won. */
    private function assignStatuses(array &$plans): void
    {
        $f = $this->faker;

        // Oldest 20: the deals that had time to close. Won = 2 best short jobs
        // (can already be completed) + 4 best remaining by score.
        $oldest = range(0, 19);
        usort($oldest, fn ($a, $b) => $plans[$b]['score'] <=> $plans[$a]['score']);
        $short = array_values(array_filter($oldest, fn ($i) => in_array($plans[$i]['attributes']['project_type'], ['repair', 'roofing', 'interior'], true)));
        $won = array_slice($short, 0, 2);
        foreach ($oldest as $i) {
            if (count($won) >= 6) {
                break;
            }
            if (! in_array($i, $won, true)) {
                $won[] = $i;
            }
        }
        $rest = array_values(array_diff($oldest, $won)); // still score-ordered
        $restStatuses = array_merge(
            [LeadStatus::Negotiation, LeadStatus::QuoteSent],
            array_fill(0, 3, LeadStatus::SiteVisit),
            array_fill(0, 2, LeadStatus::Contacted),
            array_fill(0, 7, LeadStatus::Lost),
        );
        foreach ($won as $i) {
            $plans[$i]['status'] = LeadStatus::Won;
        }
        foreach ($rest as $n => $i) {
            $plans[$i]['status'] = $restStatuses[$n];
        }

        // Next 14: mid-pipeline, ranked by score with a little noise.
        $middle = range(20, 33);
        usort($middle, fn ($a, $b) => $plans[$b]['score'] <=> $plans[$a]['score']);
        $middleStatuses = array_merge(
            [LeadStatus::Negotiation],
            array_fill(0, 2, LeadStatus::QuoteSent),
            array_fill(0, 4, LeadStatus::SiteVisit),
            array_fill(0, 4, LeadStatus::Contacted),
            array_fill(0, 3, LeadStatus::Lost),
        );
        for ($k = 0; $k < 3; $k++) {
            $a = $f->numberBetween(1, 11);
            [$middleStatuses[$a], $middleStatuses[$a + 2]] = [$middleStatuses[$a + 2], $middleStatuses[$a]];
        }
        foreach ($middle as $n => $i) {
            $plans[$i]['status'] = $middleStatuses[$n];
        }

        $newerIdx = range(34, 59);

        // Newer 26 by age (oldest first): a few in progress, the newest still "new".
        $newerStatuses = array_merge(
            array_fill(0, 3, LeadStatus::SiteVisit),
            array_fill(0, 7, LeadStatus::Contacted),
            array_fill(0, 16, LeadStatus::New),
        );
        for ($k = 0; $k < 4; $k++) {
            $a = $f->numberBetween(0, 22);
            [$newerStatuses[$a], $newerStatuses[$a + 3]] = [$newerStatuses[$a + 3], $newerStatuses[$a]];
        }
        foreach ($newerIdx as $n => $idx) {
            $plans[$idx]['status'] = $newerStatuses[$n];
        }

        // Exactly one lost lead (the best-scoring one) had a quote that was declined.
        $lost = array_keys(array_filter($plans, fn ($p) => $p['status'] === LeadStatus::Lost));
        usort($lost, fn ($a, $b) => $plans[$b]['score'] <=> $plans[$a]['score']);
        foreach ($plans as $i => &$plan) {
            $plan['declined_quote'] = $i === ($lost[0] ?? null);
        }
    }

    /** Timestamps for each pipeline step, all between created_at and now. */
    private function planTimeline(array &$plan): void
    {
        $f = $this->faker;
        $status = $plan['status'];
        $created = $plan['created_at'];

        $steps = match ($status) {
            LeadStatus::New => [],
            LeadStatus::Contacted => ['contacted'],
            LeadStatus::SiteVisit => ['contacted', 'site_visit'],
            LeadStatus::QuoteSent => ['contacted', 'site_visit', 'quote_sent'],
            LeadStatus::Negotiation => ['contacted', 'site_visit', 'quote_sent', 'negotiation'],
            LeadStatus::Won => ['contacted', 'site_visit', 'quote_sent', 'won'],
            LeadStatus::Lost => $plan['declined_quote']
                ? ['contacted', 'site_visit', 'quote_sent', 'lost']
                : ($f->boolean() ? ['contacted', 'lost'] : ['contacted', 'site_visit', 'lost']),
        };

        // Hot leads get called within the hour; others within ~1–2 days.
        $firstContactHours = match ($plan['temperature']) {
            LeadTemperature::Hot => $f->numberBetween(0, 1) + $f->randomFloat(2, 0.2, 0.8),
            LeadTemperature::Warm => $f->numberBetween(3, 20),
            LeadTemperature::Cold => $f->numberBetween(20, 60),
        };

        $available = max(1, $created->diffInMinutes($this->now) - 60);
        $times = [];
        $cursor = $created->copy();
        foreach ($steps as $n => $step) {
            if ($n === 0) {
                $minutes = (int) min($firstContactHours * 60, $available * 0.3);
            } else {
                $remaining = max(1, $created->diffInMinutes($this->now) - $created->diffInMinutes($cursor) - 60);
                $share = 1 / (count($steps) - $n + 0.6);
                $minutes = (int) ($remaining * $share * $f->randomFloat(2, 0.5, 0.95));
                $minutes = min($minutes, 60 * 24 * $f->numberBetween(4, 14));
            }
            $cursor = $cursor->copy()->addMinutes(max(5, $minutes));
            if ($cursor->gte($this->now)) {
                $cursor = $this->now->copy()->subMinutes(30 * (count($steps) - $n));
            }
            $times[$step] = $cursor;
        }

        $plan['times'] = $times;
        $plan['first_contacted_at'] = $times['contacted'] ?? null;
        $plan['updated_at'] = $times ? end($times) : $created->copy()->addMinutes(2);
        $plan['lost_reason'] = $status === LeadStatus::Lost
            ? ($plan['declined_quote'] ? 'Price — competitor 12% cheaper' : $f->randomElement(self::LOST_REASONS))
            : null;
    }

    // ------------------------------------------------------------------
    // Leads + assignment
    // ------------------------------------------------------------------

    /** @return array<int, Lead> keyed by plan index */
    private function createLeads(array &$plans, $reps): array
    {
        $openCounts = $reps->mapWithKeys(fn (User $u) => [$u->id => 0])->all();
        $positions = [];
        $leads = [];

        foreach ($plans as $i => &$plan) {
            $a = $plan['attributes'];
            $rep = $this->pickRep($reps, $a['postcode'], $a['project_type'], $plan['temperature'], $openCounts);
            if (! $plan['status']->isClosed()) {
                $openCounts[$rep->id]++;
            }
            $plan['rep'] = $rep;

            $status = $plan['status']->value;
            $positions[$status] = ($positions[$status] ?? -1) + 1;

            $leads[$i] = Lead::factory()->create(array_merge($a, [
                'score' => $plan['score'],
                'rule_score' => $plan['rule_score'],
                'ai_score' => $plan['ai_score'],
                'temperature' => $plan['temperature'],
                'score_breakdown' => $plan['result']->toBreakdown(),
                'ai_summary' => $plan['result']->summary,
                'ai_next_action' => $plan['result']->nextAction,
                'scored_at' => $plan['created_at']->copy()->addMinute(),
                'status' => $plan['status'],
                'assigned_to' => $rep->id,
                'assigned_at' => $plan['created_at']->copy()->addMinutes(2),
                'first_contacted_at' => $plan['first_contacted_at'],
                'estimated_value' => $plan['estimated_value'],
                'lost_reason' => $plan['lost_reason'],
                'position' => $positions[$status],
                'consent_at' => $plan['created_at'],
                'raw_payload' => $this->rawPayload($plan),
                'created_at' => $plan['created_at'],
                'updated_at' => $plan['updated_at'],
            ]));
        }
        unset($plan);

        return $leads;
    }

    /** Mirrors the production rule: capacity → region/type match → hot: best win rate, else least busy. */
    private function pickRep($reps, string $postcode, string $type, LeadTemperature $temperature, array $openCounts): User
    {
        $available = $reps->filter(fn (User $u) => $openCounts[$u->id] < $u->max_open_leads);
        if ($available->isEmpty()) {
            $available = $reps;
        }

        $both = $available->filter(fn (User $u) => $u->coversPostcode($postcode) && in_array($type, $u->project_types, true));
        $either = $available->filter(fn (User $u) => $u->coversPostcode($postcode) || in_array($type, $u->project_types, true));
        $pool = $both->isNotEmpty() ? $both : ($either->isNotEmpty() ? $either : $available);

        return $temperature === LeadTemperature::Hot
            ? $pool->sortByDesc(fn (User $u) => self::WIN_RATES[$u->email] ?? 0)->first()
            : $pool->sortBy(fn (User $u) => [$openCounts[$u->id], $u->id])->first();
    }

    // ------------------------------------------------------------------
    // Quotes
    // ------------------------------------------------------------------

    /** @return array<int, Quote> keyed by plan index */
    private function createQuotes(array $plans, array $leads): array
    {
        $f = $this->faker;
        $toCreate = [];

        foreach ($plans as $i => $plan) {
            if (! isset($plan['times']['quote_sent'])) {
                continue;
            }
            $toCreate[] = $i;
        }
        usort($toCreate, fn ($a, $b) => $plans[$a]['times']['quote_sent'] <=> $plans[$b]['times']['quote_sent']);

        $quotes = [];
        foreach ($toCreate as $i) {
            $plan = $plans[$i];
            $sentAt = $plan['times']['quote_sent'];
            $status = match (true) {
                $plan['status'] === LeadStatus::Won => QuoteStatus::Accepted,
                $plan['status'] === LeadStatus::Lost => QuoteStatus::Declined,
                default => QuoteStatus::Sent,
            };

            $quote = Quote::factory()->create([
                'lead_id' => $leads[$i]->id,
                'created_by' => $plan['rep']->id,
                'status' => $status,
                'valid_until' => $sentAt->copy()->addDays(30)->toDateString(),
                'sent_at' => $sentAt,
                'accepted_at' => $status === QuoteStatus::Accepted ? $plan['times']['won'] : null,
                'notes' => 'Alle Preise netto zzgl. 19 % MwSt. Angebot gültig 30 Tage. Unvorhergesehene statische Arbeiten werden nach Aufwand abgerechnet.',
                'created_at' => $sentAt->copy()->subHours($f->numberBetween(2, 20)),
                'updated_at' => $plan['times']['won'] ?? $plan['times']['lost'] ?? $sentAt,
            ]);

            $this->addQuoteItems($quote, $plan);
            $quote->recalculate();

            // The quote is the best estimate of deal value from here on.
            // Query update with explicit updated_at: keep the lead's real last-activity time.
            Lead::query()->whereKey($leads[$i]->id)->update(['estimated_value' => $quote->subtotal, 'updated_at' => $plan['updated_at']]);

            $quotes[$i] = $quote;
        }

        return $quotes;
    }

    private function addQuoteItems(Quote $quote, array $plan): void
    {
        $target = (float) ($plan['attributes']['budget_max'] ?? $plan['estimated_value'])
            * $this->faker->randomFloat(2, 0.82, 0.97);
        $sqm = $plan['attributes']['area_sqm'] ?? 100;

        foreach (self::QUOTE_LINES[$plan['attributes']['project_type']] as $sort => [$description, $unit, $share]) {
            $lineTarget = $target * $share;
            [$quantity, $unitPrice] = match ($unit) {
                'm²' => [$sqm, $lineTarget / $sqm],
                'h' => [max(4, (int) round($lineTarget / 68)), 68],
                'pcs' => [$q = max(1, min(12, (int) round($sqm / 40))), $lineTarget / $q],
                default => [1, $lineTarget],
            };

            $quote->items()->create([
                'description' => $description,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => round($unitPrice, 2),
                'sort' => $sort,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Projects + invoices
    // ------------------------------------------------------------------

    private function createProjectsAndInvoices(array $plans, array $leads, array $quotes, User $manager): void
    {
        $f = $this->faker;
        $durations = [
            'new_build' => 40, 'commercial_fit_out' => 12, 'extension' => 14,
            'renovation' => 9, 'roofing' => 3, 'interior' => 5, 'repair' => 2,
        ];
        $won = array_keys(array_filter($plans, fn ($p) => $p['status'] === LeadStatus::Won));
        // Most-finished first: weeks since acceptance ÷ planned duration.
        $ratio = fn ($i) => $plans[$i]['times']['won']->diffInDays($this->now) / 7 / $durations[$plans[$i]['attributes']['project_type']];
        usort($won, fn ($a, $b) => $ratio($b) <=> $ratio($a));

        // Oldest win → completed … newest → planning. [status, progress, invoice plan]
        // invoice plan: [type, share of net, position in timeline (0–1), forced status|null]
        $blueprints = [
            [ProjectStatus::Completed, 100, [['deposit', 0.3, 0.05, null], ['progress', 0.4, 0.45, null], ['final', 0.3, 0.8, null]]],
            [ProjectStatus::Completed, 100, [['deposit', 0.3, 0.05, null], ['progress', 0.4, 0.4, null], ['final', 0.3, 0.85, null]]],
            [ProjectStatus::InProgress, 65, [['deposit', 0.3, 0.05, null], ['progress', 0.3, 0.45, null], ['progress', 0.2, 0.85, null]]],
            [ProjectStatus::InProgress, 40, [['deposit', 0.3, 0.05, null], ['progress', 0.25, 0.35, 'overdue'], ['progress', 0.2, 0.9, 'draft']]],
            [ProjectStatus::OnHold, 30, [['deposit', 0.3, 0.1, null], ['progress', 0.2, 0.3, 'overdue']]],
            [ProjectStatus::Planning, 0, [['deposit', 0.3, 0.6, null]]],
        ];

        $invoicePlans = [];
        foreach ($won as $n => $i) {
            [$status, $progress, $invoiceBlueprint] = $blueprints[$n];
            $plan = $plans[$i];
            $quote = $quotes[$i];
            $lead = $leads[$i];
            $acceptedAt = $plan['times']['won'];
            $a = $plan['attributes'];

            $start = $status === ProjectStatus::Planning
                ? $this->now->copy()->addDays($f->numberBetween(7, 21))
                : $acceptedAt->copy()->addDays($f->numberBetween(3, 7));
            $durationWeeks = $durations[$a['project_type']];
            $due = $status === ProjectStatus::Completed
                ? Carbon::instance(min($this->now->copy()->subDays($f->numberBetween(2, 10)), $start->copy()->addWeeks($durationWeeks)))
                : $start->copy()->addWeeks($durationWeeks);

            $budget = (float) $quote->subtotal;
            $spent = $progress === 0 ? 0 : round($budget * $progress / 100 * $f->randomFloat(2, 0.88, 1.06), -2);

            $typeLabel = ProjectType::from($a['project_type'])->label();
            $project = Project::factory()->create([
                'lead_id' => $lead->id,
                'quote_id' => $quote->id,
                'manager_id' => $manager->id,
                'name' => $typeLabel.' · '.($a['company'] ?? Str::afterLast($a['contact_name'], ' ')).' · '.$plan['area_name'],
                'site_address' => $a['street'].', '.$a['postcode'].' '.$a['city'],
                'status' => $status,
                'progress' => $progress,
                'budget' => $budget,
                'spent' => $spent,
                'start_date' => $start->toDateString(),
                'due_date' => $due->toDateString(),
                'created_at' => $acceptedAt->copy()->addHours(2),
                'updated_at' => $acceptedAt->copy()->addHours(2),
            ]);

            foreach ($invoiceBlueprint as [$type, $share, $position, $forced]) {
                $issued = $acceptedAt->copy()->addSeconds((int) ($acceptedAt->diffInSeconds($this->now) * $position))->startOfDay();
                $invoicePlans[] = compact('project', 'type', 'share', 'issued', 'forced', 'budget');
            }
        }

        // Create invoices in issue order so INV numbers are chronological (drafts last).
        usort($invoicePlans, fn ($a, $b) => [$a['forced'] === 'draft', $a['issued']] <=> [$b['forced'] === 'draft', $b['issued']]);

        foreach ($invoicePlans as $p) {
            $issued = $p['issued'];
            $dueAt = $issued->copy()->addDays(14);

            if ($p['forced'] === 'draft') {
                $status = InvoiceStatus::Draft;
                $issued = $dueAt = $paidAt = null;
            } elseif ($dueAt->gte($this->now)) {
                $status = InvoiceStatus::Sent;
                $paidAt = null;
            } elseif ($p['forced'] === 'overdue') {
                $status = InvoiceStatus::Overdue;
                $paidAt = null;
            } else {
                $status = InvoiceStatus::Paid;
                $paidAt = Carbon::instance(min($this->now, $issued->copy()->addDays($f->numberBetween(4, 13))->setTime(10, 0)));
            }

            Invoice::factory()->create([
                'project_id' => $p['project']->id,
                'type' => $p['type'],
                'status' => $status,
                'amount' => round($p['budget'] * $p['share'], 2),
                'issued_at' => $issued?->toDateString(),
                'due_at' => $dueAt?->toDateString(),
                'paid_at' => $paidAt,
                'created_at' => $issued ?? $this->now->copy()->subDay(),
                'updated_at' => $paidAt ?? $issued ?? $this->now->copy()->subDay(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Activities
    // ------------------------------------------------------------------

    private function createActivities(array $plans, array $leads, array $quotes): void
    {
        $f = $this->faker;
        $rows = [];

        foreach ($plans as $i => $plan) {
            $lead = $leads[$i];
            $rep = $plan['rep'];
            $created = $plan['created_at'];
            $source = LeadSource::from($plan['attributes']['source']);
            $city = $plan['attributes']['city'];

            $rows[] = [$lead, null, ActivityType::Created, 'Lead received via '.$source->label(), null,
                ['source' => $source->value], null, $created];
            $rows[] = [$lead, null, ActivityType::Score, "Scored {$plan['score']} · {$plan['temperature']->label()}",
                $plan['result']->summary,
                ['score' => $plan['score'], 'rule_score' => $plan['rule_score'], 'ai_score' => $plan['ai_score'], 'temperature' => $plan['temperature']->value, 'method' => 'hybrid'],
                null, $created->copy()->addMinute()];
            $rows[] = [$lead, null, ActivityType::Assignment, 'Assigned to '.$rep->name,
                $plan['temperature'] === LeadTemperature::Hot ? 'Hot lead → rep with the best win rate in region.' : 'Least busy rep matching region / project type.',
                ['user_id' => $rep->id], null, $created->copy()->addMinutes(2)];

            if ($plan['temperature'] === LeadTemperature::Hot) {
                $rows[] = [$lead, null, ActivityType::Note, '🔥 Hot lead alert sent', 'Notified '.$rep->name.' by email and in-app.',
                    ['channels' => ['mail', 'database']], null, $created->copy()->addMinutes(3)];
            }

            $previous = LeadStatus::New;
            foreach ($plan['times'] as $step => $at) {
                switch ($step) {
                    case 'contacted':
                        $rows[] = [$lead, $rep, ActivityType::Call, 'Intro call with '.Str::before($plan['attributes']['contact_name'], ' '),
                            $f->randomElement([
                                'Discussed scope and budget. Customer is comparing 2–3 offers.',
                                'Kurzes Erstgespräch, Kunde wünscht Vor-Ort-Termin.',
                                'Reached on second attempt. Very interested, wants to move quickly.',
                                'Telefonat geführt, Unterlagen (Pläne, Fotos) per E-Mail angefordert.',
                            ]), ['duration_min' => $f->numberBetween(6, 28)], null, $at];
                        break;
                    case 'site_visit':
                        $rows[] = [$lead, $rep, ActivityType::SiteVisit, "Site visit in {$city}",
                            $f->randomElement([
                                'Measured all rooms, took photos. Structure in good condition.',
                                'Vor-Ort-Termin durchgeführt. Leitungen müssen komplett erneuert werden.',
                                'Walked the site with the architect; access for machinery is limited.',
                            ]), null, null, $at];
                        break;
                    case 'quote_sent':
                        $q = $quotes[$i] ?? null;
                        $rows[] = [$lead, $rep, ActivityType::Quote, 'Quote '.($q?->number ?? '').' sent',
                            $q ? 'Net € '.number_format((float) $q->subtotal, 0, ',', '.').' · valid until '.$q->valid_until->format('j M Y') : null,
                            $q ? ['quote_id' => $q->id] : null, null, $at];
                        break;
                    case 'negotiation':
                        $rows[] = [$lead, $rep, ActivityType::Meeting, 'Negotiation meeting',
                            'Customer asked for alternative materials to reduce cost by ~8%.', null, null, $at];
                        break;
                }

                $next = match ($step) {
                    'contacted' => LeadStatus::Contacted, 'site_visit' => LeadStatus::SiteVisit,
                    'quote_sent' => LeadStatus::QuoteSent, 'negotiation' => LeadStatus::Negotiation,
                    'won' => LeadStatus::Won, 'lost' => LeadStatus::Lost,
                };
                $rows[] = [$lead, $rep, ActivityType::StatusChange, $previous->label().' → '.$next->label(),
                    $next === LeadStatus::Lost ? 'Reason: '.$plan['lost_reason'] : ($next === LeadStatus::Won ? 'Quote accepted 🎉' : null),
                    ['from' => $previous->value, 'to' => $next->value], null, $at->copy()->addMinute()];
                $previous = $next;
            }

            // Follow-up tasks on open leads (some overdue, most upcoming).
            if (! $plan['status']->isClosed() && $f->boolean(65)) {
                $due = $f->boolean(20)
                    ? $this->now->copy()->subDays($f->numberBetween(1, 3))->setTime(10, 0)
                    : $this->now->copy()->addDays($f->numberBetween(0, 6))->setTime($f->randomElement([9, 10, 11, 14, 15, 16]), 0);
                $rows[] = [$lead, $rep, $f->randomElement([ActivityType::Call, ActivityType::Email, ActivityType::Meeting]),
                    $f->randomElement(['Follow-up call', 'Send reference projects', 'Clarify budget', 'Schedule site visit', 'Rückruf vereinbaren']),
                    null, null, $due, Carbon::instance(min($this->now, $plan['updated_at']->copy()->addMinutes(5)))];
            }
        }

        foreach ($rows as [$lead, $user, $type, $title, $body, $meta, $due, $at]) {
            $activity = new LeadActivity([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'meta' => $meta,
                'due_at' => $due,
            ]);
            $activity->created_at = $at;
            $activity->updated_at = $at;
            $activity->save();
        }
    }

    private function takeCompany(string $pool): string
    {
        if (empty($this->companyPool[$pool])) {
            $pool = $pool === 'commercial' ? 'developer' : 'commercial';
        }
        if (empty($this->companyPool[$pool])) {
            return $this->faker->company();
        }

        $key = array_rand($this->companyPool[$pool]);
        $company = $this->companyPool[$pool][$key];
        unset($this->companyPool[$pool][$key]);

        return $company;
    }

    // ------------------------------------------------------------------
    // Simulated AI copy
    // ------------------------------------------------------------------

    private function aiReasons(array $plan): array
    {
        $a = $plan['attributes'];
        $reasons = [];

        if ($a['budget_max']) {
            $reasons[] = 'Budget € '.$this->money($a['budget_min']).'–'.$this->money($a['budget_max']).
                ((float) $a['budget_max'] >= 250_000 ? ' — high-value project' : ((float) $a['budget_max'] < 30_000 ? ' — small ticket' : ' — solid mid-size job'));
        } else {
            $reasons[] = 'No budget given — needs qualification';
        }

        $prefix = substr($a['postcode'], 0, 2);
        $reasons[] = in_array($prefix, ScoringSetting::config('location.core_prefixes'), true)
            ? "Core service area ({$a['postcode']} {$a['city']})"
            : (in_array($prefix, ScoringSetting::config('location.secondary_prefixes'), true)
                ? "Outer service area ({$a['city']}) — longer travel"
                : "Outside service area ({$a['city']})");

        $reasons[] = match ($a['timeline']) {
            'asap' => 'Wants to start immediately',
            'within_3_months' => 'Clear start within 3 months',
            'within_6_months' => 'Start within 6 months',
            'this_year' => 'Vague timing (this year)',
            default => 'Only researching — low urgency',
        };

        if ($a['source'] === 'referral') {
            $reasons[] = 'Referral — historically highest close rate';
        }
        if (! $a['phone']) {
            $reasons[] = 'No phone number provided';
        }
        if ($a['company'] && $a['project_type'] === 'commercial_fit_out') {
            $reasons[] = 'Commercial client with signed lease — decision-maker in contact';
        }

        return array_slice($reasons, 0, 4);
    }

    private function aiSummary(array $plan): string
    {
        $a = $plan['attributes'];
        $type = ProjectType::from($a['project_type'])->label();
        $timeline = Timeline::from($a['timeline'])->label();
        $who = $a['company'] ?? 'Private client';
        $budget = $a['budget_max'] ? 'budget € '.$this->money($a['budget_min']).'–'.$this->money($a['budget_max']) : 'no budget stated';
        $size = $a['area_sqm'] ? ", ~{$a['area_sqm']} m²" : '';

        return "{$who}: {$type} in {$plan['area_name']}{$size}, {$budget}. Timeline: {$timeline}.";
    }

    private function rawPayload(array $plan): array
    {
        $f = $this->faker;

        return match ($plan['attributes']['source']) {
            'website_form' => ['form' => 'anfrage', 'page' => '/leistungen/'.str_replace('_', '-', $plan['attributes']['project_type']), 'utm_source' => $f->randomElement(['google', 'direct', 'instagram'])],
            'email' => ['subject' => $f->randomElement(['Anfrage Bauprojekt', 'Request for quote', 'Angebot Sanierung']), 'received_by' => 'anfragen@buildlead.test'],
            'phone_call' => ['duration_sec' => $f->numberBetween(90, 900), 'taken_by' => 'reception'],
            'portal' => ['portal' => $f->randomElement(['MyHammer', 'Houzz', 'Check24']), 'portal_ref' => strtoupper($f->bothify('??-#####'))],
            'trade_fair' => ['event' => 'BAU Berlin 2026', 'badge_scan' => true],
            default => ['referred_by' => $f->name()],
        };
    }

    private function money(int|float|string|null $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }
}
