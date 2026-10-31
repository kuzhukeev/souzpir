<?php

use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\EloquentConversationRepository;

return ['bindings' => [ConversationRepository::class => EloquentConversationRepository::class]];
