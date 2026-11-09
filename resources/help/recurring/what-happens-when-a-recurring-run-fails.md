---
title: What happens when a recurring run fails
category: recurring
---

# What happens when a recurring run fails

If a recurring schedule cannot create or send an invoice on its run date, Ledgerly retries, flags the run as Failed, and tells the account owner. Later runs are not cancelled, and a failure never creates a duplicate invoice.

## Why might a run fail?

The most common causes are:

- **The client is archived.** Schedules do not generate invoices for archived clients.
- **No billing email** on the client while Auto-send is on.
- **Invoice total below the minimum.** If the total is less than your **Minimum invoice amount** (Settings → Invoices, default $1.00), the invoice cannot be saved.
- **Plan change.** If the account has dropped to the Free plan, schedules pause and do not run.
- **A deleted tax rate** that a line item still points to.
- **A temporary delivery problem** when sending the email.

## What does Ledgerly do first?

Ledgerly retries the run automatically 3 times, one hour apart. Many temporary problems clear on their own, and a retry that succeeds produces one normal invoice with the next number in your sequence.

If the invoice was created but the email could not be sent, the invoice is kept as a Draft rather than being created again, so no duplicate is made.

## How will I know a run failed?

After the third failed retry:

1. The run is marked **Failed** in the schedule's history.
2. The schedule shows a red **Needs attention** badge in the **Recurring** list.
3. The account owner receives an email naming the schedule, the client and the reason.

Open the schedule and look at the **Run history** tab. Each run shows its date, result (Created, Sent, Draft or Failed) and, for failures, the reason in plain language. Email delivery problems also appear in the Activity log.

## How do I fix it and get the invoice?

1. Correct the cause: unarchive the client, add a billing email, fix the line items, or upgrade the plan.
2. Open the failed run in Run history and click **Retry now**.
3. Ledgerly creates the invoice using the schedule's current settings and the run date shown, then sends it or saves it as a draft according to the Auto-send setting.

If you would rather not retry, you can create a regular invoice by hand.

## Is the next run affected?

No. The schedule stays Active and the next run happens on its normal date, even if the earlier run is still failed. If you fix the problem after the next date has passed, the missed invoice is not created automatically, so use **Retry now** or bill manually. Failed runs do not count toward a schedule's number of occurrences until they succeed.
