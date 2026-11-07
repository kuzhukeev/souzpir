<?php

namespace App\Shell\Brands\Console;

use App\Models\Brand;
use App\Shell\Brands\Actions\BrandAiPolicy;
use App\Shell\Brands\Actions\BrandAiPolicyLocked;
use Illuminate\Console\Command;

final class BrandAiPolicyCommand extends Command
{
    protected $signature = 'brand:ai-policy {brand} {--allow=} {--deny=}';

    protected $description = 'Показывает или задаёт согласие бренда на передачу данных в ИИ';

    public function handle(BrandAiPolicy $policy): int
    {
        $brand = Brand::withoutGlobalScopes()->where('id', $this->argument('brand'))->where('status', 'active')->first();
        if (! $brand) {
            $this->error('Активный бренд не найден.');

            return self::FAILURE;
        }

        $allow = $this->parseClasses($this->option('allow'));
        $deny = $this->parseClasses($this->option('deny'));
        if ($allow === null || $deny === null) {
            $this->error('Неизвестный класс данных. Допустимые: '.implode(', ', BrandAiPolicy::CLASSES));

            return self::FAILURE;
        }
        $overlap = array_intersect($allow, $deny);
        if ($overlap !== []) {
            $this->error('Класс указан и в --allow, и в --deny: '.implode(', ', $overlap));

            return self::FAILURE;
        }

        if ($allow === [] && $deny === []) {
            $this->printCurrent($policy->current($brand));

            return self::SUCCESS;
        }

        try {
            $updated = $policy->updateFromConsole($brand, $allow, $deny);
        } catch (BrandAiPolicyLocked $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->printCurrent($updated);

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function parseClasses(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        $classes = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
        foreach ($classes as $class) {
            if (! in_array($class, BrandAiPolicy::CLASSES, true)) {
                return null;
            }
        }

        return $classes;
    }

    /** @param array<string,bool> $current */
    private function printCurrent(array $current): void
    {
        foreach (BrandAiPolicy::CLASSES as $class) {
            $this->line($class.': '.(($current[$class] ?? false) ? 'allow' : 'deny'));
        }
    }
}
