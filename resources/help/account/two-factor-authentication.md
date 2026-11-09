---
title: Two-factor authentication
category: account
---

# Two-factor authentication

Two-factor authentication (2FA) adds a second step when you sign in: a six-digit code in addition to your password. It is optional, but we recommend it for every account because Ledgerly holds your clients' details and payment information.

## What 2FA methods can I use?

| Method | How it works |
| --- | --- |
| Authenticator app | An app such as Google Authenticator, 1Password or Authy generates a new code every 30 seconds |
| SMS | A six-digit code is texted to your phone. It is valid for 10 minutes |

An authenticator app is the stronger choice because it works offline and is not exposed to SIM-swapping. SMS is easier to set up. You can switch between them at any time.

## How do I turn on 2FA with an authenticator app?

1. Open **Settings → Account → Security**.
2. Under **Two-factor authentication**, select **Turn on**.
3. Choose **Authenticator app**.
4. Scan the QR code with your app. If you cannot scan, select **Enter key manually**.
5. Type the six-digit code your app shows and select **Verify**.
6. Save your backup codes when Ledgerly shows them.

2FA is not active until you complete the verification step, so closing the page early leaves your account unchanged.

## How do I use SMS instead?

Choose **Text message** in step 3, enter your mobile number with country code, and select **Send code**. Enter the code you receive to finish. Standard message rates from your carrier may apply. If texts do not arrive, check the number and signal, then request a new code after 60 seconds.

## What happens when I sign in?

After your password (or magic link, or Google), Ledgerly asks for a code. Tick **Trust this device for 30 days** to skip the prompt on a device you own. The trusted status ends if you sign out everywhere, change your password, or turn 2FA off.

## What if I lose my phone?

Use one of your 10 single-use backup codes in place of the six-digit code. See "Backup codes" for how they work. Without a backup code, contact support@ledgerly.example from your account email. We will verify your identity before removing 2FA, which can take a business day or more.

## How do I turn 2FA off or change the phone?

Open **Settings → Account → Security** and select **Manage** next to Two-factor authentication. You can switch methods, update your phone number or select **Turn off**. You will be asked for your password and a current code first.

On the Business plan, an Owner or Admin cannot turn off 2FA for another team member. Each person manages their own.
