---
title: Payment reminders
category: billing
---

# Payment reminders

Ledgerly can email your clients automatically when an invoice is close to due or past due, and you can also send a reminder by hand at any time. Automatic reminders are available on the Pro and Business plans.

## Automatic reminder schedule

By default Ledgerly sends reminders on this schedule:

| When | Default |
|------|---------|
| 3 days before the due date (courtesy reminder) | Off |
| 3 days past due | On |
| 7 days past due | On |
| 14 days past due | On |

The courtesy reminder is off by default, so most accounts send three reminders in total. Reminders go to every billing contact on the client record and include the invoice PDF and the secure payment link. Free plan accounts do not have automatic reminders but can still follow up manually by email.

## How do I change when reminders are sent?

1. Go to **Settings → Billing → Reminders**.
2. Switch the courtesy reminder on if you want one before the due date.
3. Edit the number of days for each past-due reminder.
4. Save.

Changes apply to reminders that have not been sent yet. Reminders already delivered are not re-sent when you change the schedule.

## When do reminders stop?

Reminders stop automatically when any of these happens:

- The invoice is paid in full.
- The invoice is voided.
- The invoice is marked as disputed.
- A payment plan is active on the invoice.

If you clear the disputed flag, reminders resume with the next reminder that falls due. If a payment plan is cancelled after a missed installment, reminders resume under the normal rules. A partial payment does not stop reminders, because the invoice is still unpaid.

## How do I send a manual reminder?

1. Open **Invoices** and select the invoice.
2. Choose **Send reminder**.
3. Review the message and send.

You can send one manual reminder per invoice every 24 hours. Manual reminders are separate from the automatic schedule and do not change it. Each reminder appears in the invoice's Activity log, where you can also see whether the email was delivered.

## Why did my client not receive a reminder?

If a reminder seems to be missing, check these things first:

- The invoice is not disputed, voided, or covered by an active payment plan.
- Your plan is Pro or Business, since automatic reminders are not on Free.
- The Activity log on the invoice does not show a "not delivered" entry. That usually means the client's mailbox rejected the message, so confirm the email address on the client record.
- The reminder time has not simply not arrived yet. Reminders are counted from the due date in your account's time zone.

Reminders are sent from `invoices@mail.ledgerly.example` unless you have set up a custom sending domain on the Business plan. For general tips on following up with clients, see Collections best practices.
