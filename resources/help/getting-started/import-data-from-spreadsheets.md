---
title: Import data from spreadsheets
category: getting-started
---

# Import data from spreadsheets

You can bring your client list into Ledgerly from a spreadsheet by importing a CSV file. One import can contain up to 1,000 rows.

## What can I import?

Ledgerly imports clients. Invoices and payments cannot be imported from a file. If you want past invoices in your records, create them manually and use **Record payment** to mark them paid, or keep the old spreadsheet as an archive.

## How do I prepare my spreadsheet?

1. Open your spreadsheet in Excel, Google Sheets or Numbers.
2. Put one client per row, with column names in the first row.
3. Use **File → Save as** or **Download** and choose **CSV (comma-separated values)**.

Ledgerly recognizes these columns:

| Column | Required |
| --- | --- |
| Name | Yes |
| Company | No |
| Email | Yes, and you can add Email 2 and Email 3 |
| Phone | No |
| Address | No |
| Currency | No. Three-letter code such as USD or EUR |
| Payment terms | No. For example Net 14 |
| Tax ID | No |
| Notes | No |

If your file has no currency column, the account's default currency is used. You can download an example from the import screen with **Download template**.

## How do I run the import?

1. Open **Clients**.
2. Select **Import**.
3. Choose your CSV file.
4. Match each column in your file to a Ledgerly field. Ledgerly guesses the match from the headings, and you can change it.
5. Review the preview of the first 10 rows.
6. Select **Import clients**.

A summary shows how many clients were created and how many rows were skipped.

## What happens to problem rows?

Rows are skipped if the name or email is missing, if the email is not valid, or if the currency code is unknown. A downloadable error report lists each skipped row with the reason, so you can fix them and import only those rows again. Rows whose email matches an existing client are reported as duplicates and are not imported twice.

## Are there plan limits?

Yes. The Free plan allows up to 3 clients, so an import that would take you over that limit stops once you reach it. Pro and Business have no client limit. If a file is bigger than 1,000 rows, split it into several files.

## What if the import looks wrong?

Special characters appearing garbled usually mean the file was not saved as UTF-8 CSV. Re-export it with that setting. You can archive any client you imported by mistake. Clients that have no invoices can also be deleted.
