<?php

namespace Tests\Feature\TrackingEvent;

use App\Jobs\SendSlackTrackingNotification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Facades\TaskFactory;
use Tests\Facades\UserFactory;
use Tests\TestCase;

class StoreTest extends TestCase
{
    private const URI = 'api/tracking/events';

    private User $admin;

    private User $assignedUser;
    private Task $assignedTask;

    private Task $task;
    private Task $previousTask;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->admin = UserFactory::refresh()->asAdmin()->create();

        $this->task = TaskFactory::create();
        $this->previousTask = TaskFactory::refresh()->create();

        $this->assignedUser = UserFactory::refresh()->asUser()->create();
        $this->assignedTask = TaskFactory::refresh()->forUser($this->assignedUser)->create();
    }

    public function test_unauthenticated(): void
    {
        $response = $this->postJson(self::URI, ['action' => 'started', 'task_id' => $this->task->id]);

        $response->assertStatus(401);
        Queue::assertNotPushed(SendSlackTrackingNotification::class);
    }

    public function test_started_dispatches_job(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(self::URI, ['action' => 'started', 'task_id' => $this->task->id]);

        $response->assertNoContent();

        Queue::assertPushed(SendSlackTrackingNotification::class, 1);
    }

    public function test_stopped_dispatches_job(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(self::URI, ['action' => 'stopped', 'task_id' => $this->task->id]);

        $response->assertNoContent();

        Queue::assertPushed(SendSlackTrackingNotification::class, 1);
    }

    public function test_switched_requires_previous_task_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(self::URI, ['action' => 'switched', 'task_id' => $this->task->id]);

        $response->assertStatus(422);
        Queue::assertNotPushed(SendSlackTrackingNotification::class);
    }

    public function test_switched_dispatches_job(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(self::URI, [
            'action' => 'switched',
            'task_id' => $this->task->id,
            'previous_task_id' => $this->previousTask->id,
        ]);

        $response->assertNoContent();

        Queue::assertPushed(SendSlackTrackingNotification::class, 1);
    }

    public function test_invalid_action(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(self::URI, ['action' => 'paused', 'task_id' => $this->task->id]);

        $response->assertStatus(422);
        Queue::assertNotPushed(SendSlackTrackingNotification::class);
    }

    public function test_nonexistent_task(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(self::URI, ['action' => 'started', 'task_id' => 999999]);

        $response->assertStatus(422);
        Queue::assertNotPushed(SendSlackTrackingNotification::class);
    }

    public function test_nonexistent_previous_task(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(self::URI, [
            'action' => 'switched',
            'task_id' => $this->task->id,
            'previous_task_id' => 999999,
        ]);

        $response->assertStatus(422);
        Queue::assertNotPushed(SendSlackTrackingNotification::class);
    }

    // App\Scopes\TaskAccessScope (which TaskPolicy::view() relies on to deny plain users
    // access to tasks outside their project) no-ops under app()->runningInConsole(), which
    // PHPUnit always sets to true — so the "no access" path can't be exercised through a real
    // HTTP request here. See StoreTrackingEventRequestTest (tests/Unit) for coverage of the
    // actual authorization wiring (task_id and, for `switched`, previous_task_id both go
    // through the `view` ability) with a faked policy.

    public function test_assigned_user_can_report_own_task(): void
    {
        Sanctum::actingAs($this->assignedUser, ['*']);

        $response = $this->postJson(self::URI, ['action' => 'started', 'task_id' => $this->assignedTask->id]);

        $response->assertNoContent();

        Queue::assertPushed(SendSlackTrackingNotification::class, 1);
    }
}
