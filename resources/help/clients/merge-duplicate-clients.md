---
title: Merge duplicate clients
category: clients
---

# Merge duplicate clients

If the same client was added twice, you can merge the two records into one. All invoices and payments move to the record you keep, and the other record is removed.

## When should I merge?

Typical situations:

- A client was created twice, with slightly different spellings
- A CSV import brought in a client that already existed
- A client changed company name and you created a second record

Merge duplicates rather than archiving one, so that statements and reports show the complete history in one place.

## How do I merge two clients?

1. Open **Clients**.
2. Tick the checkboxes next to the two duplicates.
3. Click **Merge**.
4. Choose which record to **Keep**. The other will be merged into it.
5. Review the combined details and click **Merge clients**.

Ledgerly may also show **Possible duplicates** at the top of the Clients list when two records share the same email address. Click **Review** to start a merge from there.

## What happens to the data?

| Data | Result |
|---|---|
| Invoices, payments and statements | All move to the kept client |
| Recurring schedules | Move to the kept client and keep running |
| Billing emails | Combined, up to 3. Extra addresses are dropped, oldest last |
| Name, company, address, phone, tax ID | Taken from the kept client. Empty fields are filled from the other record |
| Currency and payment terms | Taken from the kept client |
| Notes | Combined, with the merged record's notes added below |

Invoice numbers do not change.

## Can I merge clients with different currencies?

No. Merging is blocked if the two clients use different currencies, because their invoices cannot be added together. Create a new invoice in the right currency for the client, or keep them as separate records. Change the currency of one client first only if you are sure it is correct, remembering that existing invoices keep their original currency.

## Can I undo a merge?

No. A merge is permanent, so check the preview carefully. If you merged by mistake, you can create a new client record and manually create invoices for it from now on, but already merged invoices stay with the kept client. Exporting your data first under **Settings → Account → Export my data** gives you a backup.

## What happens to the client portal and payment links?

Existing payment links keep working, because the invoices are unchanged. The merged client sees all invoices in the portal under the combined billing contacts. See Client portal.

**Who can merge clients?**

Free and Pro accounts have a single user, who can do it. On the Business plan, Owners and Admins can merge clients. Members cannot.
