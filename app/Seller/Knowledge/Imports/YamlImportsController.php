<?php

namespace App\Seller\Knowledge\Imports;

use App\Models\User;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use ZipArchive;

final class YamlImportsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    public function preview(Request $request): mixed
    {
        return $this->access($request, function (TenantContext $tenant) use ($request): mixed {
            $request->session()->forget('seller_knowledge_yaml_preview');
            $input = $request->validate(['files' => ['required', 'array', 'min:1', 'max:10'], 'files.*' => ['required', 'file', 'max:20480']]);
            $files = $input['files'];
            $contents = [];
            if (count($files) === 1 && strtolower($files[0]->getClientOriginalExtension()) === 'zip') {
                $zip = new ZipArchive;
                if ($zip->open($files[0]->getRealPath()) !== true) {
                    throw ValidationException::withMessages(['files' => 'Не удалось открыть ZIP.']);
                }
                try {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        $name = $stat['name'] ?? '';
                        if (! in_array($name, array_map(fn ($key) => $key.'.yaml', YamlKnowledgeImporter::FILES), true) || isset($contents[$name]) || ($stat['size'] ?? 0) > 2 * 1024 * 1024) {
                            throw ValidationException::withMessages(['files' => 'ZIP должен содержать только десять YAML в корне.']);
                        }
                        $contents[$name] = $zip->getFromIndex($i);
                    }
                } finally {
                    $zip->close();
                }
            } else {
                foreach ($files as $file) {
                    $name = $file->getClientOriginalName();
                    if (! in_array($name, array_map(fn ($key) => $key.'.yaml', YamlKnowledgeImporter::FILES), true) || isset($contents[$name]) || $file->getSize() > 2 * 1024 * 1024) {
                        throw ValidationException::withMessages(['files' => 'Выберите десять файлов YAML с исходными именами.']);
                    }
                    $contents[$name] = file_get_contents($file->getRealPath());
                }
            }
            $data = [];
            try {
                foreach (YamlKnowledgeImporter::FILES as $name) {
                    if (! isset($contents[$name.'.yaml']) || ! is_string($contents[$name.'.yaml'])) {
                        throw new \InvalidArgumentException;
                    }
                    $parsed = Yaml::parse($contents[$name.'.yaml'], Yaml::PARSE_EXCEPTION_ON_ALIAS);
                    if (! is_array($parsed)) {
                        throw new \InvalidArgumentException;
                    }
                    $data[$name] = $parsed;
                }
            } catch (Throwable) {
                throw ValidationException::withMessages(['files' => 'Проверьте наличие и содержимое десяти YAML.']);
            }
            $request->session()->put('seller_knowledge_yaml_preview', ['organization' => $tenant->organizationId, 'brand' => $tenant->brandId, 'data' => $data]);

            return response()->json(['sections' => collect($data)->map(fn ($section) => count($section))->all()])->header('Cache-Control', 'private, no-store');
        });
    }

    public function publish(Request $request): mixed
    {
        return $this->access($request, function (TenantContext $tenant) use ($request): mixed {
            $preview = $request->session()->get('seller_knowledge_yaml_preview');
            abort_unless(is_array($preview) && $preview['organization'] === $tenant->organizationId && $preview['brand'] === $tenant->brandId, 404);
            try {
                app(YamlKnowledgeImporter::class)->publishData($tenant, $preview['data'], true);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['files' => 'Структура YAML не подходит для базы знаний.']);
            }
            $request->session()->forget('seller_knowledge_yaml_preview');

            return back()->with('status', 'База знаний импортирована и опубликована.');
        });
    }
}
