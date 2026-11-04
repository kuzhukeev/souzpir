<?php

namespace App\Seller\Dialogs;

use App\Tenancy\TenantContext;

final class DefaultMessageTemplates implements MessageTemplates
{
    public function forBrand(TenantContext $context): array
    {
        return [
            ['name' => 'Приветствие', 'text' => 'Здравствуйте, {имя}! Чем могу помочь?'],
            ['name' => 'Скоро отвечу', 'text' => 'Спасибо за обращение! Скоро вернусь с ответом.'],
        ];
    }
}
