<?php

namespace App\Http\Controllers\Api;

use App\Enums\TrackingEventAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\TrackingEvent\StoreTrackingEventRequest;
use App\Jobs\SendSlackTrackingNotification;
use Illuminate\Http\JsonResponse;

class TrackingEventController extends Controller
{
    /**
     * @api             {post} /tracking/events Create
     * @apiDescription  Reports a time-tracking event (start/stop/switch) so interested integrations
     *                  (eg. Slack notifications) can react to it.
     *
     * @apiVersion      4.0.0
     * @apiName         CreateTrackingEvent
     * @apiGroup        TrackingEvent
     *
     * @apiUse          AuthHeader
     *
     * @apiParam {String}   action             Event type. One of `started`, `stopped`, `switched`.
     * @apiParam {Integer}  task_id            The task the event relates to.
     * @apiParam {Integer}  [previous_task_id] The task being switched from. Required when `action` is `switched`.
     *
     * @apiSuccessExample {json} Response Example
     *  HTTP/1.1 204 No Content
     *  {
     *  }
     *
     * @apiUse          400Error
     * @apiUse          UnauthorizedError
     * @apiUse          ForbiddenError
     */
    public function store(StoreTrackingEventRequest $request): JsonResponse
    {
        SendSlackTrackingNotification::dispatch(
            $request->user()->id,
            TrackingEventAction::from($request->input('action')),
            $request->integer('task_id'),
            $request->filled('previous_task_id') ? $request->integer('previous_task_id') : null,
        );

        return responder()->success()->respond(204);
    }
}
