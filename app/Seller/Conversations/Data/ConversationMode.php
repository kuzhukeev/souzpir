<?php

namespace App\Seller\Conversations\Data;

enum ConversationMode: string
{
    case Bot = 'bot';
    case Suggest = 'suggest';
    case Manager = 'manager';
    case Paused = 'paused';
}
