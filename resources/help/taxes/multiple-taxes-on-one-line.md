---
title: Multiple taxes on one line
category: taxes
---

# Multiple taxes on one line

You can apply up to 5 tax rates to a single invoice line. This is useful where goods or services are charged more than one tax, such as a state tax plus a city tax.

## When would I need more than one tax?

Some places charge several taxes on the same sale. Examples include a state sales tax and a local sales tax, or a federal tax and a provincial tax. Instead of creating one combined rate, you can keep each tax as its own rate so the invoice and the Tax summary report show each one separately.

Ledgerly does not tell you which taxes apply to your sales. Confirm with your tax authority or accountant.

## How do I add several taxes to a line?

1. Create each tax in **Settings → Taxes**, for example "State tax 6%" and "City tax 2%".
2. Open the invoice and find the line item.
3. Click the **Tax** field on that line.
4. Select the first rate, then select the next. Selected rates appear as small tags in the field.
5. Repeat until all rates are chosen, up to a maximum of 5.
6. Save the invoice.

To remove one, click the x on its tag.

## How are the amounts calculated?

Each rate is calculated on the same line amount (quantity × unit price, after any discount). Rates do not compound, meaning the second tax is not charged on top of the first.

For example, a line of 200.00 with "State tax 6%" and "City tax 2%" (both exclusive) works out as:

| Tax | Calculation | Amount |
| --- | --- | --- |
| State tax 6% | 200.00 × 6% | 12.00 |
| City tax 2% | 200.00 × 2% | 4.00 |
| Total tax | | 16.00 |

The line's total is 216.00. If your local rules require one tax to be charged on top of another, create a single rate with the effective combined percentage and name it clearly.

## What if some rates are inclusive and some are exclusive?

You can mix them on one line. Inclusive rates are extracted from the entered price, and exclusive rates are added on top. Because mixing can be confusing, check the invoice preview before sending. See Inclusive vs exclusive tax for the formulas.

## How does it appear to the client?

The invoice lists each tax by name in the tax summary beneath the line items, with one row per rate and the combined amount across all lines using that rate. The Tax summary report groups collected tax by the same rate names, so naming rates clearly in **Settings → Taxes** makes both easier to read.

If you need to apply more than 5 taxes, combine the smallest ones into a single named rate.
