---
title: Adding late fees to recurring invoices
category: recurring
---

# Adding late fees to recurring invoices

You do not need to add a late fee to each recurring invoice yourself. Recurring invoices inherit your account's late-fee setting automatically, and you can override it for any single schedule.

## How do I add a late fee to recurring invoices?

If late fees are turned on for your account, every invoice a schedule generates already follows that rule. To check or change the account setting, go to **Settings → Billing → Late fees**. The default fee is 1.5% of the outstanding balance per month, and you can adjust it between 0.5% and 10%.

Late fees and recurring invoices are both Pro and Business features. On the Free plan neither is available.

## How do I override the late fee for one schedule?

1. Open **Recurring** in the left sidebar.
2. Click the schedule you want to change.
3. Find the **Late fee** field.
4. Choose one of the three options and click **Save**.

| Option | Result |
|---|---|
| Use account default | The schedule follows Settings → Billing → Late fees |
| Custom percent | The schedule uses its own monthly percentage |
| Off | No late fee is ever applied to invoices from this schedule |

A custom percent must stay within the same 0.5% to 10% range as the account setting.

## Does a change affect invoices that already exist?

No. Overrides apply to future invoices only. Invoices a schedule has already generated keep the late-fee rule they were created with, even if you change the schedule or the account default afterward.

For example, suppose a monthly schedule generated INV-4471 on June 1. On June 10 you set the schedule's late fee to Off. INV-4471 still follows the old rule, but the invoice generated on July 1 and every one after it will have no late fee.

If you need to stop the fee on an invoice that was already generated, open that invoice and use **⋯ → Waive late fee**. Waiving is permanent for that invoice.

## How are late fees calculated on recurring invoices?

Once a recurring invoice has a late fee in effect, it behaves like any other invoice:

- The fee is a percentage of the outstanding balance per month, prorated by day.
- It is calculated on the invoice total excluding any previous late fees.
- It is applied automatically 30 days after the due date, then accrues daily until the invoice is paid.
- Total fees never exceed 25% of the invoice total.
- Marking the invoice as disputed, or having an active payment plan, pauses the fee.

Clients see the late fee as a separate line on the invoice and in the payment page total.

## Which setting wins when the two differ?

The schedule setting wins over the account setting, unless the schedule is set to **Use account default**. If you switch late fees off account-wide, schedules on Use account default stop adding fees, while schedules with a Custom percent keep their own value.
