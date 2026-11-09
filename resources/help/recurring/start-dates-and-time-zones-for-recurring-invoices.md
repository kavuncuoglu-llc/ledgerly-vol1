---
title: Start dates and time zones for recurring invoices
category: recurring
---

# Start dates and time zones for recurring invoices

A recurring invoice is generated on its scheduled date at around 6 a.m. in your account's time zone. This article explains how start dates work, which time zone applies, and what happens at the end of a short month.

## Which time zone do recurring runs use?

Runs use the time zone saved in your profile, not your client's and not your device's. Check or change it under **Settings → Account → Your profile**, in the "Setting your location" section, where you choose country, time zone and currency defaults. Ledgerly does not use device GPS.

If you change your time zone, scheduled runs move with it. A run that was due at 6 a.m. in New York will happen at 6 a.m. in your new time zone.

## What start date can I choose?

The start date must be today or in the future. Past dates are not accepted, because Ledgerly does not create backdated catch-up invoices.

- **Start date is today:** the first invoice is generated within a few minutes of saving the schedule.
- **Start date is in the future:** the first invoice is generated at around 6 a.m. on that date.

The invoice date on a generated invoice is the run date. The due date is that date plus the client's payment terms, for example Net 30.

## What happens on the 29th, 30th and 31st?

A monthly, quarterly or yearly schedule that starts on a day missing from some months uses the last day of the month in those months.

| Start date | February | April | Next January |
|---|---|---|---|
| January 31 | February 28 (29 in a leap year) | April 30 | January 31 |
| January 30 | February 28 (29 in a leap year) | April 30 | January 30 |

Ledgerly remembers your original day, so a schedule that started on the 31st returns to the 31st whenever the month has one.

## How do weekly schedules pick their day?

Weekly and every-2-weeks schedules repeat on the weekday of the start date. A schedule that starts on a Monday always runs on Mondays.

## What if the client is in another time zone?

The client does not need a matching time zone. They receive an auto-sent email at the moment of the run, in their own local time. If you prefer the invoice to arrive during their working day, choose a start date and use Create as draft, then send it manually at the right moment. See Auto-send vs draft for recurring invoices.

## How can I see the next run date?

Open **Recurring**. The **Next run** column shows the next scheduled date in your account time zone. Hover over it to see the exact time Ledgerly expects to run. A paused or ended schedule shows no next run date.
