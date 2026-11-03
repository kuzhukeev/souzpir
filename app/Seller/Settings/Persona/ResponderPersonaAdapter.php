<?php

namespace App\Seller\Settings\Persona;

final class ResponderPersonaAdapter
{
    public function instructions(array $persona): string
    {
        $parts = [];
        if (trim((string) ($persona['intro'] ?? '')) !== '') {
            $parts[] = 'Представление: '.trim($persona['intro']);
        }
        foreach ($persona['form_bans'] ?? [] as $ban) {
            if (is_string($ban) && trim($ban) !== '') {
                $parts[] = 'Не использовать: '.trim($ban);
            }
        }

        return implode("\n", $parts);
    }
}
