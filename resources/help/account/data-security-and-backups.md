---
title: Data security and backups
category: account
---

# Data security and backups

Ledgerly encrypts your data in transit and at rest, takes daily backups that are kept for 35 days, and never stores full card numbers. This article summarizes the protections in place and what you can do to add more.

## How is my data encrypted?

- **In transit**: all traffic between your browser, the API and Ledgerly's servers uses HTTPS. Emails with payment links also point to HTTPS pages.
- **At rest**: databases, uploaded logos, attachments and generated PDFs are stored encrypted.

Passwords are never stored as plain text. They are protected with a one-way hash, so even our staff cannot read them.

## Does Ledgerly store card numbers?

No. When a client pays by card, they enter details on a payment page handled by our payment partner. The card number goes directly to that partner. Ledgerly keeps only the information needed to show the payment, such as the last four digits, the payment amount and date. The same applies to the card you use to pay for your plan.

## How do backups work?

Ledgerly takes a backup of the whole system every day. Backups are kept for 35 days and then removed. They are encrypted like the live data.

Backups exist for disaster recovery, such as a hardware failure or a serious software fault. They are not an undo button for individual accounts. If you delete a client or void an invoice by mistake, use the app's own features first:

| Situation | What to do |
| --- | --- |
| Archived a client by mistake | Restore the client from the Clients list |
| Voided an invoice by mistake | Create a new invoice; voided numbers are not reused |
| Closed the account | Reopen within 30 days |
| Want your own copy | Use **Settings → Account → Export my data** |

For your own peace of mind, take an export regularly. See "Export my data".

## How can I protect my account?

1. Use a strong, unique password of at least 10 characters.
2. Turn on two-factor authentication and save your backup codes.
3. Sign out on shared computers. Sessions end after 30 days of inactivity.
4. Give team members the lowest role they need.
5. Revoke API keys you no longer use.

## What does Ledgerly do not do?

We do not sell your data or your clients' data. We do not give tax advice or file taxes for you. Support will never ask for your password, full card number or backup codes.

## How do I report a security concern?

If you notice suspicious activity, change your password, sign out of all devices and write to support@ledgerly.example with the details. Mark the subject "Security" so it is triaged first.
