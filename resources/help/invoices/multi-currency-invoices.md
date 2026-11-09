---
title: Invoicing in other currencies
category: invoices
---

# Invoicing in other currencies

Ledgerly lets you bill clients in more than 30 currencies. The currency is set on the client, so every invoice for that client uses it automatically.

## How is the currency chosen?

Currency is a client-level setting. The default is USD. When you create an invoice for a client whose currency is euros, the line items, discounts, taxes and totals are all shown in euros, and the PDF and payment page use the same currency.

One invoice always uses one currency. You cannot mix currencies on the same invoice.

## How do I set a client's currency?

1. Open **Clients** and select the client, or add a new client.
2. Choose Edit.
3. Choose a value in **Currency**.
4. Save.

Set the currency before creating invoices. Changing it afterward affects new invoices only; invoices that already exist keep the currency they were created in. If a client has existing invoices, consider creating a new client record for the second currency, such as "Acme (EUR)".

## Does Ledgerly convert currencies?

Ledgerly does not convert amounts on the invoice. If you enter 1,000 in euros, the invoice shows 1,000 euros. Your payout arrives in your bank account's currency, and exchange conversion happens as part of the payout, so the amount you receive can differ slightly from the invoice amount.

Reports show amounts per currency rather than adding euros to dollars. Check the currency column on the Revenue and Payments received reports when you export them.

## What about fees, minimums and taxes?

- The minimum invoice amount is a single number in your account setting, so check it against the currency you are billing in. The default is $1.00.
- Online payment fees, 2.9% + 30¢ for cards, are quoted in dollars. For other currencies, the fee percentage is the same and the fixed part is the equivalent amount.
- Late fees are percentages, so they work in any currency. See Understanding late fees.
- Tax rates are percentages and do not depend on currency. Tax IDs and reverse-charge notes can be added in the footer for international sales.

## Which countries can receive payouts?

Payouts are supported for businesses in the US, Canada, the UK, Australia, and most of the EU. Your clients can be anywhere, but bank transfer (ACH) is for US clients only, so international clients usually pay by card. See Payment fees and payouts.

Time zone, country and default currency for your own account are set under **Settings → Account → Your profile**. Ledgerly does not use device GPS for any of these settings.
