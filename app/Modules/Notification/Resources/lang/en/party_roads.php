<?php

declare(strict_types=1);

// ⭐ Message roads — wa.me, Meta Cloud API, office phone (owner "ok" 23 Nov 2026; sample HUXqpfhxng8823eEFrvH54 tab ঘ)
return [
    'title' => 'Message roads',
    'menu' => 'Message roads',
    'tabs' => [
        'roads' => 'Roads',
        'phones' => 'SMS phones',
        'cost' => 'Cost',
    ],
    'save' => 'Save',
    'saved' => 'Saved.',

    'facts' => [
        'how' => 'How',
        'cost' => 'Cost',
        'from' => 'From which number',
        'template' => 'Text',
        'status' => 'Status known',
        'needs' => 'Needs',
    ],

    'wame' => [
        'title' => 'WhatsApp — on a button',
        'switch' => 'WhatsApp button on',
        'how' => 'The button opens WhatsApp on the staff member\'s phone or computer with the number and text filled in; they press send themselves',
        'cost' => 'Free',
        'from' => 'The sender\'s own WhatsApp',
        'status' => 'Only "opened" — delivery is not known',
    ],

    'cloud' => [
        'title' => 'WhatsApp — automatic (Meta Cloud API)',
        'switch' => 'Cloud API on',
        'number' => 'Company WhatsApp Business number',
        'phone_number_id' => 'Phone number ID',
        'waba_id' => 'Business account ID',
        'from_meta' => 'From Meta',
        'token' => 'Permanent token',
        'app_secret' => 'App secret',
        'webhook' => 'Webhook address / verify token',
        'webhook_after_save' => 'Shown after saving',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'how' => 'The server sends by itself; nobody presses a button',
        'cost' => 'Meta\'s rate per message',
        'cost_unset' => '[to be set from Meta\'s Bangladesh rate before going live]',
        'cost_set' => 'Tk :rate',
        'template' => 'Needs a Meta-approved text (template); send it for approval from "Message texts"',
        'status' => 'Sent · delivered · read · failed — all reach the log',
        'needs' => 'Meta Business verification, and a separate number (not the company\'s current WhatsApp number)',
        'states' => [
            'on' => 'On',
            'waiting' => 'Not on — check pending',
            'error' => 'Connection error',
        ],
        'check' => 'Test connection',
    ],

    'sms' => [
        'title' => 'SMS — on the office phone\'s SIM',
        'switch' => 'SMS on',
        'how' => 'The ABOS app on an office Android phone takes SMS from the queue, sends them on its own SIM and reports back',
        'cost' => 'The SIM\'s own SMS package',
        'status' => 'Sent · delivered · failed',
        'phone' => 'Phone',
        'last_seen' => 'Last seen',
        'today' => 'Today',
        'state' => 'State',
        'states' => [
            'online' => 'On',
            'offline' => 'Not connected',
            'off' => 'Off',
        ],
        'never' => 'Never seen',
        'just_now' => 'Just now',
        'minutes_ago' => ':n minutes ago',
        'hours_ago' => ':n hours ago',
        'no_phone' => 'No phone set up',
    ],

    'order' => [
        'title' => 'Which road first',
        'cloud' => 'WhatsApp (Cloud API)',
        'then' => '→ if not delivered →',
        'sms' => 'SMS (office phone)',
        'failed' => 'Logged as "failed", with the reason',
        'locale' => 'Goes in the language set on the party; when none is set',
        'locale_label' => 'Default language',
        'bn' => 'বাংলা',
        'en' => 'English',
    ],

    'cost' => [
        'month' => 'This month — from :from',
        'road' => 'Road',
        'count' => 'Messages',
        'failed' => 'Failed',
        'amount' => 'Cost (Tk)',
        'roads' => [
            'wame' => 'WhatsApp — on a button',
            'cloud' => 'WhatsApp — automatic (Cloud API)',
            'phone' => 'SMS — office phone',
        ],
        'free' => 'Free',
        'sim' => 'SIM package',
        'rate_unset' => 'Meta\'s rate not set',
    ],

    'check_ok' => 'Connection works — Cloud API is on.',
    'check_failed' => 'No connection: :error',
    'submitted' => 'Sent to Meta for approval — it turns on by itself once approved.',
    'submit_failed' => 'Could not send to Meta: :error',

    'errors' => [
        'not_ready' => 'Cloud API is not on',
        'not_approved' => 'This text is not approved by Meta',
        'no_id' => 'Meta returned no message id',
        'not_set' => 'Phone number ID, Business account ID and token are not set',
        'unreachable' => 'Could not reach Meta\'s server',
        'not_whatsapp' => 'Only WhatsApp texts go to Meta',
        'empty_template' => 'The text is empty',
    ],

    // ⓘ labels of the module.php settings
    'settings' => [
        'wame' => 'Party WhatsApp — on a button',
        'sms' => 'Party SMS — on the office phone\'s SIM',
        'default_locale' => 'Default language of party messages',
        'cloud_rate' => 'Meta Cloud API — rate per message (Tk)',
    ],
];
