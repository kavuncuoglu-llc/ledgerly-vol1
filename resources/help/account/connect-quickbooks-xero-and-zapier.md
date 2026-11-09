---
title: Connect QuickBooks, Xero and Zapier
category: account
---

# Connect QuickBooks, Xero and Zapier

Ledgerly connects to QuickBooks Online and Xero for accounting, and to Zapier for automation. QuickBooks Online and Xero need the Business plan. Zapier works on Pro and Business.

## Which plan do I need?

| Integration | Plans |
| --- | --- |
| QuickBooks Online | Business |
| Xero | Business |
| Zapier | Pro and Business |
| Google Calendar reminders feed | Pro and Business |

Only the Owner and Admins can connect or disconnect integrations. You find them all under **Settings → Integrations**. Only one accounting integration can be active at a time, so choose either QuickBooks Online or Xero.

## How do I connect QuickBooks Online or Xero?

1. Open **Settings → Integrations**.
2. Select **Connect** on the QuickBooks Online or Xero card.
3. Sign in to your accounting software and choose the company file or organization.
4. Approve access.
5. Back in Ledgerly, set the sync options and select **Save**.

You choose which sales account new invoices post to and which bank account receives payments. If you do not choose, Ledgerly uses the defaults in your accounting software.

## What gets synced?

- **Clients** become customers or contacts in your accounting software.
- **Invoices** are created there when they are sent from Ledgerly. Line items, tax rates, discounts and totals carry over.
- **Payments** recorded in Ledgerly are added to the matching invoice.

Syncing runs one way, from Ledgerly to your accounting software, and normally finishes within a few minutes. Edits you make to a synced invoice in the accounting tool are not copied back. Void in Ledgerly to cancel the matching invoice there. Sync errors appear on the Integrations page with a **Retry** button. Tax rates need matching names in both places, otherwise the sync reports a tax mismatch.

## How do I use Zapier?

1. Open **Settings → Integrations** and select **Connect** on the Zapier card.
2. Copy the connection code shown.
3. In Zapier, add Ledgerly to a Zap and paste the code.
4. Choose a trigger or an action.

Typical triggers are Invoice paid, Invoice overdue and New client. Actions include Create client and Create draft invoice. Zapier tasks count against your Zapier plan, not Ledgerly's.

## How do I disconnect an integration?

Select **Disconnect** on its card. Data already sent stays in the other system, and Ledgerly stops sending new data. You can reconnect later, and Ledgerly offers a one-time catch-up of items created while disconnected.

If you downgrade from Business, QuickBooks and Xero pause at the end of the billing period and settings are kept. For custom software, see "API keys".
