---
title: Understanding late fees
category: billing
---

# Understanding late fees

Ledgerly can add a late fee of 1.5% of the outstanding balance per month to overdue invoices, prorated by day. Late fees are available on the Pro and Business plans.

## Overview

A late fee is an extra charge added to an unpaid invoice. Customers see it as a separate line on the invoice and in the payment page total.

The default rate is 1.5% per month, adjustable from 0.5% to 10% under **Settings → Billing → Late fees**. Fees on one invoice never exceed 25% of the invoice total.

## When a late fee is applied

Ledgerly applies a late fee automatically 30 days after the invoice due date. Between the due date and day 30 the invoice is Overdue, but no fee is charged.

A late fee is not applied if either of these is true:

- The invoice is marked as disputed (invoice → ⋯ → Mark as disputed).
- A payment plan is active on the invoice.

After that it accrues daily until the invoice is paid.

## How the fee is calculated

The fee is 1.5% (or your configured rate) of the outstanding balance per month. It is calculated on the invoice total excluding any previous late fees, so fees never compound. After a partial payment, the fee is based on what is still owed.

Worked example: an invoice totals $1,000.00 and nothing has been paid. At 1.5% per month, the monthly fee is $15.00, which is $0.50 per day in a 30-day month. After 10 days of accrual the late fee is $5.00 and the client owes $1,005.00.

If the client then pays $400.00 toward the invoice, the outstanding balance becomes $600.00 and the monthly fee drops to $9.00, or $0.30 per day.

## Proration: how fees are prorated for partial months

Ledgerly divides the monthly fee by the number of days in the current calendar month and charges that amount for each day the balance is unpaid.

In the $1,000.00 example, $15.00 per month is $0.50 per day in a 30-day month and about $0.48 in a 31-day month. If the client pays 12 days into a month, that month's fee is 12 days of accrual, not a full $15.00.

## Turning late fees on or off

1. Go to **Settings → Billing → Late fees**.
2. Switch **Charge late fees** on or off.
3. If it is on, set the monthly percent.
4. Save.

Recurring invoices inherit this setting. To override it for one schedule, open **Recurring → [schedule] → Late fee** and choose Use account default, Custom percent, or Off. Overrides apply to future invoices only.

## Waiving a late fee

To remove the fee on a single invoice, open it, select **⋯ → Waive late fee**, and confirm. Waiving is permanent for that invoice, so no further late fees will be charged on it. If a client questions the fee, see Disputing a charge.
