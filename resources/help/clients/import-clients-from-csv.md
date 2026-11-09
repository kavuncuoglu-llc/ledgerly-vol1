---
title: Import clients from CSV
category: clients
---

# Import clients from CSV

You can add many clients at once by uploading a CSV file. Each import can contain up to 1,000 rows.

## How do I start an import?

1. Open **Clients** in the sidebar.
2. Click **Import**, then **Upload CSV**.
3. Choose your file, or drag it onto the page.
4. Match your columns to Ledgerly fields (see below).
5. Review the preview and click **Import clients**.

The first row of the file must contain column headings. The file should be saved as CSV with UTF-8 encoding, which keeps accented names intact.

## Which columns can I import?

| Ledgerly field | Typical column heading |
|---|---|
| Name (required) | name |
| Company | company |
| Email 1, 2, 3 | email, email_2, email_3 |
| Phone | phone |
| Address | address |
| Currency | currency (a code such as USD, EUR or GBP) |
| Payment terms | payment_terms (for example Net 30) |
| Tax ID | tax_id |
| Notes | notes |

You do not need to use these exact headings. In the mapping step you choose which column goes to which field, and you can skip columns you do not need. Only the name is required.

## What are the limits?

- A maximum of **1,000 rows** per file. Split larger files and import them one after the other.
- On the Free plan, the total number of clients is limited to 3, so an import that would go over the limit is stopped. Pro and Business have no client limit.
- Up to 3 email addresses per client.

## How does Ledgerly handle duplicates?

During the preview, Ledgerly compares each row with your existing clients by email address. Rows that match an existing client are marked **Possible duplicate**, and by default they are skipped. You can choose to import them anyway. If you later find duplicates, they can be combined. See Merge duplicate clients.

## What if rows have errors?

Rows with problems are listed in the preview before anything is saved. Typical reasons:

- No name
- An invalid email address
- An unknown currency code
- Payment terms that Ledgerly does not recognise

Fix these in your file and upload again, or import only the valid rows. After the import, a summary shows how many clients were created, skipped and rejected, with a downloadable list of rejected rows and the reason for each.

## Can I undo an import?

There is no single undo button. Clients created by an import that have no invoices can be deleted from the Clients list, and you can select several at once. See Edit, archive or delete a client.

**Will it send emails to my imported clients?**

No. Importing creates client records only. Nothing is sent to anyone until you send an invoice or the portal link.
