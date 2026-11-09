---
title: Exporting reports to CSV
category: reports
---

# Exporting reports to CSV

On the Pro and Business plans you can download any report as a CSV file and open it in Excel, Numbers or Google Sheets. The Free plan cannot export CSV, but can still view reports and download PDF statements.

## Which reports can I export?

The **Export CSV** button is on these reports:

- Revenue
- Aging
- Payments received
- Tax summary

Client statements are exported as PDF on every plan. Client lists and invoice lists can also be exported from **Clients** and **Invoices** using the same button in the top right corner.

## How do I export a report?

1. Open **Reports** and choose the report.
2. Set the date range and any filters, such as client or method.
3. Click **Export CSV**.
4. Your browser downloads a file named after the report and range, for example `revenue-2026-01-01-to-2026-03-31.csv`.

The file contains exactly what you see on screen. If you filter by a client first, the export includes only that client. Large reports are prepared immediately, and files with many rows may take a few seconds.

## What is inside the file?

The first row holds column names. Dates are written in ISO format (YYYY-MM-DD), which sorts correctly in any spreadsheet. Amounts are plain numbers with a period as the decimal separator and no currency symbol or thousands separator. A separate **Currency** column shows the currency code, such as USD or EUR.

The file uses UTF-8 encoding, so client names with accents or non-Latin letters display correctly. In older versions of Excel, import the file with **Data → From Text/CSV** and choose UTF-8 if characters look wrong.

## What if I'm on the Free plan?

The button is shown but marked **Pro**. Clicking it opens an upgrade prompt. See Upgrading your plan. You can still copy numbers from the screen, or use PDF statements. If you only need a one-time copy of everything, note that Export my data is covered separately in Exporting all your data.

## How is this different from exporting all my data?

A report export is one table for one range. The full export, at **Settings → Account → Export my data**, produces a ZIP of CSV files and PDFs covering your whole account.

| Export | Scope | Plans |
| --- | --- | --- |
| Report CSV | One report, one range | Pro, Business |
| Client statement PDF | One client | All plans |
| Export my data | Whole account | All plans |

## Why is my CSV empty?

An empty file means the report had no rows for the range and filters you chose. Widen the date range, remove filters, and check that invoices are not still in Draft.
