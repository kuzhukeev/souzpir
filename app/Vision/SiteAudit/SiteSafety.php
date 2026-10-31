<?php

namespace App\Vision\SiteAudit;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/** Stable failures never expose URLs, source content, or nested transport exceptions. */
final class SiteSafety
{
    public static function failure(#[\SensitiveParameter] Throwable $error): Throwable
    {
        $messages = [
            'Загрузка сайтов не включена в этом окружении.',
            'Безопасный обход требует cURL с привязкой DNS.',
            'Страница превышает допустимый размер.',
            'Текст страницы превышает допустимый размер.',
            'Сжатый ответ не поддерживается безопасным обходом.',
            'Тип ответа не поддерживается безопасным обходом.',
            'Страница содержит некорректный текст.',
            'Кодировка ответа не поддерживается безопасным обходом.',
            'Сайт разрешается в запрещённый сетевой адрес.',
            'У сайта нет проверенного публичного адреса.',
            'Адреса с параметрами запроса не поддерживаются.',
            'Импорт сайта нельзя запускать внутри транзакции.',
            'Лимит страниц должен быть от 1 до 100.',
            'Импорт разрешён только с публичного сайта этого бренда.',
            'Обход запрещён robots.txt или политика недоступна.',
            'Переадресация вышла за разрешённый сайт или правила обхода.',
        ];
        $message = in_array($error->getMessage(), $messages, true)
            ? $error->getMessage() : 'Не удалось безопасно обработать сайт.';
        if ($error instanceof InvalidArgumentException) {
            return new InvalidArgumentException($message);
        }
        if ($error instanceof LogicException) {
            return new LogicException($message);
        }

        return new RuntimeException($message);
    }

    /** Query-bearing URLs are not fetched, stored, or exposed in audit results. */
    public static function noQuery(#[\SensitiveParameter] string $url): void
    {
        if (str_contains(explode('#', $url, 2)[0], '?')) {
            throw new InvalidArgumentException('Адреса с параметрами запроса не поддерживаются.');
        }
    }
}
