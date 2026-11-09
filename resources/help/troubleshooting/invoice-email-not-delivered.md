---
title: Invoice email not delivered
category: troubleshooting
---

# Invoice email not delivered

If an invoice email shows "Not delivered", the client's mailbox almost always rejected it. You can see the reason in the Activity log and usually fix it in a few minutes.

## How do I see why it was not delivered?

1. Open the invoice from **Invoices**.
2. Scroll to **Activity** at the bottom, or open the Activity log from the Dashboard.
3. Find the entry marked **Not delivered** next to the send event.
4. Click it to see the reason reported by the client's mail server.

Common reasons include "mailbox full", "address does not exist", "message rejected as spam" and "domain not found".

## What are the common causes and fixes?

| Reason shown | What to do |
| --- | --- |
| Address does not exist | Check the spelling of the client email on the client record |
| Mailbox full | Ask the client to free space, then resend |
| Rejected as spam | Ask the client to allow `invoices@mail.ledgerly.example`, then resend |
| Domain not found | The part after the @ is wrong; correct it |
| Temporary failure | Wait and resend; Ledgerly retries for a few hours |

Typos are the most common cause. Compare the address letter by letter with what the client gave you.

## How do I resend the invoice?

1. Open **Clients**, click the client, and correct the email address if needed. You can store up to 3 billing contacts per client.
2. Open the invoice.
3. Click **Send** (or **Resend**).
4. Confirm the recipients and click **Send invoice**.

Resending does not create a new invoice or change its number. The status stays Sent. If the invoice was a Draft, it moves to Sent only after the email goes out.

## What if the email says delivered but the client can't find it?

Ask the client to check their spam or junk folder and any filtered tabs. Emails come from `invoices@mail.ledgerly.example`, unless you are on Business with a custom sending domain. Ask them to add that address to their contacts.

If the client still cannot find it, copy the secure payment link from the invoice (**⋯ → Copy payment link**) and send it by another route, such as chat. They can view and pay the invoice without a password. You can also download the PDF and attach it to your own email.

## Can I prevent this next time?

Yes. Use a real, working address for each client and add a second billing contact. On Business, set up a custom sending domain so emails come from your own domain, which mailboxes tend to trust more. Reminder emails use the same address list, so fixing the address fixes those too.

## Still not working?

If the Activity log shows "Delivered" but the client has not got the email after a day, or the log shows no reason at all, contact support at support@ledgerly.example with the invoice number, for example INV-4471. See Contacting support.
