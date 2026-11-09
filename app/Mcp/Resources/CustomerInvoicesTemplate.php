<?php

namespace App\Mcp\Resources;

use App\Ai\Tools\InvoiceSummary;
use App\Mcp\Exceptions\McpAuthenticationRequired;
use App\Mcp\McpIdentity;
use App\Models\Invoice;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('customer_invoices')]
#[Description('The invoices of the authenticated customer, addressed as ledgerly://customers/{id}/invoices. Only the token owner\'s own customer id resolves.')]
#[MimeType('application/json')]
final class CustomerInvoicesTemplate extends Resource implements HasUriTemplate
{
    public const MAX_INVOICES = 100;

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('ledgerly://customers/{id}/invoices');
    }

    public function handle(Request $request, McpIdentity $identity): Response
    {
        try {
            $customer = $identity->contextFor($request)->customer();
        } catch (McpAuthenticationRequired $exception) {
            return Response::error($exception->getMessage());
        }

        if ((string) $customer->id !== (string) $request->get('id')) {
            return Response::error('Customer not found.');
        }

        return Response::json(
            $customer->invoices()
                ->with('customer')
                ->latest('issued_at')
                ->limit(self::MAX_INVOICES)
                ->get()
                ->map(fn (Invoice $invoice): array => InvoiceSummary::of($invoice))
                ->all(),
        );
    }
}
