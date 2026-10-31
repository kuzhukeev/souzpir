<?php

use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\KnowledgeBridge\DenyGrantAuthority;
use App\Vision\KnowledgeBridge\GrantAuthority;
use App\Vision\KnowledgeBridge\PublishedKnowledgeBridge;

return [
    'bindings' => [KnowledgeGate::class => PublishedKnowledgeBridge::class, GrantAuthority::class => DenyGrantAuthority::class],
];
