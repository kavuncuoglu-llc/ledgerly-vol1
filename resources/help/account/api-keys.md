---
title: API keys
category: account
---

# API keys

API keys let your own software read and write Ledgerly data through the REST API. API access is part of the Business plan, and you manage keys under **Settings → Developers**.

## What is an API key?

An API key is a secret string that identifies your account when your code calls the Ledgerly REST API. It works like a password for software, so anyone holding it can act on your account's data. Keys are tied to the account, not to a single person's sign-in, so changing your password does not affect them.

## How do I create a key?

1. Open **Settings → Developers**.
2. Select **Create API key**.
3. Give the key a name that says where it is used, such as "Bookkeeping sync".
4. Choose the access level: **Read only** or **Read and write**.
5. Select **Create**.

Ledgerly shows the full key once. Copy it into a password manager or your secrets store before you close the dialog. Afterwards only the last four characters are displayed, and a lost key cannot be recovered, only replaced.

## How do I send requests?

Include the key as a bearer token in the `Authorization` header of each request. Requests must use HTTPS. The API is limited to 60 requests per minute per account. When you go over, it answers with HTTP 429 and you should wait for the next minute before retrying. Spread bulk work out, and fetch lists rather than one record at a time.

## How do I rotate or revoke a key?

On **Settings → Developers**, open the **⋯** menu beside a key:

- **Revoke** disables the key at once. Any software using it starts getting 401 errors.
- **Rename** changes only the label.

To rotate a key safely, create the new one, update your software, check it works, and then revoke the old one. A list shows when each key was last used, which helps you spot keys that nothing uses any more.

## Who can manage keys?

The Owner and Admins can create, view and revoke keys. Members cannot see the Developers page. When you remove a team member, revoke any keys they created for their tools.

## What are good habits?

- Use one key per integration, so a leak affects only one.
- Choose **Read only** when the software only needs to look at data.
- Never place a key in public code, a browser app or a support email.
- Revoke keys you no longer use.

If you downgrade from Business, existing keys stop working at the end of the billing period and come back when you upgrade again. For no-code connections, see "Connect QuickBooks, Xero and Zapier".
