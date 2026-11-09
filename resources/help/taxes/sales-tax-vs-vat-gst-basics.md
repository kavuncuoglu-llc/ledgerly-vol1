---
title: Sales tax vs VAT and GST basics
category: taxes
---

# Sales tax vs VAT and GST basics

Sales tax, VAT and GST all add a percentage to a sale, but they work differently. Ledgerly can handle all three on an invoice, but it does not file taxes and does not give tax advice, so always confirm your obligations with your tax authority or accountant.

## What is sales tax?

Sales tax, used in the United States, is charged only on the final sale to the end customer. Rates vary by state and sometimes by city or county, so one sale can involve several taxes. Prices are normally quoted without tax and the tax is added at checkout, which matches an **exclusive** tax rate in Ledgerly.

## What are VAT and GST?

VAT (value-added tax) and GST (goods and services tax) are used in the EU, the UK, Canada, Australia and many other countries. They are charged at each step of the supply chain. A business charges the tax to its customer, then reclaims the tax it paid on its own purchases. Prices are often quoted tax-included, which matches an **inclusive** rate in Ledgerly.

Because businesses reclaim the tax, invoices to business clients usually need your tax ID and the client's tax ID. See Showing tax IDs on invoices.

## How do they map to Ledgerly settings?

| Concept | Typical Ledgerly setup |
| --- | --- |
| US state and local sales tax | One exclusive rate per tax, several rates on a line |
| VAT or GST on tax-included prices | Inclusive rate |
| VAT or GST added on top | Exclusive rate |
| Zero-rated or reverse-charge sale | 0% rate plus footer text |
| Client who does not pay tax | Tax-exempt option on the client |

Rates are created in **Settings → Taxes**, and each line item can carry up to 5 of them.

## Does Ledgerly work out the right rate for me?

No. Ledgerly applies only the rates you create. It does not look up addresses, detect which rate applies, or check whether you are required to register for tax. If you sell in several regions, you must set up each rate yourself and select the right one on each invoice.

## What should I do when I am not sure?

- Ask your accountant which taxes apply to your sales and what your invoices must show.
- Keep your rate names descriptive, such as "VAT 20% (UK)".
- Run the Tax summary report each period to see what you collected.
- Use the Payments received report if your filing rules are based on cash received rather than invoice date.

Ledgerly keeps accurate records of what you charged. What you charge, and what you pay to the authority, remains your responsibility.

## Where can I learn more?

The related articles are Setting up tax rates, Inclusive vs exclusive tax and Zero-rated and reverse-charge notes. Your national tax authority is the authority on rates and rules.
