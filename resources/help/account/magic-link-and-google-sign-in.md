---
title: Magic-link sign-in and Google sign-in
category: account
---

# Magic-link sign-in and Google sign-in

Besides email and password, you can sign in to Ledgerly with a one-time magic link or with your Google account. Both avoid typing a password.

## How does magic-link sign-in work?

A magic link is a single-use sign-in link sent to your account email.

1. On the sign-in page, enter your email address.
2. Select **Email me a sign-in link**.
3. Open the email and select **Sign in to Ledgerly**.
4. The link opens Ledgerly in your browser, signed in.

The link expires after 15 minutes and works only once. If you request a second link, the first stops working. Open the link in the same browser where you asked for it when possible, since some email apps open links in an internal browser that does not keep you signed in.

## How does Google sign-in work?

Select **Continue with Google** on the sign-in page and choose your Google account. Ledgerly receives your name and email address from Google. It does not see your Google password and does not access your Gmail, Drive or contacts.

Your Google account email must match your Ledgerly account email. If you first signed up with email and password, signing in with Google using the same address links the two methods to one account. If the emails differ, Google sign-in creates a new, separate account, so use the same address.

## Can I use more than one method?

Yes. Password, magic link and Google can all be active on one account. Under **Settings → Account → Security** you will see a **Sign-in methods** list:

| Method | Status |
| --- | --- |
| Password | Set or Not set |
| Magic link | Always available |
| Google | Connected or Not connected |

Select **Connect** next to Google to link it while signed in, or **Disconnect** to remove it. You cannot disconnect Google if it is your only way in; set a password first, since magic links always work for your account email.

## Does two-factor authentication still apply?

Yes. If you turned on two-factor authentication, Ledgerly asks for a code after a magic link or after Google sign-in as well. These methods replace the password step, not the second factor. See "Two-factor authentication".

## What if the magic link does not arrive?

- Check spam and promotions folders.
- Confirm that the address matches your account email.
- Wait two minutes before asking for another link.
- Make sure your mail provider is not blocking `mail.ledgerly.example`.

## What about my clients?

Clients use magic links too. When you share the client portal, they sign in through a link emailed to them, with no account or password. That is separate from your own sign-in, and you do not need to change anything.

Sessions expire after 30 days of inactivity whichever method you use.
