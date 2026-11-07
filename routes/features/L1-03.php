<?php

use App\Shell\Brands\Http\BrandAiPolicyController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->prefix('/brands/{brand}/settings')->group(static function (): void {
            Route::patch('/ai-policy', [BrandAiPolicyController::class, 'update'])->name('brands.settings.ai_policy.update');
        });
    },
];
