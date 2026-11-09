<?php

namespace Database\Seeders;

use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Ticket;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class TicketSeeder extends Seeder
{
    public const GENERATED_TICKET_COUNT = 90;

    private const FIRST_TICKET_NUMBER = 10380;

    private const RESERVED_NUMBERS = [
        10422,
        10423,
        10425,
    ];

    private const HOWTO_SUBJECTS = [
        'How do I add a late fee to recurring invoices?' => 'I set up a monthly retainer for one of my clients and I want late fees to apply automatically if they pay late. Where do I turn that on for the recurring schedule?',
        'Can I make an invoice repeat every month?' => 'One of my clients pays me the same amount every month. Is there a way to have the invoice created and sent automatically instead of copying the last one?',
        'How do I change the payment terms on one invoice?' => 'My default is Net 30 but this client got Net 15. Can I change it just for this invoice without touching my settings?',
        'Where do I add my logo?' => 'My invoices still show the Ledgerly branding. How do I upload my own logo and pick my colors?',
        'How do I add a second billing contact?' => 'The client wants invoices sent to both their owner and their accountant. Can an invoice go to two email addresses?',
        'How do I export my invoices to a spreadsheet?' => 'My accountant wants a spreadsheet of everything I invoiced last quarter. What is the quickest way to get one?',
        'How do I record a check payment?' => 'A client mailed me a check. How do I mark the invoice paid and note that it was a check?',
        'How do I add sales tax to an invoice?' => 'I need to charge 7% tax on products but not on services. How do I set that up per line item?',
        'How do I turn on two-factor authentication?' => 'I want to secure my account. Where do I enable two-factor sign-in and what happens if I lose my phone?',
        'How do I void an invoice I sent by mistake?' => 'I sent an invoice with the wrong amount. Can I cancel it and send a corrected one? Will the number be reused?',
    ];

    private const BILLING_SUBJECTS = [
        'Why is my {month} invoice marked late?' => 'Invoice {invoice} says overdue but I thought it was not due yet. Can you tell me what happened and what the late fee is?',
        'Invoice {invoice} still shows unpaid' => 'My client says they paid but invoice {invoice} still shows as unpaid on my side. What should I check?',
        'Late fee on {invoice}' => 'A late fee was added to {invoice} and my client is upset. Can it be removed? They had a payment arrangement with me.',
        'What do I owe on {invoice}?' => 'Can you tell me the current balance on {invoice}, including any late fee?',
        'Payment pending on {invoice}' => 'A bank transfer for {invoice} has been pending for two days. Is that normal?',
        'Reminder emails keep going out for {invoice}' => 'The client already told me they will pay Friday but reminders for {invoice} keep going out. How do I stop them?',
    ];

    private const BUG_SUBJECTS = [
        'The PDF export button does nothing in Safari' => 'I click Download PDF on an invoice in Safari and nothing happens. Chrome works fine.',
        'Invoice email shows as not delivered' => 'My client says they never got the invoice email for {invoice}. The activity log says not delivered.',
        'Dashboard totals do not match the report' => 'The dashboard shows a different revenue total than the Revenue report for last month.',
        'Page keeps logging me out' => 'I get signed out every few minutes on the invoices page and have to log in again.',
    ];

    private const OTHER_SUBJECTS = [
        'Can you add a dark mode?' => 'Working on invoices late at night is rough on the eyes. Is dark mode planned?',
        'I want to dispute a charge from you' => 'I was charged for the Business plan but I thought I downgraded last week.',
        'Do you integrate with my bookkeeping tool?' => 'I use a smaller bookkeeping app. Do you have an integration or an API I can use?',
        'Best pizza near me?' => 'Sorry, wrong box. Can you recommend a good pizza place near the office?',
    ];

    public function run(): void
    {
        $this->seedGeneratedTickets();

        $this->seedAcmeTickets();
    }

    private function seedGeneratedTickets(): void
    {
        $customers = Customer::query()
            ->whereKeyNot($this->acme()->id)
            ->get();

        $receivedTimes = $this->sortedReceivedTimes();

        $number = self::FIRST_TICKET_NUMBER;

        foreach ($receivedTimes as $receivedAt) {
            $number = $this->nextNumberFrom($number);

            $this->createGeneratedTicket(
                number: $number,
                customer: $customers->random(),
                receivedAt: $receivedAt,
            );

            $number++;
        }
    }

    private function createGeneratedTicket(int $number, Customer $customer, Carbon $receivedAt): void
    {
        $category = $this->pickCategory();

        $invoice = $this->pickInvoiceFor($customer, $category);

        [$subject, $body] = $this->pickSubjectAndBody($category, $invoice);

        $status = $this->statusFor($receivedAt);

        $resolvedAt = $status === TicketStatus::Resolved
            ? $receivedAt->copy()->addMinutes(fake()->numberBetween(20, 2880))
            : null;

        Ticket::query()->create([
            'number' => "T-{$number}",
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'subject' => $subject,
            'body' => $body,
            'category' => $category,
            'status' => $status,
            'received_at' => $receivedAt,
            'resolved_at' => $resolvedAt,
        ]);
    }

    private function seedAcmeTickets(): void
    {
        $acme = $this->acme();

        $invoice4471 = $acme->invoices()->where('number', 'INV-4471')->first();

        $invoice4480 = $acme->invoices()->where('number', 'INV-4480')->first();

        Ticket::query()->create([
            'number' => 'T-10422',
            'customer_id' => $acme->id,
            'invoice_id' => $invoice4471->id,
            'subject' => 'Invoice 4471 still shows unpaid',
            'body' => 'Hi, my client at acme@example.com says they paid $1,284.50 on March 3 but invoice 4471 still shows unpaid. Can you check what happened?',
            'category' => TicketCategory::Billing,
            'status' => TicketStatus::Open,
            'received_at' => now()->subDays(2)->setTime(10, 5),
        ]);

        Ticket::query()->create([
            'number' => 'T-10423',
            'customer_id' => $acme->id,
            'invoice_id' => null,
            'subject' => 'Recurring invoice for a retainer client',
            'body' => 'How do I set up a recurring invoice for a retainer client?',
            'category' => TicketCategory::Howto,
            'status' => TicketStatus::Open,
            'received_at' => now()->subDays(2)->setTime(10, 40),
        ]);

        Ticket::query()->create([
            'number' => 'T-10425',
            'customer_id' => $acme->id,
            'invoice_id' => $invoice4480->id,
            'subject' => 'PDF export does nothing in Safari',
            'body' => 'The PDF export button does nothing when I click it on Safari.',
            'category' => TicketCategory::Bug,
            'status' => TicketStatus::Pending,
            'received_at' => now()->subDays(1)->setTime(15, 20),
        ]);
    }

    private function acme(): Customer
    {
        return Customer::query()
            ->where('email', 'billing@acme.example')
            ->first();
    }

    private function sortedReceivedTimes(): array
    {
        $times = [];

        for ($index = 0; $index < self::GENERATED_TICKET_COUNT; $index++) {
            $times[] = now()
                ->subMinutes(fake()->numberBetween(60, 21 * 24 * 60))
                ->startOfMinute();
        }

        usort($times, fn (Carbon $first, Carbon $second) => $first <=> $second);

        return $times;
    }

    private function nextNumberFrom(int $number): int
    {
        while (in_array($number, self::RESERVED_NUMBERS, true)) {
            $number++;
        }

        return $number;
    }

    private function pickCategory(): TicketCategory
    {
        $roll = fake()->numberBetween(1, 100);

        if ($roll <= 45) {
            return TicketCategory::Howto;
        }

        if ($roll <= 75) {
            return TicketCategory::Billing;
        }

        if ($roll <= 90) {
            return TicketCategory::Bug;
        }

        return TicketCategory::Other;
    }

    private function pickInvoiceFor(Customer $customer, TicketCategory $category): Invoice
    {
        if ($category === TicketCategory::Howto) {
            return new Invoice;
        }

        if ($category === TicketCategory::Other) {
            return new Invoice;
        }

        return $customer->invoices()->inRandomOrder()->first() ?? new Invoice;
    }

    private function pickSubjectAndBody(TicketCategory $category, Invoice $invoice): array
    {
        $pool = match ($category) {
            TicketCategory::Howto => self::HOWTO_SUBJECTS,
            TicketCategory::Billing => self::BILLING_SUBJECTS,
            TicketCategory::Bug => self::BUG_SUBJECTS,
            TicketCategory::Other => self::OTHER_SUBJECTS,
        };

        $subject = fake()->randomElement(array_keys($pool));

        $body = $pool[$subject];

        $replacements = [
            '{invoice}' => $invoice->number ?? 'INV-0000',
            '{month}' => ($invoice->issued_at ?? now())->format('F'),
        ];

        return [
            strtr($subject, $replacements),
            strtr($body, $replacements),
        ];
    }

    private function statusFor(Carbon $receivedAt): TicketStatus
    {
        if ($receivedAt->greaterThan(now()->subDays(2))) {
            return TicketStatus::Open;
        }

        if (fake()->numberBetween(1, 100) <= 10) {
            return TicketStatus::Pending;
        }

        return TicketStatus::Resolved;
    }
}
