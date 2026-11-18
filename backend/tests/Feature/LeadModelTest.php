<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_references_are_sequential_per_year_and_survive_soft_deletes(): void
    {
        $a = Lead::factory()->create();
        $b = Lead::factory()->create();
        $b->delete();
        $c = Lead::factory()->create();

        $year = now()->year;
        $this->assertSame("BL-{$year}-0001", $a->reference);
        $this->assertSame("BL-{$year}-0002", $b->reference);
        $this->assertSame("BL-{$year}-0003", $c->reference);
    }

    public function test_other_documents_get_their_own_prefixes(): void
    {
        $quote = Quote::factory()->create();
        $project = Project::factory()->create();
        $invoice = Invoice::factory()->create(['project_id' => $project->id]);

        $year = now()->year;
        $this->assertSame("Q-{$year}-0001", $quote->number);
        $this->assertSame("P-{$year}-0001", $project->code);
        $this->assertSame("INV-{$year}-0001", $invoice->number);
    }

    public function test_invoice_and_quote_totals_include_19_percent_vat(): void
    {
        $invoice = Invoice::factory()->create(['amount' => 1000]);
        $this->assertSame('190.00', $invoice->vat_amount);
        $this->assertSame('1190.00', $invoice->total);

        $quote = Quote::factory()->create();
        $quote->items()->create(['description' => 'Flooring', 'quantity' => 10, 'unit' => 'm²', 'unit_price' => 50]);
        $quote->items()->create(['description' => 'PM', 'quantity' => 2, 'unit' => 'h', 'unit_price' => 75]);
        $quote->recalculate();

        $this->assertSame('650.00', $quote->subtotal);
        $this->assertSame('123.50', $quote->vat_amount);
        $this->assertSame('773.50', $quote->total);
    }

    public function test_hot_and_open_scopes(): void
    {
        Lead::factory()->hot()->create();
        Lead::factory()->cold()->create();
        Lead::factory()->hot()->status(LeadStatus::Won)->create();

        $this->assertSame(2, Lead::hot()->count());
        $this->assertSame(2, Lead::open()->count());
        $this->assertSame(1, Lead::hot()->open()->count());
    }

    public function test_visible_to_limits_sales_to_own_leads(): void
    {
        $rep = User::factory()->sales()->create();
        $other = User::factory()->sales()->create();
        $manager = User::factory()->manager()->create();

        Lead::factory()->count(2)->assignedTo($rep)->create();
        Lead::factory()->assignedTo($other)->create();
        Lead::factory()->create(); // unassigned

        $this->assertSame(2, Lead::visibleTo($rep)->count());
        $this->assertSame(4, Lead::visibleTo($manager)->count());
    }

    public function test_search_matches_reference_name_company_and_city_case_insensitively(): void
    {
        $lead = Lead::factory()->create([
            'contact_name' => 'Jürgen Möller',
            'company' => 'Spreeblick Immobilien GmbH',
            'city' => 'Potsdam',
        ]);
        Lead::factory()->create(['contact_name' => 'Someone Else', 'company' => null, 'city' => 'Cottbus']);

        $this->assertSame([$lead->id], Lead::search('spreeblick')->pluck('id')->all());
        $this->assertSame([$lead->id], Lead::search('POTSDAM')->pluck('id')->all());
        $this->assertSame([$lead->id], Lead::search($lead->reference)->pluck('id')->all());
        $this->assertSame(2, Lead::search('')->count());
    }

    public function test_temperature_bands_follow_config(): void
    {
        $this->assertSame(LeadTemperature::Hot, LeadTemperature::fromScore(75));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore(74));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore(50));
        $this->assertSame(LeadTemperature::Cold, LeadTemperature::fromScore(49));
    }

    public function test_lead_status_pipeline_order_and_labels(): void
    {
        $this->assertSame(
            ['new', 'contacted', 'site_visit', 'quote_sent', 'negotiation', 'won', 'lost'],
            array_map(fn (LeadStatus $s) => $s->value, LeadStatus::pipeline())
        );
        $this->assertSame('Site visit', LeadStatus::SiteVisit->label());
    }
}
