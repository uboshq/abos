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
