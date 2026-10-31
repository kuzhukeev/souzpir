<?php

namespace App\Shell\Notifications;

final class EventCatalog
{
    public const TITLES = [
        'seller.conversation.handed_off' => 'Диалог ждёт менеджера',
        'seller.promise.overdue' => 'Обещание просрочено',
        'seller.unknown_question.logged' => 'Неизвестный вопрос',
        'seller.supervisor.completed' => 'Доклад готов',
        'seller.supervisor.blocking_issue' => 'Блокирующая проблема в докладе',
        'seller.channel.disconnected' => 'Канал отключился',
        'seller.quota.threshold_reached' => 'Достигнут порог лимита',
        'vision.measurement.completed' => 'Замер готов',
        'vision.clarification.required' => 'Требуются уточнения',
        'vision.report.updated' => 'Отчёт обновлён',
        'vision.action.overdue' => 'Действие просрочено',
        'vision.competitor.top5_added' => 'Новый конкурент в топ-5',
        'vision.visibility.dropped' => 'Видимость снизилась',
        'shell.member.invited' => 'Участник приглашён',
        'ai.budget.threshold_reached' => 'Достигнут порог бюджета ИИ',
    ];
}
