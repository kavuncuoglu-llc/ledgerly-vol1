---
title: Recurring invoices and taxes
category: recurring
---

# Recurring invoices and taxes

Tax rates on a recurring schedule work exactly as they do on a one-off invoice: you set them per line item, and every generated invoice repeats them. Ledgerly calculates the tax but does not file taxes or give tax advice.

## How do I add tax to a recurring schedule?

1. Open **Recurring** and click the schedule, then **Edit**.
2. On each line item, open the **Tax** field and choose one or more rates.
3. Click **Save schedule**.

Rates come from the list you maintain in **Settings → Taxes**, where each rate has a name, a percentage, and a setting for inclusive or exclusive. You can attach up to 5 tax rates to a single line.

## What is the difference between inclusive and exclusive?

| Type | Meaning | Example on a $100.00 line at 10% |
|---|---|---|
| Exclusive | Tax is added on top of the price | Line $100.00, tax $10.00, total $110.00 |
| Inclusive | Tax is already part of the price | Line total $100.00, of which about $9.09 is tax |

The type is part of the rate itself, so every invoice the schedule creates treats it the same way.

## What happens if I change a tax rate later?

If you edit a rate in **Settings → Taxes**, invoices generated after the change use the new percentage. Invoices already generated keep the percentage they were created with.

To change the rate on a single schedule only, edit that schedule's line items and pick a different rate. Do not delete a rate that a live schedule still uses, because the next run for that schedule would fail. See What happens when a recurring run fails.

## Which tax settings come from the client?

The client's **Tax ID** is printed on every invoice a schedule generates, taken from the client record at the time of the run. Set it under **Clients → [client] → Edit**.

For reverse charge or zero-rated sales, Ledgerly supports a note in the invoice footer text. Add the required wording to the schedule's footer, such as "Reverse charge applies", and it appears on each invoice. Check the correct wording with your tax adviser.

## How do I see the tax collected?

Open **Reports → Tax summary**. It lists tax collected per rate for the date range you choose: this month, last month, quarter, year or custom. Invoices from recurring schedules are included like any other invoice. Voided invoices are excluded.

## Does tax change the late fee?

The late fee is a percentage of the outstanding balance, which includes tax, and is calculated on the invoice total excluding earlier late fees. If you override the late fee for a schedule, see Adding late fees to recurring invoices.

**Are discounts applied before or after tax?**

A percent or fixed discount set on the schedule applies to the invoice subtotal, and tax is calculated on the discounted amounts. Check the preview on the schedule page to confirm the total before you save.
