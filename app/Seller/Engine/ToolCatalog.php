<?php

namespace App\Seller\Engine;

/** Prototype tool schemas only: this class cannot execute or authorize effects. */
final class ToolCatalog
{
    public static function definitions(): array
    {
        $d = fn (string $name, string $desc, array $props, array $required = []) => [
            'type' => 'function',
            'function' => ['name' => $name, 'description' => $desc, 'parameters' => [
                'type' => 'object', 'properties' => $props ?: new \stdClass, 'required' => $required,
            ]],
        ];

        return [
            'send_price' => $d('send_price', 'Отправить прайс клиенту на почту и/или в мессенджер. Вызывать только когда известен email или мессенджер и прайс ещё не отправлен.',
                ['email' => ['type' => 'string'], 'messenger' => ['type' => 'string'], 'pricelists' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'общий, zerga, volk, premier']]),
            'calc_delivery' => $d('calc_delivery', 'Ориентир стоимости и срока доставки ТК до города клиента. Возвращает диапазон, не точную цену.',
                ['city' => ['type' => 'string'], 'weight_kg' => ['type' => 'number']], ['city']),
            'set_followup' => $d('set_followup', 'Зафиксировать дату следующего контакта с клиентом (когда он сам назвал или согласился).',
                ['datetime' => ['type' => 'string', 'description' => 'ISO или «завтра», «в пятницу», «середина октября»'], 'reason' => ['type' => 'string']], ['datetime']),
            'create_promise' => $d('create_promise', 'Записать обещание клиенту (пришлю видео, посчитаю доставку, уточню).', ['text' => ['type' => 'string']], ['text']),
            'escalate' => $d('escalate', 'Передать клиента живому менеджеру.', ['reason' => ['type' => 'string'], 'summary' => ['type' => 'string']], ['reason']),
            'mark_do_not_disturb' => $d('mark_do_not_disturb', 'Клиент попросил не писать — пометить и прекратить сообщения.', []),
            'check_stock' => $d('check_stock', 'Проверить наличие позиции (заглушка: создаёт обещание уточнить).', ['query' => ['type' => 'string']], ['query']),
            'log_unknown_question' => $d('log_unknown_question', 'Зафиксировать вопрос клиента, на который нет факта в базе; создать задачу менеджеру и обещание клиенту.', ['question' => ['type' => 'string']], ['question']),
            'send_requisites' => $d('send_requisites', 'Отправить реквизиты юрлица клиенту отдельным сообщением.', []),
        ];
    }

    public static function definitionsFor(#[\SensitiveParameter] array $names): array
    {
        $all = self::definitions();

        return array_values(array_filter(array_map(fn ($n) => is_string($n) ? ($all[$n] ?? null) : null, $names)));
    }
}
