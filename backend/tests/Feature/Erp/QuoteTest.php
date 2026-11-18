<?php

namespace Tests\Feature\Erp;

use App\Enums\LeadStatus;
use App\Enums\QuoteStatus;
use App\Mail\QuoteMail;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private User $rep;

    private User $manager;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $this->manager = User::factory()->manager()->create(['name' => 'Thomas Becker']);
        $this->rep = User::factory()->sales()->create(['name' => 'Anna Weber']);
        $this->lead = Lead::factory()->assignedTo($this->rep)->create([
            'contact_name' => 'Lena Hartmann', 'company' => null, 'email' => 'lena@example.com',
            'status' => LeadStatus::SiteVisit, 'project_type' => 'extension', 'city' => 'Berlin',
            'street' => 'Kastanienallee 7', 'postcode' => '10437',
        ]);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    private function items(): array
    {
        return [
            ['description' => 'Drywall partitions', 'quantity' => 85.5, 'unit' => 'm²', 'unit_price' => 64.9],
            ['description' => 'Electrical installation', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 7450],
            ['description' => 'Site management', 'quantity' => 12, 'unit' => 'h', 'unit_price' => 68],
            ['description' => 'Skylight windows', 'quantity' => 3, 'unit' => 'pcs', 'unit_price' => 1249.99],
        ];
    }

    private function createQuote(array $overrides = []): Quote
    {
        $id = $this->as($this->rep)->postJson('/api/quotes', array_merge(['lead_id' => $this->lead->id, 'items' => $this->items()], $overrides))
            ->assertCreated()->json('data.id');

        return Quote::findOrFail($id);
    }

    // ---- totals ---------------------------------------------------------------

    public function test_create_calculates_line_totals_subtotal_vat_and_total(): void
    {
        $response = $this->as($this->rep)->postJson('/api/quotes', ['lead_id' => $this->lead->id, 'items' => $this->items()]);

        // 85.5 × 64.90 = 5548.95 · 7450 · 12 × 68 = 816 · 3 × 1249.99 = 3749.97
        $response->assertCreated()
            ->assertJsonPath('data.number', 'Q-'.now()->year.'-0001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.line_total', 5548.95)
            ->assertJsonPath('data.items.3.line_total', 3749.97)
            ->assertJsonPath('data.subtotal', 17564.92)
            ->assertJsonPath('data.vat_rate', 19)
            ->assertJsonPath('data.vat_amount', 3337.33)   // 17564.92 × 0.19 = 3337.3348
            ->assertJsonPath('data.total', 20902.25)
            ->assertJsonPath('data.creator.id', $this->rep->id)
            ->assertJsonPath('data.valid_until', now()->addDays(30)->toDateString());

        $this->assertSame(['Drywall partitions', 'Electrical installation', 'Site management', 'Skylight windows'],
            Quote::sole()->items->pluck('description')->all());
        $this->assertSame('Quote Q-'.now()->year.'-0001 drafted', $this->lead->activities()->sole()->title);
    }

    public function test_reduced_vat_rate_and_editing_a_draft_recalculates(): void
    {
        $quote = $this->createQuote(['vat_rate' => 7]);
        $this->assertSame('1229.54', $quote->vat_amount); // 17564.92 × 0.07 = 1229.5444

        $this->as($this->rep)->patchJson("/api/quotes/{$quote->id}", [
            'items' => [['description' => 'Flat rate', 'quantity' => 2, 'unit' => 'flat', 'unit_price' => 1000.01]],
            'vat_rate' => 19,
        ])->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.subtotal', 2000.02)
            ->assertJsonPath('data.vat_amount', 380)
            ->assertJsonPath('data.total', 2380.02);
    }

    public function test_validation(): void
    {
        $this->as($this->rep)->postJson('/api/quotes', ['lead_id' => $this->lead->id, 'items' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->as($this->rep)->postJson('/api/quotes', [
            'lead_id' => 999,
            'vat_rate' => 16,
            'valid_until' => now()->subDay()->toDateString(),
            'items' => [['description' => '', 'quantity' => 0, 'unit' => 'kg', 'unit_price' => -5]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'lead_id', 'vat_rate', 'valid_until',
            'items.0.description', 'items.0.quantity', 'items.0.unit', 'items.0.unit_price',
        ]);

        $this->assertSame(0, Quote::count());
    }

    public function test_creation_is_atomic(): void
    {
        // Fail after the quote and its items were written: nothing may remain.
        QuoteItem::created(function (QuoteItem $item) {
            if ($item->sort === 2) {
                throw new \RuntimeException('boom');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->as($this->rep)->postJson('/api/quotes', ['lead_id' => $this->lead->id, 'items' => $this->items()]);
            $this->fail('Expected the request to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, Quote::count());
        $this->assertSame(0, QuoteItem::count());
        $this->assertSame(0, $this->lead->activities()->count());
    }

    // ---- permissions -------------------------------------------------------------

    public function test_sales_only_quote_their_own_leads(): void
    {
        $other = Lead::factory()->assignedTo(User::factory()->sales()->create())->create();
        $theirs = Quote::factory()->create(['lead_id' => $other->id]);
        $mine = $this->createQuote();

        $this->as($this->rep)->postJson('/api/quotes', ['lead_id' => $other->id, 'items' => $this->items()])->assertForbidden();
        $this->as($this->rep)->getJson("/api/quotes/{$theirs->id}")->assertForbidden();
        $this->as($this->rep)->getJson("/api/quotes/{$theirs->id}/pdf")->assertForbidden();
        $this->as($this->rep)->postJson("/api/quotes/{$theirs->id}/send")->assertForbidden();
        $this->as($this->rep)->getJson('/api/quotes')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->as($this->manager)->getJson('/api/quotes')->assertJsonCount(2, 'data');
    }

    // ---- send ----------------------------------------------------------------------

    public function test_send_marks_sent_moves_lead_and_emails_pdf(): void
    {
        $quote = $this->createQuote();

        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/send", ['message' => 'Wie besprochen.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $quote->refresh();
        $this->assertNotNull($quote->sent_at);
        $this->lead->refresh();
        $this->assertSame(LeadStatus::QuoteSent, $this->lead->status);
        $this->assertSame('17564.92', $this->lead->estimated_value);
        $this->assertTrue($this->lead->activities()->where('title', "Quote {$quote->number} sent")->exists());
        $this->assertTrue($this->lead->activities()->where('title', 'Site visit → Quote sent')->exists());

        Mail::assertQueued(QuoteMail::class, function (QuoteMail $mail) use ($quote) {
            return $mail->hasTo('lena@example.com')
                && $mail->quote->is($quote)
                && $mail->personalMessage === 'Wie besprochen.'
                && $mail->attachments()[0]->as === $quote->number.'.pdf'
                && str_contains($mail->render(), 'Wie besprochen.');
        });
    }

    public function test_quote_mail_attaches_a_real_pdf(): void
    {
        $quote = $this->createQuote();
        $mail = new QuoteMail($quote->load('lead', 'creator', 'items'));

        // PDFs embed a creation timestamp, so check the generated bytes rather than an exact match.
        $attachment = $mail->attachments()[0];
        $bytes = $attachment->attachWith(fn () => null, fn (\Closure $data) => $data());
        $this->assertSame($quote->number.'.pdf', $attachment->as);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(10_000, strlen($bytes));
        $mail->assertSeeInHtml($quote->number);
        $mail->assertSeeInHtml('Lena Hartmann');
        $mail->assertSeeInHtml('20.902,25');
    }

    public function test_send_does_not_move_lead_backwards(): void
    {
        $this->lead->update(['status' => LeadStatus::Negotiation]);
        $quote = $this->createQuote();

        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/send")->assertOk();

        $this->assertSame(LeadStatus::Negotiation, $this->lead->fresh()->status);
    }

    public function test_send_requires_an_email_address(): void
    {
        $this->lead->update(['email' => null]);
        $quote = $this->createQuote();

        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/send")->assertJsonValidationErrors('to');
        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);

        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/send", ['to' => 'architect@example.com'])->assertOk();
        Mail::assertQueued(QuoteMail::class, fn ($m) => $m->hasTo('architect@example.com'));
    }

    public function test_only_drafts_can_be_edited(): void
    {
        $quote = $this->createQuote();
        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/send")->assertOk();

        $this->as($this->rep)->patchJson("/api/quotes/{$quote->id}", ['notes' => 'changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    // ---- accept ----------------------------------------------------------------------

    public function test_accept_wins_lead_and_creates_project_from_quote(): void
    {
        $quote = $this->createQuote();
        $competing = $this->createQuote(['items' => [['description' => 'Option B', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 9000]]]);
        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/send")->assertOk();

        $response = $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/accept");

        $response->assertCreated()
            ->assertJsonPath('quote.status', 'accepted')
            ->assertJsonPath('project.budget', 17564.92)
            ->assertJsonPath('project.status', 'planning')
            ->assertJsonPath('project.name', 'Extension · Hartmann · Berlin')
            ->assertJsonPath('project.site_address', 'Kastanienallee 7, 10437 Berlin')
            ->assertJsonPath('project.manager.id', $this->manager->id)
            ->assertJsonPath('project.quote.number', $quote->number);

        $project = Project::sole();
        $this->assertSame('P-'.now()->year.'-0001', $project->code);
        $this->assertTrue($project->lead->is($this->lead));
        $this->assertNotNull($quote->fresh()->accepted_at);
        $this->assertSame(QuoteStatus::Declined, $competing->fresh()->status);

        $lead = $this->lead->fresh();
        $this->assertSame(LeadStatus::Won, $lead->status);
        $this->assertSame('17564.92', $lead->estimated_value);
        $this->assertTrue($lead->activities()->where('title', 'Quote sent → Won')->exists());
        $this->assertTrue($lead->activities()->where('title', "Quote {$quote->number} accepted 🎉")->exists());

        // The lead detail now shows the project.
        $this->as($this->rep)->getJson("/api/leads/{$lead->id}")->assertJsonPath('data.project.code', $project->code);
    }

    public function test_accept_is_not_repeatable_and_needs_a_sent_quote(): void
    {
        $quote = $this->createQuote();
        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/accept")->assertJsonValidationErrors('status');

        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/send")->assertOk();
        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/accept")->assertCreated();
        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/accept")->assertUnprocessable();

        $this->assertSame(1, Project::count());
    }

    public function test_expired_quotes_cannot_be_accepted(): void
    {
        $quote = $this->createQuote();
        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/send")->assertOk();

        $this->travel(31)->days();

        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/accept")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quote' => 'expired']);
        $this->as($this->rep)->getJson("/api/quotes/{$quote->id}")->assertJsonPath('data.is_expired', true);
        $this->assertSame(0, Project::count());
    }

    public function test_decline(): void
    {
        $quote = $this->createQuote();

        $this->as($this->rep)->postJson("/api/quotes/{$quote->id}/decline", ['reason' => 'Too expensive'])
            ->assertOk()->assertJsonPath('data.status', 'declined');

        $this->assertSame('Too expensive', $this->lead->activities()->where('title', "Quote {$quote->number} declined")->sole()->body);
    }

    public function test_pdf_endpoint(): void
    {
        $quote = $this->createQuote();

        $response = $this->as($this->rep)->get("/api/quotes/{$quote->id}/pdf?download=1");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$quote->number.'.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }
}
