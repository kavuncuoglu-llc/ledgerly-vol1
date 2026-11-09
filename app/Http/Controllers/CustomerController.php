<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Contracts\View\View;

class CustomerController extends Controller
{
    public function index(): View
    {
        $customers = Customer::query()
            ->withCount('invoices')
            ->withCount([
                'invoices as overdue_invoices_count' => fn ($query) => $query->overdue(),
            ])
            ->orderBy('name')
            ->paginate(20);

        return view('customers.index', [
            'customers' => $customers,
        ]);
    }

    public function show(Customer $customer): View
    {
        $invoices = $customer->invoices()
            ->orderByDesc('issued_at')
            ->get();

        return view('customers.show', [
            'customer' => $customer,
            'invoices' => $invoices,
            'tickets' => $customer->tickets()->orderByDesc('received_at')->get(),
        ]);
    }
}
