---
title: Invoice statuses
category: invoices
---

# Invoice statuses

Every invoice in Ledgerly has one of five statuses: Draft, Sent, Paid, Overdue, or Void. The status tells you where the invoice stands and controls what Ledgerly does next, such as sending reminders.

## Overview

| Status | Meaning |
|--------|---------|
| Draft | Created but not sent |
| Sent | Delivered to the client and not yet past due |
| Paid | The full amount has been received |
| Overdue | Past the due date and unpaid |
| Void | Cancelled and kept for the record |

Most invoices move from Draft to Sent to Paid, and some pass through Overdue. You can filter the **Invoices** list by status.

## Draft

A Draft is an invoice you have not sent. It can be edited freely, and the client cannot see it. Drafts do not trigger reminders or late fees and do not count as money owed in your reports.

Recurring invoices that are not set to Auto-send are created as Drafts, so you can review them before sending. An invoice stays a Draft until you send it by email or mark it as sent.

## Sent

Sent means the invoice has been delivered to the client and is not yet past its due date. The client can open the payment page, pay online, or use the client portal. Courtesy reminders (3 days before the due date, if turned on) are sent during this stage.

A partial payment keeps the invoice in Sent, with a smaller outstanding balance, until the full amount arrives.

## Paid

Paid means the full amount has been received, either online or recorded manually with **Record payment** or **Mark as paid**. Ledgerly emails a receipt to the client for each payment. Reminders and late fees stop. See How to mark an invoice as paid.

If you refund a payment, the invoice returns to Sent or Overdue, depending on whether it is past its due date.

## Overdue

An invoice becomes Overdue when it is past its due date and unpaid. Ledgerly sets this automatically the day after the due date, around 1 a.m. in the account's time zone. You do not need to change it by hand.

While an invoice is Overdue:

- Automatic reminders go out 3, 7 and 14 days past due.
- A late fee is applied 30 days past due, unless the invoice is disputed or a payment plan is active.
- A partial payment keeps the invoice Overdue until the balance is zero.

See Payment reminders and Understanding late fees.

## Void

Void means the invoice has been cancelled but is kept for the record. A voided invoice cannot be paid, does not count as revenue, and stops all reminders and late fees. Its invoice number is not reused. See Voiding an invoice.

A Void invoice cannot be turned back into a normal one. If you voided it by mistake, create a new invoice or duplicate the voided one.
