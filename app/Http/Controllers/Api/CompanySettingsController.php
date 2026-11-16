<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanySettings\UpdateCompanySettingsRequest;
use App\Http\Transformers\CompanySettingsTransformer;
use App\Models\Priority;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Settings;

class CompanySettingsController extends Controller
{
    /**
     * List
     *
     * Returns all company settings.
     *
     * Modules can add their own keys to the settings object.
     *
     * @response array{status: int, success: true, data: array{
     *     timezone: string|null,
     *     language: string|null,
     *     work_time: int,
     *     color: list<mixed>,
     *     internal_priorities: list<\App\Models\Priority>,
     *     heartbeat_period: int,
     *     auto_thinning: bool,
     *     screenshots_state: int,
     *     env_screenshots_state: int,
     *     default_priority_id: int,
     *     slack_channel_id: string|null,
     *     slack_configured: bool,
     *     slack_user_mentions: list<array{email: string, slack_user_id: string}>,
     * }}
     */
    public function index(): JsonResponse
    {
        $settings = Settings::scope('core')->all();

        return responder()->success(
            array_merge(
                $settings,
                [
                    'internal_priorities' => Priority::all(),
                    'slack_configured' => $this->isSlackConfigured($settings),
                    'slack_user_mentions' => json_decode($settings['slack_user_mentions'] ?? '', true) ?: [],
                ]
            ),
            new CompanySettingsTransformer
        )->respond();
    }

    /**
     * Whether a usable Slack bot token and channel are configured. Never exposes the token itself.
     */
    private function isSlackConfigured(array $settings): bool
    {
        if (empty($settings['slack_bot_token']) || empty($settings['slack_channel_id'])) {
            return false;
        }

        try {
            Crypt::decryptString($settings['slack_bot_token']);
        } catch (DecryptException $e) {
            Log::warning('Failed to decrypt Slack bot token setting', ['exception' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /**
     * Update
     *
     * Updates the specified company settings.
     */
    public function update(UpdateCompanySettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('slack_bot_token', $validated)) {
            $this->setOrForgetSetting(
                'slack_bot_token',
                $validated['slack_bot_token'] !== null && $validated['slack_bot_token'] !== ''
                    ? Crypt::encryptString($validated['slack_bot_token'])
                    : null,
            );
            unset($validated['slack_bot_token']);
        }

        if (array_key_exists('slack_channel_id', $validated)) {
            $this->setOrForgetSetting(
                'slack_channel_id',
                $validated['slack_channel_id'] !== null && $validated['slack_channel_id'] !== ''
                    ? $validated['slack_channel_id']
                    : null,
            );
            unset($validated['slack_channel_id']);
        }

        if (array_key_exists('slack_user_mentions', $validated)) {
            $this->setOrForgetSetting(
                'slack_user_mentions',
                !empty($validated['slack_user_mentions']) ? json_encode($validated['slack_user_mentions']) : null,
            );
            unset($validated['slack_user_mentions']);
        }

        if ($validated !== []) {
            Settings::scope('core')->set($validated);
        }

        return responder()->success()->respond(204);
    }

    /**
     * Sets a core setting, or deletes it outright when the value is null (settings.value is NOT NULL,
     * so "clear" means removing the row rather than storing an empty value).
     */
    private function setOrForgetSetting(string $key, ?string $value): void
    {
        if ($value === null) {
            Setting::where(['module_name' => 'core', 'key' => $key])->delete();
            Settings::scope('core')->flush();

            return;
        }

        Settings::scope('core')->set($key, $value);
    }
    /**
     * Get Offline Sync Public Key
     *
     * Retrieves the public key for offline synchronization.
     *
     * Any authenticated user may call it.
     *
     * `key` is `null` if no key is stored.
     *
     * @response array{status: int, success: true, data: array{key: string|null}}
     */
    public function getOfflineSyncPublicKey(): JsonResponse
    {
        return responder()->success(
            [ 'key' => Settings::scope('core.offline-sync')->get('public_key')],
        )->respond();
    }
}
