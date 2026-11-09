<?php

use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HelpCenterController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ToolApprovalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', 'customers');

Route::get('customers', [CustomerController::class, 'index']);
Route::get('customers/{customer}', [CustomerController::class, 'show']);

Route::get('invoices', [InvoiceController::class, 'index']);
Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);

Route::get('help', [HelpCenterController::class, 'index']);
Route::get('help/{category}/{slug}', [HelpCenterController::class, 'show']);

Route::get('inbox', [TicketController::class, 'index']);
Route::get('inbox/{ticket}', [TicketController::class, 'show']);

Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');

Route::post('support/ask', [SupportController::class, 'ask'])->middleware([
    'auth',
    'throttle:ai',
]);

Route::middleware('auth')->group(function () {
    Route::post('support/conversations/{conversation}/close', [ConversationController::class, 'close'])->middleware('throttle:ai');

    Route::get('support/memories', [MemoryController::class, 'index']);

    Route::delete('support/memories/{userMemory}', [MemoryController::class, 'destroy']);

    Route::get('support/approvals/{pendingToolCall}', [ToolApprovalController::class, 'show'])->name('tool-approvals.show');

    Route::post('support/approve/{pendingToolCall}', [ToolApprovalController::class, 'approve'])->middleware('throttle:ai');

    Route::post('support/decline/{pendingToolCall}', [ToolApprovalController::class, 'decline'])->middleware('throttle:ai');
});
