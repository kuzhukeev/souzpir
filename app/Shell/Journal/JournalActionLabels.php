<?php

namespace App\Shell\Journal;

final class JournalActionLabels
{
    private const VERBS = [
        'created' => 'Создание', 'updated' => 'Изменение', 'deleted' => 'Удаление', 'removed' => 'Удаление',
        'saved' => 'Сохранение', 'published' => 'Публикация', 'activated' => 'Активация', 'restored' => 'Восстановление',
        'autosaved' => 'Автосохранение', 'copied' => 'Копирование', 'checked' => 'Проверка', 'imported' => 'Импорт',
        'exported' => 'Экспорт', 'started' => 'Запуск', 'completed' => 'Завершение', 'confirmed' => 'Подтверждение',
        'repeated' => 'Повтор', 'scheduled' => 'Планирование', 'requested' => 'Запрос', 'revoked' => 'Отзыв',
        'enabled' => 'Включение', 'disabled' => 'Отключение', 'changed' => 'Изменение', 'connected' => 'Подключение',
        'disconnected' => 'Отключение', 'cancelled' => 'Отмена', 'verified' => 'Подтверждение', 'reset' => 'Сброс',
        'configured' => 'Настройка', 'stopped' => 'Остановка', 'transferred' => 'Передача', 'calibrated' => 'Калибровка',
        'issued' => 'Выдача', 'submitted' => 'Отправка', 'reconciled' => 'Сверка', 'registration' => 'Регистрация',
        'received' => 'Получение', 'retry_requested' => 'Повторная отправка', 'note' => 'Комментарий',
        'mode_changed' => 'Изменение режима', 'membership_changed' => 'Изменение состава', 'step_saved' => 'Сохранение шага',
    ];

    private const SUBJECTS = [
        'password' => 'пароля', 'email' => 'почты', 'mfa' => 'двухфакторной защиты', 'session' => 'сеанса', 'sessions' => 'сеансов',
        'profile' => 'профиля', 'avatar' => 'аватара', 'telegram' => 'подключения Telegram', 'settings' => 'настроек',
        'payer' => 'реквизитов', 'plan_request' => 'заявки на тариф', 'export' => 'экспорта данных',
        'api_key' => 'ключа API', 'webhook' => 'вебхука', 'draft' => 'черновика знаний', 'fact' => 'факта',
        'release' => 'версии знаний', 'scenarios' => 'сценариев', 'scenario' => 'сценария', 'delivery' => 'уведомления',
        'notification' => 'уведомления', 'logo' => 'логотипа', 'customer' => 'клиента', 'customers' => 'клиентов',
        'lead' => 'лида', 'mapping' => 'соответствия полей', 'catalog' => 'каталога', 'yml' => 'YML-каталога',
        'stock' => 'остатков', 'price_file' => 'прайс-листа', 'mode' => 'режима канала', 'bots' => 'ботов',
        'note' => 'заметки', 'reminder' => 'напоминания', 'dialog' => 'диалога', 'task' => 'задачи',
        'greetings' => 'приветствий', 'evaluation' => 'оценки', 'judge' => 'оценщика', 'planned_interest' => 'планового интереса',
        'promise' => 'обещания', 'simulation' => 'симуляции', 'supervisor' => 'супервизора', 'recommendation' => 'рекомендации',
        'report' => 'отчёта', 'invitation' => 'приглашения', 'member' => 'участника', 'ticket' => 'обращения',
        'access' => 'доступа', 'action' => 'рекомендации', 'clarification' => 'уточнения', 'grant' => 'разрешения',
        'measurement' => 'замера', 'question' => 'вопроса', 'questions' => 'вопросов', 'quiz' => 'анкеты',
        'publication' => 'публикации', 'recipient' => 'получателя', 'schedule' => 'расписания', 'audit' => 'аудита сайта',
        'presence' => 'присутствия в источнике', 'tracking' => 'отслеживания компаний', 'call' => 'вызова ИИ',
    ];

    public static function label(string $action): string
    {
        $parts = explode('.', $action);
        $verb = self::VERBS[array_pop($parts)] ?? null;
        foreach (array_reverse($parts) as $part) {
            if ($verb && isset(self::SUBJECTS[$part])) {
                return $verb.' '.self::SUBJECTS[$part];
            }
        }

        return 'Действие в кабинете';
    }
}
