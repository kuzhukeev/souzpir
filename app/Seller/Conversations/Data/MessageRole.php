<?php

namespace App\Seller\Conversations\Data;

enum MessageRole: string
{
    case Client = 'client';
    case Agent = 'agent';
    case System = 'system';
    case Tool = 'tool';
    case Manager = 'manager';
}
