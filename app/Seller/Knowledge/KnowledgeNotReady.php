<?php

namespace App\Seller\Knowledge;

use RuntimeException;

final class KnowledgeNotReady extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('knowledge_not_ready');
    }
}
