---
title: Date ranges and time zones
category: reports
---

# Date ranges and time zones

Every report uses a date range, and every date is read in your account's time zone. If a number looks off by a day, the time zone is the first thing to check.

## What date ranges can I choose?

The date selector at the top of each report offers five options:

| Option | Covers |
| --- | --- |
| This month | From the 1st of the current month to today |
| Last month | The whole previous calendar month |
| Quarter | The current calendar quarter (Jan-Mar, Apr-Jun, Jul-Sep, Oct-Dec) up to today |
| Year | The current calendar year up to today |
| Custom | Any start and end date you pick |

Both the start and end dates are inclusive. A custom range from 1 March to 31 March covers every moment of both days. Reports use calendar quarters and calendar years, not fiscal ones. To report on a fiscal year, use a Custom range.

## Which time zone does Ledgerly use?

Your time zone is set at **Settings → Account → Your profile**, under "Setting your location". There you choose your country, time zone and currency defaults. Ledgerly never uses your device's GPS or browser location. The setting applies to the whole account, not to each browser.

Reports, invoice dates, due dates, scheduled emails and overdue changes all use this time zone. On the Business plan, team members see the account time zone, not their own.

## How does the time zone change which day something falls on?

Payments and invoices are stored with an exact time and then assigned to a day in your time zone. A payment made at 11:30 p.m. in New York on 31 March is a March payment for an account set to New York, but an April payment for an account set to London.

Invoices become Overdue the day after the due date at about 1 a.m. in your time zone, so the Aging report buckets change at that time too.

## How do I check or fix my time zone?

1. Open **Settings → Account → Your profile**.
2. Check the **Time zone** field.
3. Change it if wrong and click **Save**.

Changing the time zone does not edit past invoices. Their stored dates stay the same, but reports regroup them using the new zone, so a payment near midnight may move to the next or previous day. If your totals changed, that is the likely reason.

## Why does my report not match another report?

Different reports can disagree for good reasons. Revenue uses invoice dates, and Payments received uses payment dates. Aging uses an **As of** date and ignores the range selector. If you run two reports for the same range and totals differ, compare which date each one is based on. See Revenue report and Payments received report. If the dates themselves look wrong everywhere, see Wrong time zone or dates in the troubleshooting section.
