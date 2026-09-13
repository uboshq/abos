<?php

declare(strict_types=1);

return [
    /*
     * One failure message for every case — section 16.5. Telling somebody
     * "no such user" separately from "wrong password" hands an attacker the
     * user list first and lets them spend their attempts only on real names.
     */
    'failed' => 'Incorrect username or password.',
    'throttle' => 'Too many attempts. Try again in :seconds seconds.',
    'locked' => 'Too many failed attempts. Try again in :minutes minutes.',

    'welcome_back' => 'Welcome back',

    /* Greeting by time of day, on the quiet door (/signin). */
    /* Link back to the full page from the quiet door. */
    'about_abos' => 'About ABOS',

    'greeting' => [
        'morning' => 'Good morning',
        'afternoon' => 'Good afternoon',
        'evening' => 'Good evening',
    ],
    'sign_in_to_workspace' => 'Sign in to your workspace',
    'sign_in' => 'Sign in',
    'authenticating' => 'Authenticating…',

    'identifier' => 'Username, email or mobile',
    'password' => 'Password',
    'show_password' => 'Show password',
    'hide_password' => 'Hide password',
    'caps_lock_on' => 'Caps Lock is on',
    'remember_device' => 'Remember this device',
    'forgot_password' => 'Forgot password?',
    'back_to_sign_in' => 'Back to sign in',
    'email' => 'Email address',
    'new_password' => 'New password',
    'confirm_password' => 'Type the new password again',
    'password_rule' => 'At least 8 characters, including at least one letter and one number.',

    /*
     * Conditional wording — "if". Saying "we have sent it" would itself be
     * a fact: that the address exists. Anyone could then count the staff
     * list one address at a time. This sentence gives the same comfort and
     * leaks nothing.
     */
    'forgot_title' => 'Get your password back',
    'forgot_lead' => 'Give us your email address and we will send a link for setting a new password.',
    'forgot_submit' => 'Send the link',
    'forgot_sending' => 'Sending…',
    'forgot_sent' => 'If that address is in our books, a link for setting a new password has been sent. Check your inbox, and the spam folder too.',

    /*
     * The only honest path for someone with no real mailbox. A depot
     * worker's email is often on paper only — an address nobody opens.
     * The letter goes nowhere for them, and without this line they would
     * sit watching an inbox all day.
     */
    'forgot_no_mail' => 'If no email arrives, ask your manager — they can set your password directly.',

    'reset_title' => 'Set a new password',
    'reset_lead' => 'This link works once, and only for a short while.',
    'reset_submit' => 'Set the password',
    'reset_saving' => 'Saving…',

    /*
     * One message for two different failures. The broker says "no user
     * with that email" and "invalid token" separately, but this screen can
     * be reached without a valid token — so separate messages would reopen
     * the very enumeration hole the first step closes.
     */
    'reset_link_dead' => 'That link no longer works — it has either expired or already been used. Ask for a new one.',
    'reset_done' => 'Your new password is set. Sign in with it now.',

    /* The letter itself — [[PasswordResetLink]] and `mail.password_reset`. */
    'reset_mail_subject' => 'Set your password — ABOS',
    'reset_mail_heading' => 'Password reset request',
    'reset_mail_greeting' => ':name, this was requested for your account.',
    'reset_mail_body' => 'Press the button below to set a new password. The link stops working after :minutes minutes, and after one use.',
    'reset_mail_button' => 'Set a new password',
    'reset_mail_fallback' => 'If the button does not work, copy this address into your browser:',
    'reset_mail_ignore' => 'If you did not ask for this, there is nothing to do — leave the link alone and your old password keeps working.',

    /*
     * ⓘ `coming_soon` used to live here — removed 13 Sep 2026.
     *
     * Its own note said the key would go once SMTP arrived and a real link
     * could take its place. That day came: the link now works
     * (`password.request`), so the badge has no job left.
     *
     * ⚠️ Removed from BOTH languages — leaving it in one would turn
     * [[BothLanguagesSayTheSameThingTest]] red, and rightly so.
     */

    'highlight' => [
        'multi_company' => 'Multi company',
        'multi_branch' => 'Multi branch',
        'secure' => 'Secure',
        'fast' => 'Fast',
        'audit_trail' => 'Audit trail',
        'daily_backup' => 'Daily backup',
        'role_based' => 'Role based',
        'hyperlinked' => 'Hyperlinked ERP',
    ],
    'code' => 'Code from your app',
    'code_hint' => 'The six digits from your authenticator app, or one recovery code.',
    'code_needed' => 'Two-step sign-in is on for this account — enter the code.',
    'code_wrong' => 'That code did not match. The app changes it every 30 seconds — read the current one.',
    'mfa_title' => 'Two-step sign-in',
    'mfa_subtitle' => 'Your password plus a code from your phone — the books stay shut even if the password leaks',
    'mfa_why' => 'Right now a single password is the only lock on your books. Anyone who learns it has every bill, every cost price and every bank balance. With two-step on, knowing the password is not enough without your phone.',
    'mfa_turn_on' => 'Turn it on',
    'mfa_turn_off' => 'Turn it off',
    'mfa_is_on' => 'Two-step sign-in is on.',
    'mfa_off' => 'Two-step sign-in has been turned off.',
    'mfa_step_one' => '1 · Put this key into your app',
    'mfa_step_one_note' => 'In Google Authenticator or any authenticator app, choose "enter a setup key" and type this. It needs no internet.',
    'mfa_step_two' => '2 · The code your app is showing',
    'mfa_confirm' => 'Check it and turn on',
    'confirm_with_password' => 'Enter your password to confirm',
    'password_wrong' => 'That password did not match.',
    'recovery_title' => 'Recovery codes — write these down now',
    'recovery_note' => 'If the phone is lost or broken, any one of these gets you in. Each works once, and once you leave this page they can never be shown again.',
    'recovery_left' => '{0} No recovery codes left — a lost phone would lock you out|{1} Only 1 recovery code left|[2,*] :count recovery codes left',
];
