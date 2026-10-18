<?php

namespace App\Http\Requests\CompanySettings;

use App\Enums\Role;
use App\Enums\ScreenshotsState;
use App\Http\Requests\CattrFormRequest;
use Closure;
use Illuminate\Validation\Rules\Enum;

class UpdateCompanySettingsRequest extends CattrFormRequest
{
    public function _authorize(): bool
    {
        return $this->user()->hasRole(Role::ADMIN);
    }

    public function _rules(): array
    {
        return [
            'timezone' => 'sometimes|required|timezone',
            'work_time' => 'sometimes|int',
            'auto_thinning' => 'sometimes|boolean',
            'screenshots_state' => ['sometimes', 'required', new Enum(ScreenshotsState::class)],
            'language' => 'sometimes|string',
            'default_priority_id' => 'sometimes|int',
            'slack_bot_token' => [
                'sometimes',
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && $value !== '' && !str_starts_with($value, 'xoxb-')) {
                        $fail('The :attribute must be a valid Slack bot token (starting with xoxb-).');
                    }
                },
            ],
            'slack_channel_id' => 'sometimes|nullable|string',
            'slack_user_mentions' => 'sometimes|nullable|array',
            'slack_user_mentions.*.email' => 'required|email',
            'slack_user_mentions.*.slack_user_id' => ['required', 'regex:/^U[A-Z0-9]+$/'],
        ];
    }
}
