<?php

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;

return ['bindings' => [KnowledgeRepository::class => DatabaseKnowledgeRepository::class]];
