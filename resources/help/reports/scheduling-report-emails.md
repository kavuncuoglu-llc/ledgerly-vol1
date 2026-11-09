---
title: Scheduling report emails
category: reports
---

# Scheduling report emails

You can have Ledgerly email you a report automatically every week or month, so you do not have to remember to open it. Scheduled reports are available on Pro and Business.

## Which reports can be scheduled?

You can schedule the Revenue, Aging, Payments received and Tax summary reports. Each schedule sends one report. If you want several, create several schedules. Client statements are not scheduled, because they are sent to clients one at a time.

## How do I create a schedule?

1. Open **Reports** and choose the report.
2. Click **Schedule email** in the top right.
3. Choose how often: **Weekly** or **Monthly**.
4. For weekly, pick the day of the week. For monthly, pick the day of the month (1 to 28).
5. Choose the time of day.
6. Add up to 5 recipient email addresses.
7. Choose **CSV attachment** if you want the data file included.
8. Click **Save schedule**.

Manage existing schedules from **Settings → Account → Scheduled reports**.

## What does the email contain?

Each email has a short summary in the body: the main totals for the period and a link to open the full report in Ledgerly. If CSV is switched on, the file is attached. Emails are sent from `invoices@mail.ledgerly.example`.

The period covered is the one that just ended. A weekly schedule covers the previous 7 days, and a monthly schedule covers the previous calendar month. The Aging report is always as of the day it is sent.

## When exactly will it be sent?

Times use the time zone in **Settings → Account → Your profile**. If you change your time zone, schedules move with it. See Date ranges and time zones. A monthly schedule set for day 28 runs on the 28th in every month, including February.

| Option | Choices |
| --- | --- |
| Frequency | Weekly, monthly |
| Weekly day | Monday to Sunday |
| Monthly day | 1 to 28 |
| Recipients | Up to 5 |
| Attachment | Optional CSV |

## Can my accountant receive them?

Yes. Add your accountant's address as a recipient. They will receive the summary and any CSV attachment, but the link to the full report only works for people with a Ledgerly login. On Business you can also add them as a team member under **Settings → Team**. See the team articles for roles.

## How do I pause or delete a schedule?

Open **Settings → Account → Scheduled reports**. Use the switch to pause a schedule, or the bin icon to delete it. Schedules also stop if your account drops to the Free plan, and resume when you upgrade again. If a recipient's mailbox rejects the email, check the details in the Activity log.
