---
title: Setting up tax rates
category: taxes
---

# Setting up tax rates

Tax rates are created once in Settings → Taxes and then picked from a list on each invoice line. Set them up before you start invoicing so you do not have to type percentages by hand.

## What does a tax rate contain?

Every tax rate has three parts:

- **Name**: what your client sees on the invoice, for example "VAT 20%" or "State sales tax".
- **Rate**: a percentage between 0 and 100, with up to three decimal places (for example 8.875).
- **Type**: inclusive or exclusive. Exclusive means the tax is added on top of the line price. Inclusive means the tax is already inside the price. See Inclusive vs exclusive tax for the difference.

Ledgerly does not file taxes for you and does not give tax advice. Check the correct rates with your tax authority or accountant.

## How do I add a tax rate?

1. Open **Settings → Taxes**.
2. Click **Add tax rate**.
3. Enter a name, for example "GST 10%".
4. Enter the rate as a number, without the percent sign.
5. Choose **Exclusive** or **Inclusive**.
6. Optionally tick **Apply to new line items by default**.
7. Click **Save**.

The new rate is available immediately on every draft and new invoice. You can create as many saved rates as you need.

## How do I use a tax rate on an invoice?

Each line item has an optional **Tax** field. Open the invoice, click the Tax field on a line, and pick one or more rates from the list. Lines with no rate selected are not taxed. A single line can carry up to 5 tax rates, which is covered in Multiple taxes on one line.

If you ticked the default option, the rate is pre-selected on every new line. You can clear it on any line where it does not apply.

## Can I edit or remove a tax rate later?

You can rename a rate or change its percentage at any time from **Settings → Taxes**. Be careful when changing the percentage, because it affects invoices you edit afterwards. Changing a rate during the year is explained in Changing a tax rate mid-year, and the safest approach is usually to add a new rate instead of editing the old one.

To remove a rate, click the ⋯ menu beside it and choose **Archive**. Archived rates disappear from the picker but stay on every invoice that already used them, so your history and your Tax summary report remain correct. Rates cannot be deleted once an invoice has used them.

## Quick reference

| Setting | Where | Limit |
| --- | --- | --- |
| Tax rates list | Settings → Taxes | No fixed cap on saved rates |
| Rates per line item | Invoice line → Tax | 5 |
| Decimal places in rate | Settings → Taxes | 3 |
