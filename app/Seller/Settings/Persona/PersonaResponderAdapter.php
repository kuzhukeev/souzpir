<?php

namespace App\Seller\Settings\Persona;

final class PersonaResponderAdapter
{
    /** Map editor fields to the existing Responder persona prompt fields. */
    public function apply(array $persona, array $input): array
    {
        $persona['tone_input'] = $input['tone'];
        $persona['tone'][] = ($input['address'] ?? 'vy') === 'ty' ? 'Обращайся к клиенту на ты.' : 'Обращайся к клиенту на Вы.';
        $persona['tone'][] = ($input['bot_disclosure'] ?? 'if_asked') === 'always'
            ? 'Сразу честно скажи, что ты автоматический помощник.'
            : 'Если клиент спросит, честно скажи, что ты автоматический помощник.';
        foreach ($input['anchors'] ?? [] as $anchor) {
            $persona['tone'][] = 'Якорь персонажа: '.$anchor;
        }
        if (trim($input['intro'] ?? '') !== '') {
            $persona['tone'][] = 'При первом представлении используй: '.trim($input['intro']);
        }
        $persona['stop_phrases'] = array_values(array_unique(array_merge(
            $input['stop_phrases'], $input['form_bans']
        )));

        return $persona;
    }
}
