<?php

namespace App\Shell\Profile;

interface EmailChangeSender
{
    /** True only after confirmed transport acceptance; false means uncertain. */
    public function send(#[\SensitiveParameter] string $email, #[\SensitiveParameter] string $confirmationUrl): bool;
}
