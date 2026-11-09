---
title: Payment receipts
category: payments
---

# Payment receipts

Ledgerly emails a receipt to your client automatically on every online payment. You can also resend a receipt at any time and send one for payments you record by hand.

## When is a receipt sent?

A receipt is sent as soon as an online payment succeeds, whether it is a card payment or an ACH bank transfer. For ACH, the receipt is sent when the payment clears, not when the client submits it, because the payment shows as pending for up to 2 business days. See Pending payments explained.

If the client makes a partial payment, they receive a receipt for that amount, showing the remaining balance. Each later payment gets its own receipt.

## What does a receipt contain?

- Your business name and logo
- The client's name
- The invoice number, such as INV-4471
- The payment date and amount
- The payment method (for cards, the card brand and last four digits)
- The remaining balance, or confirmation that the invoice is paid

Receipts come from `invoices@mail.ledgerly.example` unless you use a custom sending domain on the Business plan. Card numbers are never stored by Ledgerly and are never shown in full.

## Who receives the receipt?

The receipt goes to the email addresses on the client record. A client can have up to 3 billing contacts, and all of them receive it. See Multiple billing contacts for a client.

## How do I resend a receipt?

1. Open **Payments** in the sidebar.
2. Click the payment.
3. Click **Resend receipt**.

You can resend a receipt as often as needed, for example if the client lost the email. Check the **Activity log** on the invoice if a client says they never got one. A "not delivered" entry usually means their mailbox rejected the message, in which case check the email address on the client record.

## Do offline payments get receipts?

Only if you choose. When you use **Record payment**, tick **Send receipt to client**. It is not sent by default. If you forgot, open the payment in the invoice's payment history and click **Resend receipt**.

## Can I customise the receipt?

Receipts use your logo and accent color from your branding settings (custom logo and colors are on Pro and Business). The wording of the email cannot be changed, but your custom email message template applies to invoice emails, not receipts.

**Can a client download a receipt later?**

Yes. Clients can open their payment page or the client portal and see each paid invoice with its payment details. See Client portal for how clients get access.
