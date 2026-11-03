<?php

namespace App\Seller\Settings\Persona;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class PersonaController
{
    private const PHRASE_DEFAULTS = [
        'error' => 'Секунду, уточню пару моментов и вернусь к вам сегодня.',
        'handoff' => 'Передаю менеджеру, он напишет сегодня до конца дня.',
        'out_of_hours' => 'Менеджер подключится в рабочее время.',
        'no_answer' => 'Не могу ответить на этот вопрос.',
        'do_not_disturb' => 'Спасибо, больше не будем вас беспокоить.',
        'reminder' => 'Здравствуйте! Возвращаюсь к нашему разговору.',
        'goodbye' => 'Спасибо за обращение! Хорошего дня.',
    ];

    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    private function draft(TenantContext $tenant): ?object
    {
        return DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->first(['id', 'version']);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (TenantContext $tenant): array {
            $draft = $this->draft($tenant);
            $persona = $draft ? app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true)->persona() : null;

            return ['draft' => $draft, 'persona' => $persona,
                'phrases' => array_replace(self::PHRASE_DEFAULTS, $persona['service_phrases'] ?? []),
                'quickReplies' => $persona['quick_replies'] ?? []];
        });

        return Inertia::render('Seller/Settings/Persona/Index', [...$data, 'status' => session('status')]);
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:40'], 'intro' => ['nullable', 'string', 'max:400'],
            'role' => ['sometimes', 'required', 'string', 'max:100'], 'bot_disclosure' => ['sometimes', 'in:always,if_asked'],
            'address' => ['sometimes', 'in:vy,ty'], 'anchors' => ['sometimes', 'array', 'max:12'],
            'anchors.*' => ['required', 'string', 'max:100'], 'stop_phrases' => ['sometimes', 'array', 'max:30'],
            'stop_phrases.*' => ['required', 'string', 'max:100'], 'hard_bans' => ['sometimes', 'array', 'max:20'],
            'hard_bans.*' => ['required', 'string', 'max:300'], 'max_sentences' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'tone' => ['present', 'array', 'max:8'], 'tone.*' => ['required', 'string', 'max:300'], 'form_bans' => ['present', 'array', 'max:30'], 'form_bans.*' => ['required', 'string', 'max:300'],
            'max_words' => ['required', 'integer', 'min:20', 'max:300'], 'target_min' => ['required', 'integer', 'min:1', 'max:300'], 'target_max' => ['required', 'integer', 'min:1', 'max:300'], 'max_questions' => ['required', 'integer', 'min:1', 'max:2']]);
        if ($input['target_min'] > $input['target_max'] || $input['target_max'] > $input['max_words']) {
            throw ValidationException::withMessages(['target_max' => 'Диапазон должен быть по возрастанию и не превышать максимум слов.']);
        }
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404, 'Создайте черновик базы знаний.');
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $persona = $data['persona'];
            $persona['name'] = $input['name'];
            $persona['intro'] = $input['intro'] ?? '';
            $persona['tone'] = $input['tone'];
            $persona['form_bans'] = $input['form_bans'];
            foreach (['role', 'bot_disclosure', 'address', 'anchors', 'hard_bans'] as $field) {
                if (array_key_exists($field, $input)) {
                    $persona[$field] = $input[$field];
                }
            }
            if (array_key_exists('role', $input) && array_key_exists('stop_phrases', $input)) {
                $persona = app(PersonaResponderAdapter::class)->apply($persona, $input);
            }
            $length = ['target_words' => [(int) $input['target_min'], (int) $input['target_max']],
                'max_words' => (int) $input['max_words'], 'max_questions' => (int) $input['max_questions']];
            if (isset($input['max_sentences'])) {
                $length['max_sentences'] = (int) $input['max_sentences'];
            }
            $persona['length'] = array_replace($persona['length'] ?? [], $length);
            $data['persona'] = $persona;
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Персонаж сохранён в черновике.');
    }

    public function savePhrases(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'phrases' => ['required', 'array:error,handoff,out_of_hours,no_answer,do_not_disturb,reminder,goodbye'],
            'phrases.*' => ['nullable', 'string', 'max:500'],
            'quick_replies' => ['present', 'array', 'max:20'], 'quick_replies.*' => ['required', 'string', 'max:300']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404, 'Создайте черновик базы знаний.');
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $data['persona']['service_phrases'] = array_replace(self::PHRASE_DEFAULTS, $input['phrases']);
            $data['persona']['quick_replies'] = $input['quick_replies'];
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Служебные фразы сохранены в черновике.');
    }
}
