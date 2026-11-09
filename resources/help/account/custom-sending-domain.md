---
title: Custom sending domain
category: account
---

# Custom sending domain

A custom sending domain makes invoice emails come from your own address, such as invoices@yourbusiness.com, instead of Ledgerly's. It is available on the Business plan and set up under **Settings → Branding → Sending domain**.

## Why use a custom sending domain?

By default, emails go out from `invoices@mail.ledgerly.example` with your business name as the display name. Clients sometimes hesitate to open mail from an unfamiliar domain, and a message from your own domain looks more trustworthy. It can also improve delivery, since your domain builds its own sending reputation.

## How do I set it up?

1. Open **Settings → Branding → Sending domain**.
2. Enter the domain you own, for example `yourbusiness.com`, and the sender name you want clients to see.
3. Choose the address, for example `billing`, which gives billing@yourbusiness.com.
4. Select **Generate DNS records**.
5. Add the records to your domain at your DNS provider.
6. Return to Ledgerly and select **Verify domain**.

## Which DNS records will I add?

Ledgerly shows three or four records to copy exactly:

| Record | Purpose |
| --- | --- |
| SPF (TXT) | Authorizes Ledgerly to send for your domain |
| DKIM (two CNAME records) | Signs each email so it cannot be forged |
| DMARC (TXT) | Tells receiving mailboxes how to treat unsigned mail |

Some DNS providers add your domain to the end of the record name automatically. If verification fails, check that the name has not been doubled, such as `selector._domainkey.yourbusiness.com.yourbusiness.com`.

## How long does verification take?

Often a few minutes, but DNS changes can take up to 48 hours. The status shows **Pending**, **Verified** or **Failed**. Ledgerly rechecks every hour while pending, and you can force a check with **Verify domain**. Until the domain is verified, emails continue to use the default address, so nothing breaks while you wait.

## What changes once it is verified?

All invoice emails, reminders and receipts come from your address. Replies still go to your business email set in **Settings → Business**. Messages already sent keep their original sender.

Add a plain mailbox on your domain if you want clients to be able to reply directly to the sending address, because Ledgerly does not host mailboxes.

## Can I change or remove the domain?

Yes. Select **Remove domain** to return to `invoices@mail.ledgerly.example`, or change the sender name or address at any time. Changing to a different domain requires fresh verification.

If you downgrade from Business, sending reverts to the default address at the end of the billing period. If a client reports missing emails, check the invoice's **Activity log**.
