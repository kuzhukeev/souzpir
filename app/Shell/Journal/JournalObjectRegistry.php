<?php

namespace App\Shell\Journal;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class JournalObjectRegistry
{
    private const ACTIONS = [
        'knowledge.fact.created' => 'Добавлен факт',
        'knowledge.fact.updated' => 'Изменён факт',
        'knowledge.fact.deleted' => 'Удалён факт',
        'knowledge.release.published' => 'Опубликована версия знаний',
        'seller.customer.merged' => 'Объединены клиенты',
        'seller.customer.manager_assigned' => 'Назначен менеджер',
        'seller.customer.note.created' => 'Добавлена заметка о клиенте',
        'seller.customer.reminder.created' => 'Создано напоминание клиенту',
        'seller.task.status.updated' => 'Изменён статус задачи',
        'seller.customers.exported' => 'Экспортированы клиенты',
        'vision.question.created' => 'Добавлен вопрос',
        'vision.question.updated' => 'Изменён вопрос',
        'vision.question.deleted' => 'Удалён вопрос',
        'vision.questions.approved' => 'Утверждён набор вопросов',
        'vision.measurement.started' => 'Запущен замер',
        'vision.measurement.repeated' => 'Повторён замер',
        'vision.schedule.saved' => 'Изменено расписание замеров',
    ];

    public function describe(object $row, TenantContext $context): array
    {
        $id = (string) $row->subject_id;
        $result = ['name' => $id, 'url' => null];
        if ($row->brand_id !== $context->brandId) {
            return $result;
        }

        $scope = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId];
        if ($row->subject_type === 'contact') {
            $contact = DB::table('contacts')->where($scope)->where('id', $id)->first(['name', 'phone', 'email']);
            if ($contact) {
                $result['name'] = $contact->name ?: ($contact->phone ?: ($contact->email ?: $id));
                $result['url'] = '/seller/customers/'.$id;
            }
        } elseif ($row->subject_type === 'conversation') {
            $conversation = DB::table('conversations')->where($scope)->where('id', $id)->first(['contact_id']);
            if ($conversation) {
                $contact = $conversation->contact_id ? DB::table('contacts')->where($scope)->where('id', $conversation->contact_id)->first(['name', 'phone', 'email']) : null;
                $result['name'] = $contact?->name ?: ($contact?->phone ?: ($contact?->email ?: 'Диалог '.$id));
                $result['url'] = '/seller/dialogs/'.$id;
            }
        }

        return $result;
    }

    public function module(string $action): string
    {
        return str_starts_with($action, 'vision.') ? 'Видение'
            : ((str_starts_with($action, 'seller.') || str_starts_with($action, 'knowledge.')) ? 'ИИ-продавец' : 'Кабинет');
    }

    public function action(string $action): string
    {
        return self::ACTIONS[$action] ?? 'Действие: '.$action;
    }
}
