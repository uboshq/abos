<?php

declare(strict_types=1);

// ⭐ বার্তার পথ — wa.me, Meta Cloud API, অফিসের ফোন (মালিকের "ok", ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব ঘ)
return [
    'title' => 'বার্তার পথ',
    'menu' => 'বার্তার পথ',
    'tabs' => [
        'roads' => 'পথ',
        'phones' => 'SMS-এর ফোন',
        'cost' => 'খরচ',
    ],
    'save' => 'সংরক্ষণ',
    'saved' => 'সংরক্ষিত।',

    'facts' => [
        'how' => 'কীভাবে',
        'cost' => 'খরচ',
        'from' => 'কোন নম্বর থেকে',
        'template' => 'লেখা',
        'status' => 'অবস্থা জানা',
        'needs' => 'যা লাগবে',
    ],

    'wame' => [
        'title' => 'WhatsApp — বোতাম চাপলে',
        'switch' => 'WhatsApp বোতাম চালু',
        'how' => 'বোতাম চাপলে কর্মীর ফোন/কম্পিউটারে WhatsApp খোলে, নম্বর আর লেখা বসানো থাকে; পাঠান চাপেন কর্মী নিজে',
        'cost' => 'বিনামূল্যে',
        'from' => 'যিনি পাঠাচ্ছেন, তাঁর নিজের WhatsApp',
        'status' => 'কেবল "খোলা হয়েছে" — পৌঁছানো জানা যায় না',
    ],

    'cloud' => [
        'title' => 'WhatsApp — নিজে থেকে (Meta Cloud API)',
        'switch' => 'Cloud API চালু',
        'number' => 'কোম্পানির WhatsApp Business নম্বর',
        'phone_number_id' => 'Phone number ID',
        'waba_id' => 'Business account ID',
        'from_meta' => 'Meta থেকে পাওয়া',
        'token' => 'স্থায়ী চাবি (token)',
        'app_secret' => 'App secret',
        'webhook' => 'Webhook ঠিকানা / verify token',
        'webhook_after_save' => 'সংরক্ষণের পরে আসবে',
        'copy' => 'কপি',
        'copied' => 'কপি হয়েছে',
        'how' => 'সার্ভার নিজে পাঠায়, কাউকে বোতাম চাপতে হয় না',
        'cost' => 'প্রতি বার্তায় Meta-র হার',
        'cost_unset' => '[চালুর আগে Meta-র বাংলাদেশের হার দেখে বসানো হবে]',
        'cost_set' => '৳:rate',
        'template' => 'Meta-র অনুমোদিত লেখা (template) লাগে; "লেখার ছক" থেকে অনুমোদনে পাঠানো যায়',
        'status' => 'পাঠানো · পৌঁছেছে · পড়েছে · ব্যর্থ — সবই খাতায় আসে',
        'needs' => 'Meta Business যাচাই, আর আলাদা একটা নম্বর (কোম্পানির এখনকার WhatsApp নম্বর নয়)',
        'states' => [
            'on' => 'চালু',
            'waiting' => 'চালু হয়নি — যাচাই বাকি',
            'error' => 'সংযোগে ভুল',
        ],
        'check' => 'সংযোগ পরীক্ষা',
    ],

    'sms' => [
        'title' => 'SMS — অফিসের ফোনের সিমে',
        'switch' => 'SMS চালু',
        'how' => 'অফিসের Android ফোনে ABOS অ্যাপ সারি থেকে SMS নিয়ে নিজের সিমে পাঠায়, ফল জানায়',
        'cost' => 'সিমের নিজের SMS প্যাকেজ',
        'status' => 'পাঠানো · পৌঁছেছে · ব্যর্থ',
        'phone' => 'ফোন',
        'last_seen' => 'শেষ দেখা',
        'today' => 'আজ',
        'state' => 'অবস্থা',
        'states' => [
            'online' => 'চালু',
            'offline' => 'সংযুক্ত নয়',
            'off' => 'বন্ধ',
        ],
        'never' => 'কখনো আসেনি',
        'just_now' => 'এইমাত্র',
        'minutes_ago' => ':n মিনিট আগে',
        'hours_ago' => ':n ঘণ্টা আগে',
        'no_phone' => 'কোনো ফোন বসানো নেই',
    ],

    'order' => [
        'title' => 'কোন পথ আগে',
        'cloud' => 'WhatsApp (Cloud API)',
        'then' => '→ না গেলে →',
        'sms' => 'SMS (অফিসের ফোন)',
        'failed' => 'খাতায় "ব্যর্থ", কারণসহ',
        'locale' => 'পার্টির খাতায় যে ভাষা বসানো, সেই ভাষায় যায়; না বসানো থাকলে',
        'locale_label' => 'শুরুর ভাষা',
        'bn' => 'বাংলা',
        'en' => 'English',
    ],

    'cost' => [
        'month' => 'এই মাস — :from থেকে',
        'road' => 'পথ',
        'count' => 'বার্তা',
        'failed' => 'ব্যর্থ',
        'amount' => 'খরচ (৳)',
        'roads' => [
            'wame' => 'WhatsApp — বোতাম চাপলে',
            'cloud' => 'WhatsApp — নিজে থেকে (Cloud API)',
            'phone' => 'SMS — অফিসের ফোন',
        ],
        'free' => 'বিনামূল্যে',
        'sim' => 'সিমের প্যাকেজ',
        'rate_unset' => 'Meta-র হার বসানো হয়নি',
    ],

    'check_ok' => 'সংযোগ ঠিক আছে — Cloud API চালু।',
    'check_failed' => 'সংযোগ হয়নি: :error',
    'submitted' => 'Meta-র অনুমোদনে পাঠানো হয়েছে — অনুমোদন এলে নিজেই চালু হবে।',
    'submit_failed' => 'Meta-য় পাঠানো যায়নি: :error',

    'errors' => [
        'not_ready' => 'Cloud API চালু নয়',
        'not_approved' => 'এই লেখা Meta-র অনুমোদন পায়নি',
        'no_id' => 'Meta বার্তার নম্বর ফেরত দেয়নি',
        'not_set' => 'Phone number ID, Business account ID আর চাবি বসানো নেই',
        'unreachable' => 'Meta-র সার্ভারে পৌঁছানো যায়নি',
        'not_whatsapp' => 'কেবল WhatsApp-এর লেখা Meta-য় যায়',
        'empty_template' => 'লেখা ফাঁকা',
    ],

    // ⓘ module.php-র সেটিংয়ের নাম
    'settings' => [
        'wame' => 'পার্টিকে WhatsApp — বোতাম চাপলে',
        'sms' => 'পার্টিকে SMS — অফিসের ফোনের সিমে',
        'default_locale' => 'পার্টির বার্তার শুরুর ভাষা',
        'cloud_rate' => 'Meta Cloud API — প্রতি বার্তার হার (৳)',
    ],
];
