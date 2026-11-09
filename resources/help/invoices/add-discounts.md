---
title: Adding discounts to an invoice
category: invoices
---

# Adding discounts to an invoice

Ledgerly lets you apply one discount to an invoice, either as a percentage or as a fixed amount. The discount reduces the invoice total and is shown clearly to your client.

## What kinds of discounts are available?

Each invoice can have one discount, set at invoice level:

| Type | Example | Effect on a $500.00 subtotal |
|------|---------|------------------------------|
| Percent | 10% | Takes off $50.00, total $450.00 |
| Fixed amount | $75.00 | Takes off $75.00, total $425.00 |

There is no separate discount column on individual line items. If you want to discount one item, lower its unit price or add a negative line item with a clear description, such as "Loyalty credit".

## How do I add a discount?

1. Open the invoice in edit mode, or start a new one.
2. Below the line items, find **Discount**.
3. Choose **Percent** or **Fixed amount**.
4. Enter the value.
5. Save.

The totals update right away so you can check the result before saving. The discount appears on the PDF and payment page as its own line.

## How do discounts interact with tax?

The discount is applied to the line item subtotal before tax is calculated, so tax is charged on the lower amount. For example, a $500.00 subtotal with a 10% discount becomes $450.00. With a 10% tax rate, tax is $45.00 and the total is $495.00.

Tax rates are defined in **Settings → Taxes**, and each line can have up to 5 tax rates. Ledgerly does not give tax advice, so check with your accountant if you are unsure how discounts should be treated in your region.

## What about discounts for paying early?

Ledgerly does not have an automatic early-payment discount. If you want to reward fast payers, you can state the offer in the invoice footer, for example "Pay within 10 days and take 2% off". When the client pays early, record the lower amount. Only the received amount closes the balance, so you would then also need to reduce the invoice by editing the discount, which is only possible while it is a Draft, or voiding and reissuing it.

## Can a discount bring an invoice below the minimum?

A discount that pushes the total under your minimum invoice amount (default $1.00) stops you from saving. A 100% discount gives a $0.00 total, which is below the default minimum. See Setting minimum invoice amounts.

Discounts are fully supported on all plans, including Free. They also carry over to recurring invoices, so a schedule can include a standing loyalty discount.
