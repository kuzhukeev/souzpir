<?php

use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\KnowledgeBridge\GrantAuthority;
use App\Vision\KnowledgeBridge\PublishedKnowledgeBridge;
use App\Vision\KnowledgeGrants\DatabaseGrantAuthority;

return [
    'bindings' => [KnowledgeGate::class => PublishedKnowledgeBridge::class, GrantAuthority::class => DatabaseGrantAuthority::class],
];
