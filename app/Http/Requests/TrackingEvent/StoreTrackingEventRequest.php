<?php

namespace App\Http\Requests\TrackingEvent;

use App\Enums\TrackingEventAction;
use App\Http\Requests\AuthorizesAfterValidation;
use App\Http\Requests\CattrFormRequest;
use App\Models\Task;
use Illuminate\Validation\Rules\Enum;

class StoreTrackingEventRequest extends CattrFormRequest
{
    use AuthorizesAfterValidation;

    public function authorizeValidated(): bool
    {
        $user = $this->user();

        if (!$user->can('view', Task::find($this->integer('task_id')))) {
            return false;
        }

        if (TrackingEventAction::from($this->input('action')) === TrackingEventAction::Switched
            && !$user->can('view', Task::find($this->integer('previous_task_id')))
        ) {
            return false;
        }

        return true;
    }

    public function _rules(): array
    {
        return [
            'action' => ['required', new Enum(TrackingEventAction::class)],
            'task_id' => 'required|integer|exists:tasks,id',
            'previous_task_id' => 'required_if:action,switched|integer|exists:tasks,id',
        ];
    }
}
