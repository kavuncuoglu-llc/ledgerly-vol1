---
title: Edit or end a recurring schedule
category: recurring
---

# Edit or end a recurring schedule

You can change a recurring schedule whenever you like, and you can end it by setting an end date or by deleting it. Changes only affect invoices created after you save.

## What can I change on an existing schedule?

Open **Recurring** in the left sidebar and click the schedule. Nearly every field can be edited:

- Line items, quantities, unit prices and tax rates
- Interval (weekly, every 2 weeks, monthly, quarterly, yearly)
- Next run date
- End date or number of occurrences
- Auto-send or draft setting
- Payment terms and the invoice footer text
- Late fee setting (Use account default, Custom percent or Off)

The client cannot be changed on an existing schedule. To bill a different client, create a new schedule.

## Do edits change invoices already generated?

No. Invoices that a schedule has already created are separate, ordinary invoices. Editing the schedule only changes what future runs produce. To correct an old invoice, open it from the Invoices list and edit it there, or void it and issue a replacement.

## How do I change the interval or next run date?

1. Open the schedule and click **Edit**.
2. Change the **Interval** or **Next run date**.
3. Click **Save schedule**.

The next run date must be today or later. If you move a monthly schedule from the 1st to the 15th, the following runs keep the new day of the month.

## How do I end a schedule?

There are three ways, depending on what you want:

| Goal | What to do |
|---|---|
| Stop after a known date | Set an **End date** in the schedule. The last invoice is created on or before that date |
| Stop after a number of invoices | Set **Number of occurrences**. The schedule completes after the last run |
| Stop right now | Click **⋯ → End schedule** |

Ending a schedule changes its status to Ended. It stays in the Recurring list under the Ended filter, with its history, and it cannot be resumed. If you need the same setup again, use **⋯ → Duplicate** to start a new schedule from it.

## Can I delete a schedule?

Yes. Click **⋯ → Delete** on an Ended or Paused schedule. Deleting removes the schedule only. Every invoice it generated remains in your Invoices list, untouched, and keeps its invoice number.

If you only want a temporary stop, use the pause option instead. See Pause or resume a recurring schedule.

## What happens when the last occurrence runs?

When a schedule reaches its end date or its final occurrence, Ledgerly generates the last invoice as normal, marks the schedule Ended and emails the account owner a short notice, so a retainer never stops without you knowing. Nothing further is billed after that.
