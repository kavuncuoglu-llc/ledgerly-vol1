<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $status = InvoiceStatus::tryFrom($request->string('status')->toString());

        $invoices = Invoice::query()
            ->with('customer')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('issued_at')
            ->paginate(25)
            ->withQueryString();

        return view('invoices.index', [
            'invoices' => $invoices,
            'statuses' => InvoiceStatus::cases(),
            'activeStatus' => $status,
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load([
            'customer',
            'items',
            'payments',
        ]);

        return view('invoices.show', [
            'invoice' => $invoice,
        ]);
    }
}
