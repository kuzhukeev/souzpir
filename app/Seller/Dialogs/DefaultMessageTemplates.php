<?php

namespace App\Seller\Dialogs;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

final class DefaultMessageTemplates implements MessageTemplates
{
    public function forBrand(TenantContext $context): array
    {
        $disk = Storage::disk('local');
        $path = $this->path($context);
        if ($disk->exists($path)) {
            return json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
        }

        return [
            ['name' => 'Приветствие', 'text' => 'Здравствуйте, {имя}! Чем могу помочь?'],
            ['name' => 'Скоро отвечу', 'text' => 'Спасибо за обращение! Скоро вернусь с ответом.'],
        ];
    }

    public function save(TenantContext $context, array $templates): void
    {
        if (! Storage::disk('local')->put($this->path($context), json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('Не удалось сохранить шаблоны.');
        }
    }

    private function path(TenantContext $context): string
    {
        return 'seller/dialog-templates/'.$context->organizationId.'/'.$context->brandId.'.json';
    }
}
