---
title: Aging report
category: reports
---

# Aging report

The Aging report shows who owes you money and how overdue it is. Open it from **Reports → Aging**.

## What are the aging buckets?

The report sorts every unpaid invoice into one of five buckets based on how many days have passed since the due date:

| Bucket | Meaning |
| --- | --- |
| Current | Not yet due |
| 1-30 | 1 to 30 days past due |
| 31-60 | 31 to 60 days past due |
| 61-90 | 61 to 90 days past due |
| 90+ | More than 90 days past due |

The top of the report shows the total outstanding in each bucket. Below that, each client has a row with their balance in each bucket, so you can spot which clients are slipping.

## Which invoices appear?

Only invoices with a balance still owed appear: status Sent and Overdue. Paid, Void and Draft invoices are left out. If an invoice was partly paid, only the outstanding balance is counted, not the full invoice total.

An invoice becomes Overdue the day after its due date, at about 1 a.m. in your account's time zone, and then moves into the 1-30 bucket. Late fees are included in the outstanding balance when they apply.

Disputed invoices stay in the report, because they still carry a balance. They are marked with a **Disputed** tag so you can tell them apart.

## How do I use it?

1. Open **Reports → Aging**.
2. The report always shows balances as of today. Use the **As of** date to look at an earlier day.
3. Click a bucket total to see the invoices inside it.
4. Click a client row to open that client's invoices.
5. Click **Export CSV** (Pro and Business) to download the table.

The date range options used on other reports do not apply here, because aging is a snapshot of one day. See Date ranges and time zones for how the **As of** date is read.

## What can I do about older balances?

Aging is most useful when you act on it. From a client row you can send a manual reminder (maximum one per 24 hours per invoice), start a payment plan, or mark an invoice as disputed. Pro and Business accounts also have automatic reminders at 3, 7 and 14 days past due, set in **Settings → Billing → Reminders**. Late fees can be added after 30 days past due, as described in the late fees articles.

For a document you can send to the client, create a statement instead. See Client statements.

## Why is an invoice in the wrong bucket?

Check the due date on the invoice. The bucket depends on the due date, not the invoice date, so a Net 60 invoice stays in Current for 60 days. Also check the time zone in **Settings → Account → Your profile**, since it decides when each day begins.
