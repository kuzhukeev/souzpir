<?php

namespace App\Shell\Notifications;

use App\Models\Membership;
use App\Providers\Features\FeatureRegistry;
use App\Shell\Notifications\Contracts\NotificationRouter;
use LogicException;

final class NotificationRouting
{
    public function __construct(private FeatureRegistry $features) {}

    public function route(DomainEvent $event, Membership $member, string $channel): string
    {
        $router = $this->router();
        $result = $router?->route($event, $member, $channel) ?? 'immediate';
        if (! in_array($result, ['immediate', 'digest', 'skip'], true)) {
            throw new LogicException('Invalid notification routing decision.');
        }

        return $result;
    }

    public function allowsImmediate(DomainEvent $event, Membership $member, string $channel): bool
    {
        return $this->router()?->allowsImmediate($event, $member, $channel) ?? true;
    }

    private function router(): ?NotificationRouter
    {
        $class = $this->features->extensions()['notifications.router'] ?? null;
        if ($class === null) {
            return null;
        }
        $instance = app($class);
        if (! $instance instanceof NotificationRouter) {
            throw new LogicException('Invalid notification routing extension.');
        }

        return $instance;
    }
}
