---
title: Invoice numbering
category: invoices
---

# Invoice numbering

Ledgerly numbers your invoices automatically, for example INV-4471. The number goes up by one for each new invoice in your account, and you can change the prefix and the next number whenever you need to.

## How does automatic numbering work?

Each invoice gets a number made of a prefix and a counter. With the prefix INV-, your invoices run INV-4471, INV-4472, INV-4473, and so on. The counter is per account, so each Ledgerly account has its own sequence.

Numbers are assigned when an invoice is created, including Drafts. Recurring invoices take the next number each time a schedule generates one. Duplicating an invoice also produces a new number.

## How do I change the prefix or next number?

1. Go to **Settings → Invoices**.
2. Find **Invoice prefix** and **Next invoice number**.
3. Enter your preferred values, for example prefix `ACME-` and next number 1001.
4. Save.

Existing invoices keep the numbers they already have. The change affects invoices created from then on. The next number must be higher than any number already used with the same prefix, so Ledgerly can avoid duplicates.

## Can invoice numbers be reused or skipped?

Numbers are never reused. If you void INV-4471, that number stays with the voided invoice and the next new invoice continues the sequence. Deleting a Draft before it is sent can leave a gap in the sequence, which is normal. A voided invoice remains in your records so any gap can be explained.

If you want to skip numbers, perhaps to continue from a previous system, raise the next invoice number in settings.

## Moving from another system

When you switch from another tool, set the prefix to match your old format and the next number to continue where you left off. For example, if your last invoice was 0342, use a prefix of `0` and a next number of 343, or choose a fresh prefix like `LDG-` to start clearly. Mixing both styles in a single sequence is possible but can confuse clients, so choose one.

## What should my numbering look like?

There are no strict rules, but these habits help clients and accountants:

- Keep it short, with a consistent prefix.
- Do not put personal data in the number.
- Avoid changing the prefix midyear unless you have a reason.
- Mention the invoice number in client conversations; it appears on the PDF, the email subject, and the payment page.

The invoice number is also shown on receipts and in reports such as Payments received, so a clear format makes searching easier. See Invoice statuses to understand what happens to a number when an invoice is voided.
