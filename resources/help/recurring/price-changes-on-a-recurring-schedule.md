---
title: Changing the price on a recurring schedule
category: recurring
---

# Changing the price on a recurring schedule

To raise or lower the amount a client is billed every cycle, edit the line items on the recurring schedule. The new price applies to invoices created after you save, and never to invoices that already exist.

## How do I change the price?

1. Open **Recurring** in the left sidebar and click the schedule.
2. Click **Edit**.
3. In the line items, change the **Unit price** or **Quantity**. You can also add, remove or reorder lines (up to 50 per invoice).
4. Click **Save schedule**.

The schedule page shows the new total per invoice. The next run will use it.

## When does the new price take effect?

The change applies from the next run. If the schedule runs on the 1st and you edit it on the 20th, the invoice that goes out on the 1st of the following month carries the new price.

Invoices generated before your edit are not touched. A Sent invoice at the old price stays at the old price, and a Draft created earlier stays as it was. If a draft from the old run is still unsent and should have the new price, open it from the Invoices list and edit it there.

## How do I apply a price rise from a specific date?

Ledgerly applies edits immediately on save, so time the edit rather than the price. The simplest approach:

1. Wait until after the last run that should use the old price.
2. Edit the line items before the next run date.

Alternatively, end the existing schedule with an end date, then create a new schedule for the same client with the new price and a start date matching the changeover. This keeps a clear record of what was billed at each rate.

## What about discounts and one-time charges?

- **Discounts:** a percent or fixed amount per invoice can be set on the schedule and edited the same way. It applies to every future invoice until you remove it.
- **One-time extras:** do not add them to the schedule, or they will repeat every cycle. Instead use Auto-send off, so each run creates a draft, and add the extra to that draft only.

## Will my client be told about the change?

Ledgerly does not send a separate price-change notice. The client sees the new amount on the next invoice. For a retainer, it is good practice to tell the client in advance. You can add a short message in the schedule's **Notes to client** field, which is printed on every invoice it generates, then remove it once the new price is established.

## Does a price change affect late fees or taxes?

Late fees follow the schedule's late fee setting and are calculated on the invoice's own total. Tax rates stay attached to each line item, so a new unit price is taxed at the line's rate. See Recurring invoices and taxes.
