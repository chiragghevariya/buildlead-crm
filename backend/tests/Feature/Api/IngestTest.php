<?php

namespace Tests\Feature\Api;

use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-ingest-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.leads.ingest_token' => self::TOKEN]);
        Queue::fake();
    }

    private function ingest(string $channel, array $payload, ?string $token = self::TOKEN)
    {
        return $this->withHeaders(array_filter(['X-Ingest-Token' => $token]))
            ->postJson("/api/ingest/{$channel}", $payload);
    }

    private function websiteForm(array $overrides = []): array
    {
        return array_merge([
            'vorname' => 'Lena',
            'nachname' => 'Hartmann',
            'E-Mail' => 'Lena.Hartmann@Example.com',
            'telefon' => '030 555 1234',
            'projektart' => 'Dachgeschossausbau',
            'budget' => '80.000-120.000 €',
            'zeitraum' => 'in 1-3 Monaten',
            'strasse' => 'Kastanienallee 7',
            'plz' => '10437',
            'ort' => 'Berlin',
            'flaeche' => '85 m²',
            'nachricht' => 'Wir möchten unser Dachgeschoss ausbauen.',
            'datenschutz' => 'on',
        ], $overrides);
    }

    // ---- auth / limits ------------------------------------------------------

    public function test_requires_valid_token(): void
    {
        $this->ingest('website', $this->websiteForm(), token: null)->assertUnauthorized();
        $this->ingest('website', $this->websiteForm(), token: 'wrong')->assertUnauthorized();
        $this->assertSame(0, Lead::count());
    }

    public function test_disabled_when_no_token_configured(): void
    {
        config(['services.leads.ingest_token' => null]);

        $this->ingest('website', $this->websiteForm())->assertStatus(503);
    }

    public function test_unknown_channel_is_404(): void
    {
        $this->ingest('fax', $this->websiteForm())->assertNotFound();
    }

    public function test_rate_limited_per_ip(): void
    {
        config(['services.leads.ingest_rate_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->ingest('website', $this->websiteForm(['E-Mail' => "p{$i}@example.com", 'telefon' => "030 555 00{$i}0"]))->assertCreated();
        }

        $this->ingest('website', $this->websiteForm(['E-Mail' => 'p9@example.com']))->assertStatus(429);
    }

    public function test_bad_tokens_are_rate_limited_too(): void
    {
        config(['services.leads.ingest_rate_per_minute' => 2]);

        $this->ingest('website', [], token: 'guess1')->assertUnauthorized();
        $this->ingest('website', [], token: 'guess2')->assertUnauthorized();
        $this->ingest('website', [], token: 'guess3')->assertStatus(429);
    }

    // ---- parsing ----------------------------------------------------------------

    public function test_website_form_is_normalised_and_queued_for_scoring(): void
    {
        $response = $this->ingest('website', $this->websiteForm());

        $response->assertCreated()
            ->assertJsonPath('status', 'created')
            ->assertJsonPath('reference', 'BL-'.now()->year.'-0001')
            ->assertJsonMissingPath('lead'); // public endpoint leaks nothing else

        $lead = Lead::sole();
        $this->assertSame('Lena Hartmann', $lead->contact_name);
        $this->assertSame('lena.hartmann@example.com', $lead->email);
        $this->assertSame('website_form', $lead->source->value);
        $this->assertSame('extension', $lead->project_type->value);
        $this->assertSame('within_3_months', $lead->timeline->value);
        $this->assertSame('80000.00', $lead->budget_min);
        $this->assertSame('120000.00', $lead->budget_max);
        $this->assertSame(85, $lead->area_sqm);
        $this->assertSame('10437', $lead->postcode);
        $this->assertSame('new', $lead->status->value);
        $this->assertTrue($lead->consent_given);
        $this->assertNotNull($lead->consent_at);
        $this->assertSame('Lena', $lead->raw_payload['vorname']);
        $this->assertSame('Lead received via Website form', $lead->activities()->sole()->title);

        Queue::assertPushed(ScoreLeadJob::class, fn ($job) => $job->lead->is($lead) && $job->autoAssign);
    }

    public function test_email_payload_uses_subject_and_body(): void
    {
        $this->ingest('email', [
            'from_name' => 'Tom Berger',
            'from_email' => 'tom@example.com',
            'subject' => 'Anfrage Sanierung',
            'body' => 'Budget ca. 60k, so schnell wie möglich.',
            'budget' => '60k',
            'timeline' => 'so schnell wie möglich',
            'consent' => true,
        ])->assertCreated();

        $lead = Lead::sole();
        $this->assertSame('email', $lead->source->value);
        $this->assertStringStartsWith('Anfrage Sanierung', $lead->description);
        $this->assertSame('asap', $lead->timeline->value);
        $this->assertSame('60000.00', $lead->budget_max);
    }

    public function test_call_with_phone_only(): void
    {
        $this->ingest('call', ['caller_name' => 'Uwe Kraft', 'caller_id' => '+49 331 9876543', 'notes' => 'Wasserschaden im Keller', 'consent' => 'yes'])
            ->assertCreated();

        $this->assertSame('phone_call', Lead::sole()->source->value);
        $this->assertNull(Lead::sole()->email);
    }

    public function test_unknown_project_type_is_kept_in_description(): void
    {
        $this->ingest('website', $this->websiteForm(['projektart' => 'Gartenhaus', 'nachricht' => 'Holz bitte']))->assertCreated();

        $lead = Lead::sole();
        $this->assertNull($lead->project_type);
        $this->assertStringStartsWith("Project type: Gartenhaus\nHolz bitte", $lead->description);
    }

    // ---- validation -----------------------------------------------------------------

    public function test_gdpr_consent_is_required(): void
    {
        $this->ingest('website', $this->websiteForm(['datenschutz' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent' => 'GDPR consent is required']);

        $this->ingest('website', $this->websiteForm(['datenschutz' => 'no']))->assertUnprocessable();
        $this->assertSame(0, Lead::count());
    }

    public function test_name_and_a_contact_channel_are_required(): void
    {
        $this->ingest('website', ['datenschutz' => 'on'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact_name', 'email', 'phone']);
    }

    public function test_invalid_email_phone_and_postcode_are_rejected(): void
    {
        $this->ingest('website', $this->websiteForm(['E-Mail' => 'not-an-email', 'telefon' => 'call me', 'plz' => '1234']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone', 'postcode']);
    }

    public function test_honeypot_submissions_are_silently_dropped(): void
    {
        $this->ingest('website', $this->websiteForm(['_hp' => 'http://spam.example']))
            ->assertStatus(202)
            ->assertExactJson(['status' => 'received']);

        $this->assertSame(0, Lead::count());
        Queue::assertNothingPushed();
    }

    // ---- de-duplication ------------------------------------------------------------

    public function test_same_email_within_30_days_is_merged(): void
    {
        $this->ingest('website', $this->websiteForm(['telefon' => null, 'strasse' => null]))->assertCreated();
        Queue::fake(); // reset

        $this->ingest('email', [
            'from_name' => 'Lena Hartmann', 'from_email' => 'LENA.HARTMANN@example.com',
            'body' => 'Noch eine Frage zum Angebot', 'phone' => '030 555 1234', 'consent' => true,
        ])->assertOk()->assertJsonPath('status', 'duplicate');

        $lead = Lead::sole();
        $this->assertSame('030 555 1234', $lead->phone); // filled the gap
        $note = $lead->activities()->where('title', 'Repeat enquiry via Email')->sole();
        $this->assertSame('Noch eine Frage zum Angebot', $note->body);
        $this->assertSame(['phone'], $note->meta['filled']);
        Queue::assertPushed(ScoreLeadJob::class, 1); // phone affects completeness → re-score
    }

    public function test_same_phone_in_another_format_is_merged(): void
    {
        $this->ingest('website', $this->websiteForm())->assertCreated();

        $this->ingest('call', ['name' => 'Lena H.', 'phone' => '+49 (30) 555-1234', 'consent' => true])
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, Lead::count());
        $this->assertSame('Lena Hartmann', Lead::sole()->contact_name); // existing data is never overwritten
    }

    public function test_duplicates_older_than_30_days_create_a_new_lead(): void
    {
        $this->ingest('website', $this->websiteForm())->assertCreated();
        Lead::query()->update(['created_at' => now()->subDays(31)]);

        $this->ingest('website', $this->websiteForm())->assertCreated()->assertJsonPath('status', 'created');

        $this->assertSame(2, Lead::count());
    }

    public function test_deleted_leads_are_not_used_for_dedupe(): void
    {
        $this->ingest('website', $this->websiteForm())->assertCreated();
        Lead::sole()->delete();

        $this->ingest('website', $this->websiteForm())->assertCreated();
    }
}
