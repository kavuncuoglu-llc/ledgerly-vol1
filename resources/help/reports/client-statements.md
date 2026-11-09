---
title: Client statements
category: reports
---

# Client statements

A client statement is a PDF summary of everything one client has been invoiced and has paid over a period, ending in the balance they owe. Statements are available on all plans, including Free.

## What does a statement contain?

Each statement lists, in date order:

- The **opening balance** at the start of the period.
- Every invoice issued in the period, with its number, date and amount.
- Every payment received, with its date and method.
- Any late fees charged.
- The **closing balance**, which is what the client owes at the end.

Your logo, business details and tax ID appear at the top. On the Free plan, the PDF carries Ledgerly branding.

## How do I create a statement?

1. Open **Reports → Client statements**.
2. Choose a client from the **Client** drop-down.
3. Pick a date range: **This month**, **Last month**, **Quarter**, **Year** or **Custom**.
4. Click **Generate**.
5. Review the preview, then click **Download PDF**.

You can also start from the client's own page. Open **Clients**, click the client, and choose **Statement**.

## How do I send a statement to a client?

After generating the statement, click **Email statement**. The email goes to the client's billing contacts (up to 3 email addresses can be stored per client) and includes the PDF and a link to the client portal. You can edit the subject and message before sending. Statements are sent from `invoices@mail.ledgerly.example`, unless your Business account uses a custom sending domain.

Clients can also see their invoices and pay them through the portal, which needs no password. They sign in with a magic-link email.

## Which invoices are on the statement?

Draft invoices are not included, since the client has not received them. Void invoices are left off the statement, though the invoice numbers are never reused. Invoices in the client's currency appear at their face value. A client has one currency, so a statement never mixes currencies.

Partial payments reduce the balance and show as separate payment lines, so the client can follow how an invoice went down.

## What is the difference between a statement and the Aging report?

A statement is for a single client and is meant to be sent to them. The Aging report covers all clients and is meant for you. Use Aging to decide who to chase, then send a statement to the clients who are behind. See Aging report.

| Plan | Statements | CSV export |
| --- | --- | --- |
| Free | PDF | No |
| Pro | PDF | Yes |
| Business | PDF | Yes |
