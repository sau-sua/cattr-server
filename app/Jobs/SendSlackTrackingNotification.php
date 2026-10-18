<?php

namespace App\Jobs;

use App\Enums\TrackingEventAction;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Settings;
use Throwable;

class SendSlackTrackingNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const SLACK_API_URL = 'https://slack.com/api/chat.postMessage';
    private const LOOKUP_API_URL = 'https://slack.com/api/users.lookupByEmail';

    private const MAX_RELEASE_SECONDS = 300;
    private const DEFAULT_RELEASE_SECONDS = 30;

    private const MENTION_CACHE_NOT_FOUND = '__not_found__';
    private const MENTION_CACHE_FOUND_TTL = 86400;
    private const MENTION_CACHE_NOT_FOUND_TTL = 3600;

    public int $tries = 5;

    public function __construct(
        private readonly int $userId,
        private readonly TrackingEventAction $action,
        private readonly int $taskId,
        private readonly ?int $previousTaskId = null,
    ) {
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120, 240];
    }

    public function retryUntil(): Carbon
    {
        return now()->addMinutes(5);
    }

    public function handle(): void
    {
        $token = $this->getToken();
        $channelId = Settings::scope('core')->get('slack_channel_id');

        if (!$token || !$channelId) {
            return;
        }

        $user = User::find($this->userId);

        if (!$user) {
            return;
        }

        $mention = $this->mentionFor($user, $token);

        foreach ($this->buildLines($mention) as $line) {
            if (!$this->postMessage($token, $channelId, $line)) {
                return;
            }
        }
    }

    private function buildLines(string $mention): array
    {
        // Matches the agreed Hubstaff-style format: "stopped" (including the stopped half of
        // a switch) never mentions project/task, only "started" does.
        if ($this->action === TrackingEventAction::Switched) {
            return array_filter([
                $this->stoppedLine($mention, $this->previousTaskId),
                $this->startedLine($mention, $this->taskId),
            ]);
        }

        if ($this->action === TrackingEventAction::Started) {
            return array_filter([$this->startedLine($mention, $this->taskId)]);
        }

        return array_filter([$this->stoppedLine($mention, $this->taskId)]);
    }

    private function startedLine(string $mention, ?int $taskId): ?string
    {
        $task = $taskId ? Task::with('project')->find($taskId) : null;

        if (!$task || !$task->project) {
            return null;
        }

        return "{$mention} started working on " . $this->projectTaskLink($task);
    }

    private function stoppedLine(string $mention, ?int $taskId): ?string
    {
        if (!$taskId || !Task::whereKey($taskId)->exists()) {
            return null;
        }

        return "{$mention} stopped working";
    }

    /**
     * Builds a `<url|label>` Slack mrkdwn link to the task's project board. The label is
     * escaped before being wrapped so the `<...|...>` link syntax itself is never escaped.
     */
    private function projectTaskLink(Task $task): string
    {
        $label = $this->escapeMrkdwn($task->project->name) . ' / ' . $this->escapeMrkdwn($task->task_name);

        $frontendUrl = rtrim((string)config('app.frontend_url'), '/');

        if ($frontendUrl === '') {
            return $label;
        }

        return "<{$frontendUrl}/projects/{$task->project_id}/tasks/kanban|{$label}>";
    }

    private function mentionFor(User $user, string $token): string
    {
        $slackUserId = $this->overrideSlackUserId($user->email) ?? $this->lookupSlackUserId($user->email, $token);

        return $slackUserId ? "<@{$slackUserId}>" : '*' . $this->escapeMrkdwn($user->full_name) . '*';
    }

    /**
     * Manually configured email -> Slack user ID overrides (Company Settings), checked before
     * falling back to the `users.lookupByEmail` API — some workspaces don't match Cattr/Slack
     * emails, so admins can map them by hand.
     */
    private function overrideSlackUserId(string $email): ?string
    {
        $overrides = json_decode(Settings::scope('core')->get('slack_user_mentions') ?? '', true) ?: [];

        foreach ($overrides as $override) {
            if (
                isset($override['email'], $override['slack_user_id'])
                && strcasecmp($override['email'], $email) === 0
            ) {
                return $override['slack_user_id'];
            }
        }

        return null;
    }

    private function lookupSlackUserId(string $email, string $token): ?string
    {
        $cacheKey = 'slack:user-lookup:' . hash('sha256', strtolower($email));
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached === self::MENTION_CACHE_NOT_FOUND ? null : $cached;
        }

        $slackUserId = $this->fetchSlackUserId($email, $token);

        Cache::put(
            $cacheKey,
            $slackUserId ?? self::MENTION_CACHE_NOT_FOUND,
            $slackUserId ? self::MENTION_CACHE_FOUND_TTL : self::MENTION_CACHE_NOT_FOUND_TTL,
        );

        return $slackUserId;
    }

    private function fetchSlackUserId(string $email, string $token): ?string
    {
        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get(self::LOOKUP_API_URL, ['email' => $email]);
        } catch (Throwable $e) {
            Log::warning('Slack users.lookupByEmail request failed', ['exception' => $e->getMessage()]);

            return null;
        }

        if (!$response->json('ok')) {
            if ($response->json('error') !== 'users_not_found') {
                Log::warning('Slack users.lookupByEmail returned an error', [
                    'slack_error' => $response->json('error'),
                ]);
            }

            return null;
        }

        return $response->json('user.id');
    }

    private function postMessage(string $token, string $channelId, string $text): bool
    {
        $response = Http::withToken($token)
            ->timeout(5)
            ->asJson()
            ->post(self::SLACK_API_URL, [
                'channel' => $channelId,
                'text' => $text,
            ]);

        if ($response->status() === 429 || $response->json('error') === 'ratelimited') {
            $retryAfter = min((int)($response->header('Retry-After') ?: self::DEFAULT_RELEASE_SECONDS), self::MAX_RELEASE_SECONDS);
            $this->release($retryAfter);

            return false;
        }

        if (!$response->json('ok')) {
            Log::error('Slack chat.postMessage failed', [
                'slack_error' => $response->json('error'),
            ]);

            return false;
        }

        return true;
    }

    private function getToken(): ?string
    {
        $encrypted = Settings::scope('core')->get('slack_bot_token');

        if (!$encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException $e) {
            Log::warning('Failed to decrypt Slack bot token setting', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    private function escapeMrkdwn(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
