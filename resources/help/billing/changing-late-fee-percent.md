---
title: Changing the late-fee percentage
category: billing
---

# Changing the late-fee percentage

The default late fee is 1.5% of the outstanding balance per month. You can set any rate from 0.5% to 10% for your account, and override it for individual recurring schedules.

## What rates can I choose?

The account-wide late-fee rate is a monthly percentage between 0.5% and 10%. Rates outside that range are rejected when you save. The fee is always prorated by day and calculated on the invoice total excluding any previous late fees.

Whatever rate you pick, total late fees on one invoice are capped at 25% of the invoice total. Late fees are available on the Pro and Business plans only.

## How do I change the account-wide rate?

1. Go to **Settings → Billing → Late fees**.
2. Make sure **Charge late fees** is on.
3. Enter a new monthly percent, for example 2%.
4. Save.

The change takes effect straight away for fees accrued from that day forward. Fees already charged are not recalculated.

## Which invoices does a new rate apply to?

| Invoice situation | Effect of the change |
|-------------------|----------------------|
| Not yet overdue | New rate is used if a fee later applies |
| Overdue, fee not yet started | New rate is used when the fee starts |
| Fee already accruing | New rate applies to days after the change; earlier days keep the old rate |
| Late fee waived | No fees, regardless of rate |
| Disputed or on a payment plan | Paused; the new rate applies when fees resume |

For example, on an outstanding balance of $1,000.00, 1.5% is $15.00 a month and 2% is $20.00 a month. If you change the rate partway through a month, each day is charged at the rate in force on that day.

## How do I use a different rate for a recurring schedule?

Recurring invoices inherit the account's late-fee setting automatically. To use a different rate for one schedule:

1. Go to **Recurring** and open the schedule.
2. Find **Late fee**.
3. Choose **Use account default**, **Custom percent**, or **Off**.
4. Save.

Overrides apply to future invoices only, not to invoices the schedule has already generated. This is handy for a retainer client you want to charge a lower rate, or to switch fees off for one friendly customer without affecting anyone else.

## What should I tell clients when I change the rate?

Clients see the late fee as a separate line on each invoice and on the payment page, but they are not notified when you change your settings. If you raise your rate, tell clients ahead of time or add a line to your invoice footer, for example "Late fees of 2% per month apply after 30 days." Footer text is set under **Settings → Invoices**.

For how fees start and stop, see Understanding late fees. To remove a fee from a single invoice, see Disputing a charge.
