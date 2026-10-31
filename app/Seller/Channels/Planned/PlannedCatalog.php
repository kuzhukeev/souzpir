<?php

namespace App\Seller\Channels\Planned;

final class PlannedCatalog
{
    public const ITEMS = [
        'vk' => ['VK', 'Общение с клиентами ВКонтакте.'],
        'whatsapp' => ['WhatsApp', 'Общение с клиентами в WhatsApp.'],
        'google_sheets' => ['Google Sheets', 'Обмен данными с таблицами.'],
        'amocrm' => ['amoCRM', 'Интеграция с CRM.'],
        'bitrix24' => ['Битрикс24', 'Интеграция с CRM.'],
        'external_url' => ['Внешний URL', 'Передача данных во внешнюю систему.'],
        '1c' => ['1С', 'Интеграция с системой 1С.'],
        'calendar' => ['Почта и календарь', 'Интеграция с почтой и календарём.'],
    ];
}
