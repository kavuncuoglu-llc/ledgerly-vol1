---
title: Inclusive vs exclusive tax
category: taxes
---

# Inclusive vs exclusive tax

An exclusive tax is added on top of the price you enter. An inclusive tax is already contained in the price you enter. Each tax rate in Ledgerly is set to one or the other in Settings → Taxes.

## What is the difference?

Take a line item with a unit price of 100.00 and a 20% tax rate.

| Type | Price you enter | Tax | Client pays |
| --- | --- | --- | --- |
| Exclusive | 100.00 | 20.00 | 120.00 |
| Inclusive | 100.00 | 16.67 | 100.00 |

With an exclusive rate, the tax is calculated as price × rate. With an inclusive rate, the tax is extracted from the price: price − (price ÷ (1 + rate)). In the example, 100.00 ÷ 1.20 = 83.33 net, so the tax portion is 16.67.

## Which one should I choose?

Choose based on how you quote prices.

- If you quote prices without tax and add tax at the end, use **exclusive**. This is common for business-to-business work and for US sales tax.
- If you quote prices that already include tax, use **inclusive**. This is common where consumer prices are shown tax-included, such as VAT or GST in many countries.

Ledgerly does not tell you which is legally correct for your situation. Ask your accountant if you are unsure.

## How do I set the type?

1. Open **Settings → Taxes**.
2. Click an existing rate, or **Add tax rate**.
3. Under **Type**, choose **Exclusive** or **Inclusive**.
4. Click **Save**.

Because the type belongs to the rate, you can have both kinds in your list, for example "Sales tax 8% (exclusive)" and "VAT 20% (inclusive)". Naming the type in the rate name helps you pick the right one quickly.

## How does it show on the invoice?

The invoice shows each line's amount and a tax summary at the bottom, listing every rate used with the tax amount for that rate. With inclusive rates, the invoice total equals the sum of your line prices, and the tax summary shows how much of that total is tax. With exclusive rates, the tax is added to the subtotal to reach the total.

Discounts are applied before tax is calculated, so a 10% invoice discount lowers the taxable amount.

## Can I mix inclusive and exclusive on one invoice?

Yes. Each line can use a different rate, and each rate has its own type. Lines are calculated independently and then added together, as described in Tax rounding rules.

Changing a rate from exclusive to inclusive changes the total of any draft invoice using it. Invoices that were already sent are not recalculated, so the amount your client was asked to pay does not change.
