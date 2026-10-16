<?php

namespace Tests\Unit\Http\Requests\TrackingEvent;

use App\Http\Requests\TrackingEvent\StoreTrackingEventRequest;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Gate;
use Tests\Facades\ProjectFactory;
use Tests\Facades\TaskFactory;
use Tests\Facades\UserFactory;
use Tests\TestCase;

/**
 * App\Scopes\TaskAccessScope (which TaskPolicy::view() relies on to restrict plain
 * users to their own tasks) short-circuits under app()->runningInConsole(), which is
 * always true for PHPUnit — so HTTP feature tests can't exercise the "no access" path
 * through the real policy. These tests swap in a fake Task policy instead, to verify
 * StoreTrackingEventRequest actually calls the 'view' ability for both task_id and,
 * when switching, previous_task_id.
 */
class StoreTrackingEventRequestTest extends TestCase
{
    private User $user;
    private Task $viewableTask;
    private Task $otherTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = UserFactory::refresh()->asUser()->create();

        $project = ProjectFactory::create();
        $this->viewableTask = TaskFactory::refresh()->forProject($project)->create();
        $this->otherTask = TaskFactory::refresh()->forProject($project)->create();

        $this->fakeTaskPolicy(fn($user, Task $task) => $task->id === $user->viewableTaskId);
        $this->user->viewableTaskId = $this->viewableTask->id;
    }

    /**
     * Gate::policy() always resolves its second argument through the container (even when
     * given an object), so the fake policy has to be bound under its own class name first.
     */
    private function fakeTaskPolicy(Closure $view): void
    {
        $policy = new class($view) {
            public function __construct(private Closure $view)
            {
            }

            public function view($user, Task $task): bool
            {
                return ($this->view)($user, $task);
            }
        };

        app()->instance(get_class($policy), $policy);
        Gate::policy(Task::class, get_class($policy));
    }

    private function makeRequest(array $input): StoreTrackingEventRequest
    {
        $request = StoreTrackingEventRequest::create('api/tracking/events', 'POST', $input);
        $request->setUserResolver(fn() => $this->user);

        return $request;
    }

    public function test_authorized_for_started_when_task_viewable(): void
    {
        $request = $this->makeRequest(['action' => 'started', 'task_id' => $this->viewableTask->id]);

        $this->assertTrue($request->authorizeValidated());
    }

    public function test_forbidden_for_started_when_task_not_viewable(): void
    {
        $request = $this->makeRequest(['action' => 'started', 'task_id' => $this->otherTask->id]);

        $this->assertFalse($request->authorizeValidated());
    }

    public function test_switched_requires_both_tasks_viewable(): void
    {
        $request = $this->makeRequest([
            'action' => 'switched',
            'task_id' => $this->viewableTask->id,
            'previous_task_id' => $this->otherTask->id,
        ]);

        $this->assertFalse($request->authorizeValidated());
    }

    public function test_switched_authorized_when_both_tasks_viewable(): void
    {
        $this->fakeTaskPolicy(fn() => true);

        $request = $this->makeRequest([
            'action' => 'switched',
            'task_id' => $this->viewableTask->id,
            'previous_task_id' => $this->otherTask->id,
        ]);

        $this->assertTrue($request->authorizeValidated());
    }
}
