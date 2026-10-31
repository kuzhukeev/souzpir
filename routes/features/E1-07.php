<?php

use App\Shell\Billing\Http\BillingController;
use Illuminate\Support\Facades\Route;

return [
    'web' => static function (): void {
        Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
            foreach (['/billing' => 'overview', '/billing/usage' => 'usage', '/billing/plans' => 'plans', '/billing/invoices' => 'invoices', '/billing/payer' => 'payer'] as $path => $tab) {
                Route::get($path, [BillingController::class, 'index'])->defaults('tab', $tab)->name('billing.'.$tab);
            }
            Route::get('/billing/payer/data', [BillingController::class, 'payerData'])->name('billing.payer.data');
            Route::put('/billing/payer', [BillingController::class, 'updatePayer'])->name('billing.payer.update');
            Route::post('/billing/plans/request', [BillingController::class, 'requestPlan'])->middleware('throttle:10,1')->name('billing.plans.request');
        });
    },
];
