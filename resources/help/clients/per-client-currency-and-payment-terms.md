---
title: Per-client currency and payment terms
category: clients
---

# Per-client currency and payment terms

Each client has their own currency and default payment terms. New invoices for that client use them automatically, so you do not have to set them every time.

## How do I set a client's currency?

1. Open **Clients** and click the client, then **Edit**.
2. Choose a **Currency** from the list. Ledgerly supports 30+ currencies, and USD is the default.
3. Click **Save client**.

Every new invoice for that client is created in that currency. A single invoice cannot mix currencies.

## What happens if I change a currency later?

The change applies to new invoices. Existing invoices keep the currency they were created in, and Ledgerly does not convert them. If you change the currency of a client who already has invoices, their statement and reports show amounts per currency, never added together. For a clean break, it is often simpler to leave the old invoices as they are and bill in the new currency from now on.

## What are default payment terms?

Payment terms decide the due date of an invoice. The default for new clients is **Net 30**, meaning payment is due 30 days after the invoice date. The available options are:

| Option | Due date |
|---|---|
| Due on receipt | The invoice date |
| Net 7 | 7 days after the invoice date |
| Net 14 | 14 days after |
| Net 15 | 15 days after |
| Net 30 | 30 days after |
| Net 45 | 45 days after |
| Net 60 | 60 days after |
| Custom date | A specific date you pick on the invoice |

## How do I set payment terms for a client?

1. Open the client and click **Edit**.
2. Choose **Default payment terms**.
3. Click **Save client**.

When you create an invoice for that client, the due date is filled from these terms. You can still change the terms on any single invoice. Recurring invoices also use the client's terms to set each due date. See Set up a recurring invoice.

## Why do terms matter for overdue invoices and fees?

An invoice becomes Overdue the day after its due date, set automatically at around 1 a.m. in your account's time zone. Late fees are applied 30 days after the due date, and reminders are scheduled from the due date as well. So a client on Net 60 has longer before an invoice is overdue than a client on Net 7.

## Can I set one account-wide default?

Yes. The account default is Net 30, and you can change it in **Settings → Invoices**. A client's own terms override the account default for that client.

**How do currencies affect payments?**

The payment page shows the invoice in the client's currency. Payouts arrive in your bank's currency. See Supported countries and currencies for payments. ACH is available only for US dollar invoices to US clients.
