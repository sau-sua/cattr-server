<?php

namespace Tests\Feature\Jobs;

use App\Enums\TrackingEventAction;
use App\Jobs\SendSlackTrackingNotification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Settings;
use Tests\Facades\ProjectFactory;
use Tests\Facades\TaskFactory;
use Tests\Facades\UserFactory;
use Tests\TestCase;

class SendSlackTrackingNotificationTest extends TestCase
{
    private const TOKEN = 'xoxb-fake-token';
    private const CHANNEL = 'C12345';

    private User $user;
    private Task $task;
    private Task $previousTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = UserFactory::refresh()->asUser()->create();

        $project = ProjectFactory::create();
        $this->task = TaskFactory::refresh()->forProject($project)->forUser($this->user)->create();
        $this->previousTask = TaskFactory::refresh()->forProject($project)->forUser($this->user)->create();
    }

    private function configureSlack(): void
    {
        Settings::scope('core')->set([
            'slack_bot_token' => Crypt::encryptString(self::TOKEN),
            'slack_channel_id' => self::CHANNEL,
        ]);
    }

    public function test_does_nothing_when_not_configured(): void
    {
        Http::fake();

        (new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Started, $this->task->id))->handle();

        Http::assertNothingSent();
    }

    public function test_started_posts_message_with_mention_and_project_task(): void
    {
        $this->configureSlack();

        Http::fake([
            'https://slack.com/api/users.lookupByEmail*' => Http::response([
                'ok' => true,
                'user' => ['id' => 'U999'],
            ]),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        (new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Started, $this->task->id))->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && $request['channel'] === self::CHANNEL
                && $request['text'] === "<@U999> started working on {$this->task->project->name} / {$this->task->task_name}"
                && $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN);
        });
    }

    public function test_stopped_message_has_no_project_or_task(): void
    {
        $this->configureSlack();

        Http::fake([
            'https://slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U999']]),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        (new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Stopped, $this->task->id))->handle();

        Http::assertSent(fn($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['text'] === '<@U999> stopped working');
    }

    public function test_falls_back_to_bold_name_when_mention_not_found(): void
    {
        $this->configureSlack();

        Http::fake([
            'https://slack.com/api/users.lookupByEmail*' => Http::response([
                'ok' => false,
                'error' => 'users_not_found',
            ]),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        (new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Stopped, $this->task->id))->handle();

        Http::assertSent(fn($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['text'] === "*{$this->user->full_name}* stopped working");
    }

    public function test_manual_mention_override_takes_priority_over_lookup(): void
    {
        $this->configureSlack();
        Settings::scope('core')->set('slack_user_mentions', json_encode([
            ['email' => strtoupper($this->user->email), 'slack_user_id' => 'U777OVERRIDE'],
        ]));

        Http::fake([
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        (new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Stopped, $this->task->id))->handle();

        Http::assertNotSent(fn($request) => $request->url() === 'https://slack.com/api/users.lookupByEmail');
        Http::assertSent(fn($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['text'] === '<@U777OVERRIDE> stopped working');
    }

    public function test_builds_project_task_link_when_frontend_url_configured(): void
    {
        $this->configureSlack();
        config(['app.frontend_url' => 'https://cattr.example.com']);

        Http::fake([
            'https://slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U999']]),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        (new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Started, $this->task->id))->handle();

        $expectedLabel = "{$this->task->project->name} / {$this->task->task_name}";
        $expectedUrl = "https://cattr.example.com/projects/{$this->task->project_id}/tasks/kanban";

        Http::assertSent(fn($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['text'] === "<@U999> started working on <{$expectedUrl}|{$expectedLabel}>");
    }

    public function test_escapes_mrkdwn_in_task_name(): void
    {
        $this->configureSlack();
        $this->task->task_name = 'release <!channel> now';
        $this->task->save();

        Http::fake([
            'https://slack.com/api/users.lookupByEmail*' => Http::response(['ok' => false, 'error' => 'users_not_found']),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        (new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Started, $this->task->id))->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && str_contains($request['text'], 'release &lt;!channel&gt; now')
                && !str_contains($request['text'], '<!channel>');
        });
    }

    public function test_escapes_mrkdwn_in_task_name_within_project_link(): void
    {
        $this->configureSlack();
        config(['app.frontend_url' => 'https://cattr.example.com']);
        $this->task->task_name = 'release <!channel> now';
        $this->task->save();

        Http::fake([
            'https://slack.com/api/users.lookupByEmail*' => Http::response(['ok' => false, 'error' => 'users_not_found']),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        (new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Started, $this->task->id))->handle();

        Http::assertSent(function ($request) {
            $expectedUrl = "https://cattr.example.com/projects/{$this->task->project_id}/tasks/kanban";

            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && str_starts_with($request['text'], "*{$this->user->full_name}* started working on <{$expectedUrl}|")
                && str_contains($request['text'], 'release &lt;!channel&gt; now')
                && !str_contains($request['text'], '<!channel>');
        });
    }

    public function test_switched_sends_stopped_then_started_in_order(): void
    {
        $this->configureSlack();

        Http::fake([
            'https://slack.com/api/users.lookupByEmail*' => Http::response(['ok' => false, 'error' => 'users_not_found']),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        (new SendSlackTrackingNotification(
            $this->user->id,
            TrackingEventAction::Switched,
            $this->task->id,
            $this->previousTask->id,
        ))->handle();

        $postMessageRequests = Http::recorded(
            fn($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        )->values();

        $this->assertCount(2, $postMessageRequests);
        [$first] = $postMessageRequests[0];
        [$second] = $postMessageRequests[1];

        $this->assertSame("*{$this->user->full_name}* stopped working", $first['text']);
        $this->assertStringContainsString('started working on', $second['text']);
        $this->assertStringContainsString($this->task->task_name, $second['text']);
    }

    public function test_slack_ok_false_logs_error_and_does_not_throw(): void
    {
        $this->configureSlack();

        Http::fake([
            'https://slack.com/api/users.lookupByEmail*' => Http::response(['ok' => false, 'error' => 'users_not_found']),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'not_in_channel']),
        ]);

        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Started, $this->task->id);
        $job->handle();

        Http::assertSentCount(2);
    }

    public function test_rate_limited_releases_job(): void
    {
        $this->configureSlack();

        Http::fake([
            'https://slack.com/api/users.lookupByEmail*' => Http::response(['ok' => false, 'error' => 'users_not_found']),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => '5']),
        ]);

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('release')->once()->with(5);

        $job = new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Started, $this->task->id);
        $job->setJob($queueJob);
        $job->handle();
    }

    public function test_decrypt_failure_is_treated_as_not_configured(): void
    {
        Settings::scope('core')->set([
            'slack_bot_token' => 'not-a-valid-encrypted-token',
            'slack_channel_id' => self::CHANNEL,
        ]);

        Http::fake();

        (new SendSlackTrackingNotification($this->user->id, TrackingEventAction::Started, $this->task->id))->handle();

        Http::assertNothingSent();
    }
}
