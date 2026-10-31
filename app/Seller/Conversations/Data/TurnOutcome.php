<?php

namespace App\Seller\Conversations\Data;

final readonly class TurnOutcome
{
    public function __construct(public string $turnId, public string $conversationId, public string $status, public int $version, public ?string $assistantMessageId, public ?array $goal, public ?array $guard, public array $toolCalls, public array $state, public array $messages = [], public array $tasks = [], public array $events = []) {}

    public function toArray(): array
    {
        return ['turnId' => $this->turnId, 'conversationId' => $this->conversationId, 'status' => $this->status, 'version' => $this->version, 'assistantMessageId' => $this->assistantMessageId, 'goal' => $this->goal, 'guard' => $this->guard, 'toolCalls' => $this->toolCalls, 'state' => $this->state, 'messages' => $this->messages, 'tasks' => $this->tasks, 'events' => $this->events];
    }
}
