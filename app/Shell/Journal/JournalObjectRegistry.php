<?php

namespace App\Shell\Journal;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class JournalObjectRegistry
{
    private const OBJECTS = [
        'knowledge_release' => ['knowledge_releases', 'Версия знаний', '/seller/knowledge'],
        'question_candidate' => ['question_candidates', 'Вопрос', '/vision/questions'],
        'question_set' => ['question_sets', 'Набор вопросов', '/vision/questions'],
        'measurement' => ['measurements', 'Замер', '/vision/runs'],
        'vision_report' => ['vision_reports', 'Отчёт', '/vision/report'],
        'seller_persona' => ['seller_personas', 'Персона', '/seller/quality/scenarios'],
        'seller_task' => ['seller_tasks', 'Задача', '/seller/tasks'],
        'channel_account' => ['channel_accounts', 'Канал', '/seller/channels'],
        'brand_profile' => ['brand_profiles', 'Профиль бренда', '/vision/profile'],
        'vision_action' => ['actions', 'Рекомендация', '/vision/actions'],
        'report_publication' => ['report_publications', 'Публикация', '/vision/publications'],
        'site_audit' => ['audit_runs', 'Аудит сайта', '/vision/site'],
        'monitoring_schedule' => ['monitoring_schedules', 'Расписание', '/vision/runs'],
    ];

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
        } elseif ($row->subject_type === 'knowledge_fact') {
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                ->join('knowledge_releases as r', 'r.id', '=', 'g.release_id')
                ->where('r.organization_id', $context->organizationId)->where('r.brand_id', $context->brandId)
                ->where('f.logical_id', $id)->orderByDesc('r.version')->value('f.text');
            if ($fact) {
                $result = ['name' => $fact, 'url' => '/seller/knowledge'];
            }
        } elseif (isset(self::OBJECTS[$row->subject_type])) {
            [$table, $label, $url] = self::OBJECTS[$row->subject_type];
            if (Schema::hasTable($table) && Schema::hasColumns($table, ['organization_id', 'brand_id'])) {
                $object = DB::table($table)->where($scope)->where('id', $id)->first();
                if ($object) {
                    $name = $object->name ?? $object->title ?? $object->text ?? (isset($object->version) ? $label.' №'.$object->version : null);
                    $result = ['name' => $name ?: $label.' '.$id, 'url' => $url];
                }
            }
        } elseif ($row->subject_type === 'brand' && $id === $context->brandId) {
            $name = DB::table('brands')->where('organization_id', $context->organizationId)->where('id', $id)->value('name');
            if ($name) {
                $result = ['name' => $name, 'url' => '/brands/'.$id.'/settings'];
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
        return self::ACTIONS[$action] ?? JournalActionLabels::label($action);
    }
}
