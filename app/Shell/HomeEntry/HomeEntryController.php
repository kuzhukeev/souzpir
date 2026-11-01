<?php

namespace App\Shell\HomeEntry;

use App\Shell\Profile\ProfileAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

final class HomeEntryController
{
    public function __invoke(Request $request, ProfileAccess $access): Response
    {
        return $access->run($request, function ($user): Response {
            Gate::forUser($user)->authorize('profile.own');
            $destinations = [
                ['id' => 'company', 'label' => 'Компания и бренды', 'description' => 'Данные компании и доступные бренды', 'permission' => 'org.settings.write', 'route' => 'organization.show'],
                ['id' => 'team', 'label' => 'Команда', 'description' => 'Участники и приглашения', 'permission' => 'team.read', 'route' => 'team.index'],
                ['id' => 'journal', 'label' => 'Журнал', 'description' => 'Действия в кабинете', 'permission' => 'journal.read', 'route' => 'journal.index'],
                ['id' => 'profile', 'label' => 'Личный профиль', 'description' => 'Данные и безопасность вашей учётной записи', 'permission' => 'profile.own', 'route' => 'profile.show'],
            ];
            $links = [];
            foreach ($destinations as $destination) {
                if (Route::has($destination['route']) && Gate::forUser($user)->allows($destination['permission'])) {
                    $links[] = [
                        'id' => $destination['id'],
                        'label' => $destination['label'],
                        'description' => $destination['description'],
                        'href' => route($destination['route'], absolute: false),
                    ];
                }
            }

            return Inertia::render('Shell/HomeEntry/Index', ['homeLinks' => $links]);
        });
    }
}
