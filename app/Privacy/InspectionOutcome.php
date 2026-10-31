<?php

namespace App\Privacy;

/** A policy-inspection result, never permission to process or send data. */
enum InspectionOutcome: string
{
    case Clear = 'clear';
    case ReviewRequired = 'review_required';
    case Unavailable = 'unavailable';
}
