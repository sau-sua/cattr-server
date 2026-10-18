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
     * @api             {get} /company-settings/ List
     * @apiDescription  Returns all company settings.
     *
     * @apiVersion      4.0.0
     * @apiName         ListCompanySettings
     * @apiGroup        Company Settings
     *
     * @apiUse          AuthHeader
     *
     * @apiVersion      4.0.0
     * @apiName         ListCompanySettings
     * @apiGroup        Company Settings
     *
     * @apiUse          AuthHeader
     *
     * @apiSuccess {String}   timezone                        The timezone setting for the company.
     * @apiSuccess {String}   language                        The language setting for the company.
     * @apiSuccess {Integer}  work_time                       The configured work time in hours.
     * @apiSuccess {Array}    color                           Array of colors configured for the company settings.
     * @apiSuccess {Array}    internal_priorities             Array of internal priorities.
     * @apiSuccess {Integer}  internal_priorities.id          The unique ID of the priority.
     * @apiSuccess {String}   internal_priorities.name        The name of the priority.
     * @apiSuccess {String}   internal_priorities.created_at  The creation timestamp of the priority.
     * @apiSuccess {String}   internal_priorities.updated_at  The last update timestamp of the priority.
     * @apiSuccess {String}   internal_priorities.color       The color associated with the priority.
     * @apiSuccess {Integer}  heartbeat_period                The period for heartbeat checks in seconds.
     * @apiSuccess {Boolean}  auto_thinning                   Indicates if automatic thinning of old data is enabled.
     * @apiSuccess {Integer}  screenshots_state               The current state of screenshot monitoring.
     * @apiSuccess {Integer}  env_screenshots_state           The environmental screenshot state.
     * @apiSuccess {Integer}  default_priority_id             The default priority ID.
     * @apiSuccess {String}   slack_channel_id                The Slack channel ID tracking notifications are sent to, if configured.
     * @apiSuccess {Boolean}  slack_configured                Whether a usable Slack bot token and channel are configured. The token itself is never returned.
     * @apiSuccess {Array}    slack_user_mentions              Manual email-to-Slack-user-ID overrides, used before falling back to `users.lookupByEmail`.
     * @apiSuccess {String}   slack_user_mentions.email        Cattr user email.
     * @apiSuccess {String}   slack_user_mentions.slack_user_id  Slack user ID (e.g. `U0123ABCD`).
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *    {
     *     "timezone": "UTC",
     *     "language": "ru",
     *     "work_time": 0,
     *     "color": [],
     *     "internal_priorities": [
     *    {
     *      "id": 1,
     *      "name": "Normal",
     *       "created_at": "2023-10-26T10:26:17.000000Z",
     *       "updated_at": "2024-07-12T17:57:40.000000Z",
     *       "color": null
     *    },
     *    {
     *       "id": 2,
     *       "name": "Normal",
     *       "created_at": "2023-10-26T10:26:17.000000Z",
     *       "updated_at": "2024-06-21T10:06:50.000000Z",
     *       "color": "#49E637"
     *    },
     *    {
     *       "id": 3,
     *       "name": "High",
     *       "created_at": "2023-10-26T10:26:17.000000Z",
     *       "updated_at": "2024-06-21T10:07:00.000000Z",
     *       "color": "#D40C0C"
     *    },
     *    {
     *       "id": 5,
     *       "name": "Normal",
     *       "created_at": "2024-07-12T17:10:54.000000Z",
     *       "updated_at": "2024-07-12T17:10:54.000000Z",
     *       "color": null
     *    }
      *],
     *       "heartbeat_period": 60,
     *       "auto_thinning": true,
     *       "screenshots_state": 1,
     *       "env_screenshots_state": -1,
     *       "default_priority_id": 2
     *    }
     *  }
     *
     * @apiUse          400Error
     * @apiUse          UnauthorizedError
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
     * @param UpdateCompanySettingsRequest $request
     *
     * @return JsonResponse
     *
     * @api             {patch} /company-settings/ Update
     * @apiDescription  Updates the specified company settings.
     *
     * @apiVersion      4.0.0
     * @apiName         UpdateCompanySettings
     * @apiGroup        Company Settings
     *
     * @apiUse          AuthHeader
     *
     * @apiParam {String}   timezone                        The timezone setting for the company.
     * @apiParam {String}   language                        The language setting for the company.
     * @apiParam {Integer}  work_time                       The configured work time in hours.
     * @apiParam {Array}    color                           Array of colors configured for the company settings.
     * @apiParam {Array}    internal_priorities             Array of internal priorities.
     * @apiParam {Integer}  internal_priorities.id          The unique ID of the priority.
     * @apiParam {String}   internal_priorities.name        The name of the priority.
     * @apiParam {String}   internal_priorities.created_at  The creation timestamp of the priority.
     * @apiParam {String}   internal_priorities.updated_at  The last update timestamp of the priority.
     * @apiParam {String}   internal_priorities.color       The color associated with the priority.
     * @apiParam {Integer}  heartbeat_period                The period for heartbeat checks in seconds.
     * @apiParam {Boolean}  auto_thinning                   Indicates if automatic thinning of old data is enabled.
     * @apiParam {Integer}  screenshots_state               The current state of screenshot monitoring.
     * @apiParam {Integer}  env_screenshots_state           The environmental screenshot state.
     * @apiParam {Integer}  default_priority_id             The default priority ID.
     * @apiParam {String}   [slack_bot_token]               Slack bot token (`xoxb-...`). Omit to keep the current value, send `null`/empty to remove it. Write-only, never returned by GET.
     * @apiParam {String}   [slack_channel_id]               Slack channel ID to send tracking notifications to. Omit to keep the current value, send `null`/empty to remove it.
     * @apiParam {Array}    [slack_user_mentions]            Manual email-to-Slack-user-ID overrides. Omit to keep the current value, send `[]`/`null` to clear it.
     * @apiParam {String}   slack_user_mentions.email        Cattr user email.
     * @apiParam {String}   slack_user_mentions.slack_user_id  Slack user ID, must match `U[A-Z0-9]+`.
     *
     * @apiParamExample {json} Request Example
     * { "timezone" : "Europe/Moscow",
     *  "language" : "en",
     *  "work_time" : 0,
     *  "color" : [],
     *  "internal_priorities" :  [
     *   {
     *      "id" : 1,
     *      "name" : "Normal",
     *      "created_at" : "2023-10-26T10:26:17.000000Z",
     *      "updated_at" : "2024-07-12T17:57:40.000000Z",
     *      "color" : null
     *    },
     *    {
     *      "id" : 2,
     *      "name" : "Normal",
     *      "created_at" : "2023-10-26T10:26:17.000000Z",
     *      "updated_at" : "2024-06-21T10:06:50.000000Z",
     *      "color" : "#49E637"},
     *   {
     *      "id" : 3,
     *      "name" : "High",
     *      "created_at" : "2023-10-26T10:26:17.000000Z",
     *      "updated_at" : "2024-06-21T10:07:00.000000Z",
     *      "color" : "#D40C0C"
     *   },{
     *      "id" : 5,
     *      "name" : "Normal",
     *      "created_at" : "2024-07-12T17:10:54.000000Z",
     *      "updated_at" : "2024-07-12T17:10:54.000000Z",
     *      "color" : null
     *   }
     *  ],
     *  "heartbeat_period" : 60,
     *  "auto_thinning" : true,
     *  "screenshots_state" : 1,
     *  "env_screenshots_state" : -1,
     *  "default_priority_id" : 2
     * }
     *
     * @apiSuccess {Array}   data  Contains an array of settings that changes were applied to
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 204 No Content
     *  {
     *  }
     *
     * @apiUse          400Error
     * @apiUse          UnauthorizedError
     *
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
     * @api             {get} /offline-sync/public-key Get Offline Sync Public Key
     * @apiDescription  Retrieves the public key for offline synchronization.
     *
     * @apiVersion      4.0.0
     * @apiName         GetOfflineSyncPublicKey
     * @apiGroup        OfflineSync
     *
     * @apiUse          AuthHeader
     *
     * @apiPermission   offline_sync_view
     * @apiPermission   offline_sync_full_access
     *
     * @apiSuccess {Boolean} success Indicates if the operation was successful.
     * @apiSuccess {Object} data The response data.
     * @apiSuccess {String} data.key The public key for offline synchronization.
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 200 OK
     *  {
     *       "key": null
     *  }
     * @apiError (Error 401) Unauthorized The user is not authorized to access this resource.
     * @apiError (Error 403) Forbidden The user does not have the necessary permissions.
     * @apiError (Error 404) NotFound The requested resource was not found.
     *
     * @apiUse         400Error
     * @apiUse         UnauthorizedError
     * @apiUse         ItemNotFoundError
     * @apiUse         ForbiddenError
     */
    public function getOfflineSyncPublicKey(): JsonResponse
    {
        return responder()->success(
            [ 'key' => Settings::scope('core.offline-sync')->get('public_key')],
        )->respond();
    }
}
