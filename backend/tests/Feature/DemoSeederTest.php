<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_the_spec_dataset(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@buildlead.test')->where('role', 'admin')->count());
        $this->assertSame(1, User::where('email', 'manager@buildlead.test')->where('role', 'manager')->count());
        $this->assertSame(6, User::salesReps()->count());

        $this->assertSame(60, Lead::count());
        $this->assertSame(12, Quote::count());
        $this->assertSame(6, Project::count());
        $this->assertSame(15, Invoice::count());

        $this->assertSame(0, Lead::whereNull('assigned_to')->count());
        $this->assertSame(0, Lead::whereNull('score')->count());
        $this->assertTrue(Lead::min('budget_min') >= 8000);
        $this->assertTrue(Lead::max('budget_max') <= 1_200_000);
        $this->assertTrue(Lead::min('created_at') >= now()->subWeeks(12)->startOfDay());

        // Every won lead became a project via an accepted quote.
        $this->assertSame(6, Lead::where('status', LeadStatus::Won->value)->has('project')->count());

        // Each lead has a full timeline starting with "created".
        Lead::with('activities')->get()->each(function (Lead $lead) {
            $this->assertSame('created', $lead->activities->sortBy('id')->first()->type->value);
        });
    }

    public function test_seeded_admin_can_log_in(): void
    {
        $this->seed(DemoSeeder::class);

        $this->postJson('/api/auth/login', ['email' => 'admin@buildlead.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');
    }
}
