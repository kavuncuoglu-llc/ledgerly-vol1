---
title: Tax IDs for clients
category: clients
---

# Tax IDs for clients

You can store a tax ID on each client, such as a VAT, GST or EIN number. Ledgerly prints it on the client's invoices. Ledgerly does not file taxes or give tax advice.

## How do I add a tax ID?

1. Open **Clients** and click the client.
2. Click **Edit**.
3. Enter the number in the **Tax ID** field.
4. Click **Save client**.

The field accepts letters and numbers. You can fill it in when you first create the client, or add it later.

## Where does the tax ID appear?

The client's tax ID is printed on invoices for that client, in the "Bill to" block, and on their statements. Your own business tax ID is entered separately in your account settings and appears in the sender block.

Invoices that were already sent keep the details they had when sent. If you add or correct a tax ID later, only new invoices, and drafts you open again, show the update. To fix an invoice that was already sent, void it and issue a corrected one, or ask the client whether the old one is acceptable.

## Does Ledgerly check the number?

No. Ledgerly saves the text exactly as you type it and does not check it against a tax authority. Make sure you copy the number correctly from your client's paperwork.

## How do I handle reverse charge and zero-rated sales?

For cross-border sales where the client accounts for the tax, you normally need both the client's tax ID and a note on the invoice. Ledgerly supports this through the invoice footer text:

1. Make sure the client's **Tax ID** is saved.
2. In the invoice, add wording such as "Reverse charge applies" to the **Footer** field, or put it in your default footer in **Settings → Invoices**.
3. Set the line items' tax to a zero rate that you created in **Settings → Taxes**.

Confirm the exact wording and rules with your tax adviser. Rules differ from country to country.

## How do tax IDs work with recurring invoices?

Each generated invoice takes the tax ID from the client record at the time of the run, so updating it applies to future invoices only. See Recurring invoices and taxes.

## Can I import tax IDs?

Yes. Include a column for tax ID in your CSV and map it to the **Tax ID** field during the import. See Import clients from CSV.

**Can a client have more than one tax ID?**

Each client has a single tax ID field. If a client has two numbers, such as a VAT number and a local registration number, put the main one in the field and the other in the invoice footer text or the client's notes.

**Can I see which clients have no tax ID?**

Add the **Tax ID** column on the Clients list through **Columns**, and sort by it. Blank values appear first.
