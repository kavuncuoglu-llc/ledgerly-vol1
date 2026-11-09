---
title: Payments received report
category: reports
---

# Payments received report

The Payments received report lists every payment recorded in a period, so you can match it against your bank account. Open it from **Reports → Payments received**.

## What does the report list?

Each row is one payment and shows:

- **Date** the payment was received or recorded.
- **Client** and **invoice number**, for example INV-4471.
- **Method**: card, bank transfer, check, cash or other.
- **Amount**, the processing fee for online payments, and the net amount.
- **Status**: Completed, Pending or Refunded.

Totals at the top add up gross payments, fees and net amounts for the range. Card payments carry a fee of 2.9% + 30¢ and ACH bank transfers carry 0.8%, capped at $5. Offline payments have no fee.

## Which payments are included?

All payments are included, whether they were made online or recorded manually with **Record payment** or **Mark as paid**. A single invoice can have several rows if the client paid in parts, and each partial payment is its own row.

Pending ACH payments appear with the status **Pending** and are not counted in the Completed total until they clear, which can take up to 2 business days. See Pending ACH payments.

Refunds are shown on their own rows with a negative amount, or the original payment is tagged **Refunded**. Processing fees are not returned on refunds.

## How do I run it?

1. Open **Reports → Payments received**.
2. Choose a date range: **This month**, **Last month**, **Quarter**, **Year** or **Custom**.
3. Optionally filter by **Method** or **Client**.
4. Click **Export CSV** (Pro and Business) to download the rows.

Dates follow your account's time zone, so a payment made late in the evening in another time zone may fall on a different day. Date ranges and time zones explains this in more detail.

## How do I match this report to my bank?

Online payouts reach your connected bank account within 2 business days, and your first payout can take up to 7 days. Your bank usually shows a single deposit that combines several payments after fees. To reconcile, compare the **Net** column total for the days covered by the payout with the deposit.

For cheques, cash and transfers made directly to your own account, the row date is the date you entered on the payment, so check it matches the date the money arrived.

## How is this different from the Revenue report?

Payments received is based on when money arrived, while the Revenue report is based on invoice dates. Use this report for cash tracking and tax filings that depend on payment dates.

| Report | Based on | Best for |
| --- | --- | --- |
| Payments received | Payment date | Bank reconciliation |
| Revenue | Invoice date | Seeing how much you billed |
