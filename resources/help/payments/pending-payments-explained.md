---
title: Pending payments explained
category: payments
---

# Pending payments explained

A payment shows as **pending** while it has been started but the money has not yet cleared. For ACH bank transfers this can last up to 2 business days. Card payments normally do not stay pending.

## What does pending mean?

Pending means the client has authorised the payment, but the bank has not confirmed the funds. Ledgerly lists the payment under **Payments**, with the status Pending, and the invoice has not yet been marked Paid.

## How long does a payment stay pending?

| Method | Time in Pending |
|---|---|
| Card | Usually none. The invoice updates within a minute |
| Bank transfer (ACH) | Up to 2 business days |
| Offline payment you record | Never pending. It is recorded as received |

Business days exclude weekends and bank holidays. An ACH payment submitted on a Friday evening can stay pending until Tuesday.

## What happens when it clears?

When the transfer clears:

1. The payment status changes to Succeeded.
2. The invoice balance is reduced, and the invoice becomes Paid if nothing remains.
3. A receipt is emailed to the client.
4. The money joins your next payout. See Payout schedule.

You also get an email when the payment succeeds.

## Will the client still get reminders?

Reminders can still go out until the payment clears, because the invoice has not yet been marked Paid. If a client has told you they paid by ACH, you can avoid confusion by checking the **Payments** list first. A reminder stops automatically once the invoice is Paid. You can also mark the invoice as disputed to pause reminders, but only do that for a genuine dispute.

## What if a pending payment fails?

Sometimes the bank rejects an ACH transfer, for example for insufficient funds or a closed account. Then:

- The payment status changes to **Failed**.
- The invoice stays Sent or Overdue, with the full balance outstanding.
- You and the client are notified by email.
- The client can try again from the same payment link, using another method if they prefer.

A failed ACH payment costs nothing. See Bank transfer (ACH) payments.

## What if it has been pending for more than 2 business days?

First check that your payout account is verified in **Settings → Payments** and that the delay is not caused by a bank holiday. If the payment is still pending after 3 business days, email support@ledgerly.example with the invoice number.

**Why is my invoice unpaid but there is no pending payment?**

If the client says they paid, but nothing appears in Payments, the money was probably sent outside Ledgerly, for example a transfer to your own bank account. Record it with **Record payment**. See Record an offline payment.
