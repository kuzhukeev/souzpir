<?php

namespace App\Shell\Support\Contracts;

enum PlanRequestDeliveryStatus: string
{
    case NotConfigured = 'not_configured';
    case Disabled = 'disabled';
    case Sent = 'sent';
    case Uncertain = 'uncertain';
}
