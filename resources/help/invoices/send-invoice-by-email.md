---
title: Sending an invoice by email
category: invoices
---

# Sending an invoice by email

Ledgerly emails your invoice to the client with the PDF attached and a secure payment link. Sending changes the status from Draft to Sent.

## How do I send an invoice?

1. Open **Invoices** and select the invoice.
2. Choose **Send**.
3. Check the recipients. They default to the billing contacts on the client record.
4. Edit the subject and message if you wish.
5. Choose **Send invoice**.

The invoice status becomes Sent, and a record of the send appears in the invoice's Activity log. You can also choose **Save and send** while creating an invoice.

## What does the client receive?

The client gets an email with your message, the invoice as a PDF, and a button that opens the secure payment page. They can pay by card or bank transfer (ACH, US only) without creating an account. A receipt is emailed automatically after each payment.

Emails come from `invoices@mail.ledgerly.example`. On the Business plan you can send from your own domain instead.

## Can I change the recipients or the message?

Recipients default to the billing contacts on the client record, up to 3 addresses. For a single send you can add or remove addresses without changing the client record. To change the contacts permanently, see Managing billing contacts for a client.

You can set a default email message under your branding settings, with placeholders for the client name and invoice number. Each send still lets you edit the text.

## How do I resend an invoice?

Open the invoice and choose **Send** again. The status stays as it is: an Overdue invoice remains Overdue. If the invoice is already past due, a reminder may be more appropriate. You can send one manual reminder per invoice every 24 hours; see Payment reminders.

## Why was my invoice email not delivered?

A "not delivered" entry in the Activity log usually means the client's mailbox rejected the message. To resolve it:

1. Confirm the address is spelled correctly on the client record.
2. Ask the client to check spam or junk folders and allow `invoices@mail.ledgerly.example`.
3. Try sending to another billing contact.
4. Share the payment link directly: open the invoice and choose **Copy payment link**.

If the PDF fails to attach, check for very large attachments. PDFs fail on very large files; the limit is up to 5 attached files of 10 MB each, and smaller is safer.

## Can I send an invoice without emailing it?

Yes. Download the PDF and deliver it yourself, or copy the payment link into your own message. If you do, the invoice stays a Draft until you mark it as sent, so reminders only begin when you do. See Downloading an invoice as a PDF.
