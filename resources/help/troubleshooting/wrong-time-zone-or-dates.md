---
title: Wrong time zone or dates
category: troubleshooting
---

# Wrong time zone or dates

If invoice dates, due dates or report days are a day off, the time zone on your account is probably not the one you expect. Ledgerly uses one time zone for the whole account, which you set in your profile.

## How do I check my time zone?

1. Open **Settings → Account → Your profile**.
2. Look at the **Time zone** field under "Setting your location".
3. If it is wrong, choose the right one, and click **Save**.

You choose your country, time zone and currency defaults there. Ledgerly does not use your device's GPS or browser location, so it will not detect a change on its own. If you travel or move, update it by hand.

## What does the time zone affect?

- The date stamped on new invoices and payments
- When an invoice turns Overdue, which happens the day after the due date at about 1 a.m. in your time zone
- The day a payment falls on in reports
- Late fee and reminder timing
- Scheduled report emails

Everything runs on the account time zone, not the one on your computer, so two team members in different places on a Business plan see the same dates.

## Why does a date look wrong?

Most problems come from one of these:

1. **Time zone set to another country.** An invoice created at 9 p.m. in California is already tomorrow in a UTC+0 account.
2. **Due date arithmetic.** Net 30 counts 30 days from the invoice date. Check the invoice date first.
3. **Overdue appears early or late.** The change happens at about 1 a.m. in your time zone, so it can look different if you check it at midnight.
4. **A payment near midnight** lands on the day in your account time zone, which may not match your bank statement.

## Will changing the time zone fix old invoices?

No. Existing invoice dates and due dates stay as they were entered, since they are plain calendar dates. What changes is how payments and events are assigned to days in reports, so some may move to the day before or after. See Date ranges and time zones.

To fix one invoice, open it, click **Edit**, change the **Invoice date** or **Due date**, and save. You can only edit dates on Draft invoices. For a sent invoice, void it and create a new one, which gets a new number.

## What about my clients' time zones?

Clients see invoice and due dates as calendar dates, the same for everyone. Email times shown in the Activity log use your account time zone.

| Symptom | Check |
| --- | --- |
| Invoice dated yesterday | Account time zone |
| Overdue came a day late | Due date; status changes about 1 a.m. |
| Report total moved after changing zone | Payments regrouped by day |

If the time zone is correct and a date is still wrong, contact support with the invoice number. See Contacting support.
