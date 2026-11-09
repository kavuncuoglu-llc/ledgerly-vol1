<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HelpCenterController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TicketController;
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
