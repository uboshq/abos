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

    'welcome_back' => 'Welcome back',
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
];
