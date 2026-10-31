<?php

namespace App\Seller\Conversations\Data;

enum ChannelType: string
{
    case Telegram = 'telegram';
    case Widget = 'widget';
    case Api = 'api';
    case Email = 'email';
    case Whatsapp = 'whatsapp';
}
