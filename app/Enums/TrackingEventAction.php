<?php

namespace App\Enums;

enum TrackingEventAction: string
{
    case Started = 'started';
    case Stopped = 'stopped';
    case Switched = 'switched';
}
