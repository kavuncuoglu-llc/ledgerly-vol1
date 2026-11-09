---
title: Tax-exempt clients
category: taxes
---

# Tax-exempt clients

Mark a client as tax-exempt and Ledgerly stops adding tax to their new invoices. This suits clients such as charities or resellers who hold an exemption certificate. Whether a client qualifies is a decision for you and your tax authority, not Ledgerly.

## How do I mark a client as tax-exempt?

1. Open **Clients** in the sidebar and click the client.
2. Click **Edit**.
3. Tick **Tax exempt**.
4. Enter an **Exemption reason** and, if you have one, a **Certificate number**.
5. Click **Save**.

The reason and certificate number are stored on the client record and can be printed on invoices, so you can show why no tax was charged.

## What changes on new invoices?

When you create an invoice for a tax-exempt client:

- No tax rate is pre-selected on new lines, even if a rate is set as the default in **Settings → Taxes**.
- The invoice shows a "Tax exempt" label and, if entered, the certificate number.
- The tax summary at the bottom shows 0.00.

You can still pick a tax rate manually on a line if you need to. Ledgerly shows a small warning so you do not do it by accident.

## What about invoices I already created?

Existing invoices are not changed. Sent, Overdue and Paid invoices keep the tax they were issued with. Draft invoices keep their rates until you remove them manually and save. If you marked the client exempt by mistake or after the fact, void the invoice and create a corrected one rather than editing a sent invoice.

Recurring invoices created for the client from now on follow the exempt setting automatically when each run generates the new invoice.

## Can I store the certificate?

Yes. Open any invoice or the client record and add the certificate as an attachment. You can attach up to 5 files, 10 MB each. Attachments on the client record are kept for your records only and are not sent to the client.

## How do exempt sales appear in reports?

In the Tax summary report, sales to exempt clients are shown on a separate **Tax exempt** row with taxable sales and 0.00 tax collected. This gives you the total to report if your return asks for exempt sales separately. See Using the tax summary when you file.

| Item | Behaviour |
| --- | --- |
| New invoices | No tax by default |
| Sent invoices | Unchanged |
| Certificate number | Optional, printed on invoice |
| Reports | Separate Tax exempt row |

To turn the option off later, untick **Tax exempt** on the client record.
