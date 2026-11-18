<?php

namespace Tests\Feature\Erp;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_filters_and_search(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        Project::factory()->create(['status' => 'in_progress', 'name' => 'Office fit-out Nordlicht']);
        Project::factory()->create(['status' => 'completed', 'name' => 'Roof Müller']);

        $this->getJson('/api/projects')->assertJsonCount(2, 'data');
        $this->getJson('/api/projects?status=in_progress')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Office fit-out Nordlicht');
        $this->getJson('/api/projects?search=nordlicht')->assertJsonCount(1, 'data');
    }

    public function test_manager_updates_status_progress_and_spent(): void
    {
        $manager = User::factory()->manager()->create();
        $project = Project::factory()->create(['budget' => 80_000, 'spent' => 0, 'progress' => 0]);

        $this->actingAs($manager, 'sanctum')->patchJson("/api/projects/{$project->id}", [
            'status' => 'in_progress', 'progress' => 45, 'spent' => 41_500.5, 'manager_id' => $manager->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.progress', 45)
            ->assertJsonPath('data.spent', 41500.5)
            ->assertJsonPath('data.budget_used_pct', 52)
            ->assertJsonPath('data.manager.id', $manager->id);

        $this->actingAs($manager, 'sanctum')->patchJson("/api/projects/{$project->id}", ['status' => 'completed'])
            ->assertJsonPath('data.progress', 100);
    }

    public function test_update_validation(): void
    {
        $this->actingAs(User::factory()->manager()->create(), 'sanctum');
        $project = Project::factory()->create();

        $this->patchJson("/api/projects/{$project->id}", [
            'status' => 'abandoned', 'progress' => 120, 'spent' => -1,
            'start_date' => '2026-12-01', 'due_date' => '2026-11-01',
            'manager_id' => User::factory()->sales()->create()->id,
        ])->assertJsonValidationErrors(['status', 'progress', 'spent', 'due_date', 'manager_id']);
    }
}
