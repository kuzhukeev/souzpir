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

    /** Keep the published responder prompt in sync with the saved phrase editor. */
    public function withServicePhrases(array $persona): array
    {
        $persona['tone'] = array_values(array_filter($persona['tone'] ?? [],
            fn ($line) => ! str_starts_with((string) $line, 'Служебная фраза (')
                && ! str_starts_with((string) $line, 'Быстрый ответ менеджера:')));
        $labels = [
            'error' => 'ошибка', 'handoff' => 'передача менеджеру',
            'out_of_hours' => 'вне рабочего времени', 'no_answer' => 'нет ответа',
            'do_not_disturb' => 'не беспокоить', 'reminder' => 'напоминание',
            'goodbye' => 'прощание',
        ];
        foreach ($labels as $key => $label) {
            $phrase = trim((string) ($persona['service_phrases'][$key] ?? ''));
            if ($phrase !== '') {
                $persona['tone'][] = "Служебная фраза ({$label}): {$phrase}";
            }
        }
        foreach ($persona['quick_replies'] ?? [] as $reply) {
            $reply = trim((string) $reply);
            if ($reply !== '') {
                $persona['tone'][] = "Быстрый ответ менеджера: {$reply}";
            }
        }

        return $persona;
    }
}
