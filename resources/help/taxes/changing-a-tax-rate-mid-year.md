---
title: Changing a tax rate mid-year
category: taxes
---

# Changing a tax rate mid-year

When a tax rate changes, the cleanest approach is to add a new rate and archive the old one. That way, invoices from before the change stay on the old rate, and the Tax summary report shows each period separately.

## What happens if I just edit the rate?

If you change the percentage on an existing rate in **Settings → Taxes**, the new percentage applies to any invoice you edit and save afterwards.

- **Sent, Overdue and Paid invoices** keep the amount they were issued with. Their totals do not change.
- **Draft invoices** are recalculated the next time you open and save them.
- **Recurring schedules** use the rate as it stands on each run date, so future invoices use the new percentage.

The report then shows one rate name covering sales at two different percentages. That is harder to read when you file, which is why a new rate is usually better.

## How do I switch to a new rate cleanly?

1. Open **Settings → Taxes** and click **Add tax rate**.
2. Name it clearly, for example "State tax 7% (from 1 July)".
3. Enter the new percentage and set the same type (inclusive or exclusive) as the old one.
4. If the old rate was a default for new lines, untick that option and tick it on the new rate.
5. Save.
6. Click ⋯ beside the old rate and choose **Archive**.

Archived rates stay on old invoices but disappear from the picker.

## What about drafts and recurring invoices already created?

Drafts that still carry the old rate need to be updated manually. Open each draft, remove the old tax from the line, and add the new one. For recurring invoices, open **Recurring → [schedule]**, edit the line items, and swap the rate. The change applies to invoices generated after you save, not to ones already created.

## What if an invoice was sent with the wrong rate?

Do not edit a sent invoice. Void it and issue a corrected one, or follow your accountant's advice about issuing a credit. Voided invoices stay on record and their numbers are not reused.

## How does the Tax summary report handle the change?

The report lists each rate as its own row. If you used two rates in the same year, you get two rows, each with its own taxable sales and tax collected. Pick a date range that covers the whole year to see both. See Using the tax summary when you file.

| Approach | Old invoices | Report |
| --- | --- | --- |
| Edit existing rate | Keep original amounts | One row, mixed percentages |
| Add new rate, archive old | Keep original amounts | Two clear rows |

Ledgerly does not tell you when a rate changes and does not give tax advice. Confirm the effective date with your tax authority.
