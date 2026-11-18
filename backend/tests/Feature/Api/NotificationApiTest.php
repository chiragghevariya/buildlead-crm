<?php

namespace Tests\Feature\Api;

use App\Models\Lead;
use App\Models\User;
use App\Notifications\HotLeadNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_and_marks_notifications_read(): void
    {
        $user = User::factory()->manager()->create();
        $lead = Lead::factory()->hot()->create(['company' => 'Nordlicht Software GmbH']);
        $user->notifyNow(new HotLeadNotification($lead), ['database']);

        $this->actingAs($user, 'sanctum')->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', 'hot_lead')
            ->assertJsonPath('data.0.data.title', 'Hot lead: Nordlicht Software GmbH');

        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/read')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/notifications')->assertJsonPath('unread_count', 0);
    }
}
