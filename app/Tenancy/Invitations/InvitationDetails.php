<?php

namespace App\Tenancy\Invitations;

final readonly class InvitationDetails
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $email,
        public string $role,
        public array $brandIds,
        public string $expiresAt,
    ) {}
}
