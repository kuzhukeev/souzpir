<?php

namespace App\Vision\Evidence;

final class EntityNormalizer
{
    public const VERSION = 'deterministic-1';

    public function normalize(#[\SensitiveParameter] string $name): string
    {
        $name = str_replace('ё', 'е', mb_strtolower(trim($name)));
        $name = preg_replace('/[«»“”„"\x{2018}\x{2019}]/u', '', $name);
        $name = preg_replace('/^(?:ооо|оао|зао|пао|ао|ип|llc|ltd)\s+/u', '', $name);

        return trim(preg_replace('/\s+/u', ' ', $name));
    }
}
