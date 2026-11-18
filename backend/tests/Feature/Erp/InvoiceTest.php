<?php

namespace Tests\Feature\Erp;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
        $this->project = Project::factory()->create(['budget' => 100_000, 'status' => 'in_progress']);
        $this->actingAs($this->manager, 'sanctum');
    }

    private function invoice(array $data)
    {
        return $this->postJson("/api/projects/{$this->project->id}/invoices", $data);
    }

    // ---- creation & amounts ---------------------------------------------------

    public function test_deposit_defaults_to_30_percent_as_draft_with_vat(): void
    {
        $this->invoice(['type' => 'deposit'])
            ->assertCreated()
            ->assertJsonPath('data.number', 'INV-'.now()->year.'-0001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.amount', 30000)
            ->assertJsonPath('data.vat_amount', 5700)
            ->assertJsonPath('data.total', 35700)
            ->assertJsonPath('data.issued_at', null);
    }

    public function test_progress_by_percent_or_amount_and_send_immediately(): void
    {
        $this->invoice(['type' => 'progress', 'percent' => 25, 'send' => true])
            ->assertCreated()
            ->assertJsonPath('data.amount', 25000)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.issued_at', now()->toDateString())
            ->assertJsonPath('data.due_at', now()->addDays(14)->toDateString());

        $this->invoice(['type' => 'progress', 'amount' => 12345.67])->assertCreated()->assertJsonPath('data.amount', 12345.67);

        $this->invoice(['type' => 'progress'])->assertJsonValidationErrors('amount');
        $this->invoice(['type' => 'progress', 'amount' => 100, 'percent' => 10])->assertJsonValidationErrors('amount');
        $this->invoice(['type' => 'credit_note', 'amount' => 100])->assertJsonValidationErrors('type');
    }

    public function test_final_invoice_settles_the_remainder_and_only_once(): void
    {
        $this->invoice(['type' => 'deposit'])->assertCreated();
        $this->invoice(['type' => 'progress', 'percent' => 40])->assertCreated();

        $this->invoice(['type' => 'final'])->assertCreated()->assertJsonPath('data.amount', 30000);
        $this->invoice(['type' => 'final', 'amount' => 1])->assertJsonValidationErrors(['type' => 'already has a final invoice']);
    }

    // ---- 110 % budget cap ---------------------------------------------------------

    public function test_invoices_are_blocked_above_110_percent_of_budget(): void
    {
        $this->invoice(['type' => 'progress', 'amount' => 100_000])->assertCreated();

        $this->invoice(['type' => 'progress', 'amount' => 10_000.01])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'At most € 10.000,00 can still be invoiced']);

        // Exactly up to the cap is fine.
        $this->invoice(['type' => 'progress', 'amount' => 10_000])->assertCreated();
        $this->invoice(['type' => 'progress', 'amount' => 0.01])->assertUnprocessable();

        $this->assertSame(110_000.0, (float) Invoice::sum('amount'));
    }

    public function test_drafts_count_toward_the_cap(): void
    {
        $this->invoice(['type' => 'deposit', 'percent' => 60])->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->invoice(['type' => 'progress', 'percent' => 51])->assertUnprocessable();
        $this->invoice(['type' => 'progress', 'percent' => 50])->assertCreated();
    }

    public function test_final_invoice_respects_cap_when_already_over_budget(): void
    {
        $this->invoice(['type' => 'progress', 'amount' => 105_000])->assertCreated();

        // Remainder would be negative → nothing to invoice.
        $this->invoice(['type' => 'final'])->assertJsonValidationErrors(['amount' => 'Nothing left to invoice']);
    }

    public function test_project_shows_invoiced_totals_and_remaining_capacity(): void
    {
        $this->invoice(['type' => 'deposit', 'send' => true])->assertCreated();
        $id = $this->invoice(['type' => 'progress', 'percent' => 20, 'send' => true])->json('data.id');
        $this->postJson("/api/invoices/{$id}/mark-paid")->assertOk();

        $this->getJson("/api/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonPath('data.invoiced', 50000)
            ->assertJsonPath('data.invoiced_pct', 50)
            ->assertJsonPath('data.paid', 20000)
            ->assertJsonPath('data.outstanding', 35700) // deposit gross, still open
            ->assertJsonPath('data.invoiceable_remaining', 60000)
            ->assertJsonCount(2, 'data.invoices');

        $this->getJson('/api/projects')->assertJsonPath('data.0.invoiced', 50000);
    }

    // ---- paying & overdue -----------------------------------------------------------

    public function test_mark_paid_only_for_issued_invoices(): void
    {
        $draft = $this->invoice(['type' => 'deposit'])->json('data.id');
        $this->postJson("/api/invoices/{$draft}/mark-paid")->assertJsonValidationErrors('status');

        $this->postJson("/api/invoices/{$draft}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson("/api/invoices/{$draft}/send")->assertUnprocessable();

        $this->postJson("/api/invoices/{$draft}/mark-paid", ['paid_at' => now()->subDay()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->assertTrue(Invoice::find($draft)->paid_at->isYesterday());

        $this->postJson("/api/invoices/{$draft}/mark-paid")->assertUnprocessable();
    }

    public function test_overdue_command_flags_only_past_due_sent_invoices(): void
    {
        $pastDue = Invoice::factory()->create(['project_id' => $this->project->id, 'status' => 'sent', 'issued_at' => now()->subDays(20), 'due_at' => now()->subDays(6)]);
        $dueToday = Invoice::factory()->create(['project_id' => $this->project->id, 'status' => 'sent', 'issued_at' => now()->subDays(14), 'due_at' => now()]);
        $paid = Invoice::factory()->create(['project_id' => $this->project->id, 'status' => 'paid', 'due_at' => now()->subDays(30), 'paid_at' => now()->subDays(29)]);
        $draft = Invoice::factory()->create(['project_id' => $this->project->id, 'status' => 'draft', 'issued_at' => null, 'due_at' => null]);

        $this->artisan('invoices:mark-overdue')->expectsOutput('1 invoice marked as overdue.')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Overdue, $pastDue->fresh()->status);
        $this->assertSame(InvoiceStatus::Sent, $dueToday->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $paid->fresh()->status);
        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);

        $this->getJson("/api/invoices/{$pastDue->id}")->assertJsonPath('data.days_overdue', 6);
        $this->artisan('invoices:mark-overdue')->expectsOutput('No new overdue invoices.');
    }

    public function test_backdated_issue_becomes_overdue_immediately_and_can_still_be_paid(): void
    {
        $this->invoice(['type' => 'deposit', 'send' => true, 'issued_at' => now()->subDays(20)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'overdue');

        $this->postJson('/api/invoices/'.Invoice::sole()->id.'/mark-paid')->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_overdue_check_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'invoices:mark-overdue'));

        $this->assertNotNull($event);
        $this->assertSame('0 6 * * *', $event->expression);
        $this->assertSame('Europe/Berlin', $event->timezone);
    }

    // ---- listing / pdf / permissions ---------------------------------------------------

    public function test_list_filters_and_summary(): void
    {
        Invoice::factory()->create(['project_id' => $this->project->id, 'status' => 'overdue', 'amount' => 1000]);
        Invoice::factory()->create(['project_id' => $this->project->id, 'status' => 'paid', 'amount' => 2000]);

        $this->getJson('/api/invoices?status=overdue')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.overdue', ['count' => 1, 'total' => 1190])
            ->assertJsonPath('summary.paid.total', 2380);
    }

    public function test_invoice_pdf(): void
    {
        $id = $this->invoice(['type' => 'deposit', 'send' => true])->json('data.id');

        $response = $this->get("/api/invoices/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_sales_cannot_access_projects_or_invoices(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->sales()->create(), 'sanctum');
        $invoice = Invoice::factory()->create(['project_id' => $this->project->id]);

        $this->getJson('/api/projects')->assertForbidden();
        $this->getJson("/api/projects/{$this->project->id}")->assertForbidden();
        $this->patchJson("/api/projects/{$this->project->id}", ['progress' => 50])->assertForbidden();
        $this->invoice(['type' => 'deposit'])->assertForbidden();
        $this->getJson('/api/invoices')->assertForbidden();
        $this->postJson("/api/invoices/{$invoice->id}/mark-paid")->assertForbidden();
        $this->get("/api/invoices/{$invoice->id}/pdf")->assertForbidden();
    }
}
