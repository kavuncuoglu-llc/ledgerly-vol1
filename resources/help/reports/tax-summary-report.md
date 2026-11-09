---
title: Tax summary report
category: reports
---

# Tax summary report

The Tax summary report shows tax collected per tax rate for a chosen period. Open it from **Reports → Tax summary**.

## What do I see on the report page?

A table with one row per tax rate used in the period. The columns are:

| Column | Meaning |
| --- | --- |
| Tax rate | The name from Settings → Taxes |
| Rate | The percentage |
| Taxable sales | Net amount the rate was applied to |
| Tax collected | Tax charged |
| Invoices | Number of invoices using the rate |

A row labelled **Tax exempt** lists sales to tax-exempt clients, and zero-rated sales appear under your 0% rate with 0.00 tax. The bottom row adds everything up.

## How do I run the report?

1. Click **Reports** in the sidebar.
2. Open **Tax summary**.
3. Choose a range: **This month**, **Last month**, **Quarter**, **Year** or **Custom**.
4. Optionally pick a single client or a single tax rate from the filters.
5. Click a row to open the list of invoices behind it.

Changing the range reloads the numbers instantly. Ledgerly does not file taxes or give tax advice, so use the figures as a record and confirm filing details with your accountant.

## Which invoices count?

The report uses the invoice date and includes invoices with status Sent, Overdue and Paid. Draft and Void invoices are excluded. The date of an invoice is read in your account's time zone, so an invoice created late on the last day of a quarter counts in that quarter. Time zone settings are described in Date ranges and time zones.

Because the report uses invoice dates, tax appears even if the client has not paid yet. For payment-based figures, use the Payments received report.

## Can I export it?

On Pro and Business, click **Export CSV** to download the table. The file has one row per tax rate, with the same columns as the screen, and opens in Excel, Numbers and Google Sheets. The Free plan can view the report on screen but cannot export CSV. See Exporting reports to CSV.

## Where can I learn how to use the numbers?

This article covers where to find the report and what it displays. For advice on using it with a return, including exempt sales and rounding, see Using the tax summary when you file in the taxes help. Rounding is explained in Tax rounding rules.

## Why is a rate missing from the report?

A rate only appears if it was used on at least one counted invoice in the period. Archived rates still appear for older invoices. If a rate you expect is missing, check the date range and that the invoices are not Draft.
