<?php

use App\Seller\Customers\CustomersController;
use Illuminate\Support\Facades\Route;

return ['web' => static function (): void {
    Route::middleware(['auth.version', 'auth.nostore'])->group(static function (): void {
        Route::get('/seller/customers', [CustomersController::class, 'index'])->name('seller.customers.index');
        Route::get('/seller/customers/{contact}', [CustomersController::class, 'show'])->name('seller.customers.show');
        Route::post('/seller/customers/{contact}/manager', [CustomersController::class, 'assign'])->name('seller.customers.manager');
        Route::post('/seller/customers/{contact}/notes', [CustomersController::class, 'note'])->name('seller.customers.notes.store');
        Route::get('/seller/tasks', [CustomersController::class, 'tasks'])->name('seller.customers.tasks');
        Route::patch('/seller/customer-tasks/{task}', [CustomersController::class, 'updateTask'])->name('seller.customers.tasks.update');
    });
}];
