---
title: Tax rounding rules
category: taxes
---

# Tax rounding rules

Ledgerly calculates tax on each line item, rounds that amount to the nearest cent, and then adds the lines together. If your total differs by a cent from another tool, the cause is usually a difference in where rounding happens.

## How does Ledgerly round tax?

For each line and each tax rate on it, Ledgerly:

1. Works out the taxable amount (quantity × unit price, minus the line's share of any invoice discount).
2. Multiplies by the tax rate.
3. Rounds the result to the currency's smallest unit using "round half up", so 0.005 rounds to 0.01.

The invoice's tax total for each rate is the sum of those rounded line amounts. Nothing is rounded again at the invoice level.

## What does that look like in practice?

Take three lines of 3.33 each with an exclusive 8% tax rate.

| Line | Calculation | Rounded tax |
| --- | --- | --- |
| 1 | 3.33 × 8% = 0.2664 | 0.27 |
| 2 | 3.33 × 8% = 0.2664 | 0.27 |
| 3 | 3.33 × 8% = 0.2664 | 0.27 |
| Total | | 0.81 |

If you had calculated tax on the 9.99 subtotal instead, you would get 0.7992, which rounds to 0.80. The difference of one cent is caused by rounding each line. Ledgerly always follows the per-line method.

## Does it depend on the currency?

Yes. Rounding follows the currency set for the client. Most currencies, such as USD, EUR and GBP, use two decimal places. Some currencies use none, such as JPY, and others use three. Tax is rounded to the number of decimals that currency supports.

## How are inclusive taxes rounded?

For an inclusive rate, Ledgerly extracts the tax from the line price, rounds the tax portion, and treats the remainder as the net amount. The line price you entered never changes, so the client pays exactly the sum of your line prices. See Inclusive vs exclusive tax.

## Why doesn't my tax summary match my own calculation?

If you recalculate by hand from invoice subtotals, the answer can differ by a few cents. Check these first:

- You multiplied the invoice subtotal instead of each line.
- You rounded each tax rate separately after combining several rates.
- A discount changed the taxable amounts. Discounts are spread across lines before tax is calculated.

The Tax summary report adds up the already-rounded amounts from your invoices, so it always agrees with what the client was charged. If your tax authority needs a different rounding method, tell your accountant how Ledgerly calculates it. Ledgerly does not give tax advice.
