<?php

declare(strict_types=1);

// ⭐ পার্টিকে বার্তা — খাতা: WhatsApp আর SMS এক তালিকায়, প্রতিটার অবস্থাসহ (ধাপ ৩, মালিকের "ok" ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব গ)
return [
    'title' => 'পার্টিকে বার্তা — খাতা',
    'tabs' => [
        'all' => 'সব',
        'delivered' => 'পৌঁছেছে',
        'waiting' => 'অপেক্ষায়',
        'failed' => 'ব্যর্থ',
    ],
    'tabs_label' => 'অবস্থা অনুযায়ী',
    'noun' => 'বার্তা',
    'search' => 'নম্বর, পার্টি বা কাগজের নম্বর',
    'empty' => 'এখনো কাউকে কিছু পাঠানো হয়নি।',

    // আজকের সংখ্যা
    'today' => [
        'sent' => 'আজ পাঠানো',
        'delivered' => 'পৌঁছেছে',
        'waiting' => 'অপেক্ষায়',
        'failed' => 'ব্যর্থ',
    ],

    // কলাম
    'when' => 'কখন',
    'party' => 'পার্টি',
    'phone' => 'নম্বর',
    'paper' => 'কাগজ',
    'road' => 'পথ',
    'how' => 'কে/কীভাবে',
    'state' => 'অবস্থা',

    // ছাঁকনি
    'date' => 'তারিখ',
    'who' => 'কে পাঠালেন',
    'party_account' => 'পার্টির হিসাব (তাগাদা)',

    // কাগজের ঘরে নিয়মের নাম (লেখা না থাকলে)
    'rules' => [
        'due_reminder' => 'বাকির তাগাদা',
        'payment_received' => 'টাকা পাওয়ার বিজ্ঞপ্তি',
    ],

    // কে/কীভাবে
    'button' => ':who · বোতাম',
    'auto' => 'নিজে থেকে · :road',
    'cloud' => 'Cloud',
    'office_phone' => 'অফিসের ফোন',

    // অবস্থা
    'states' => [
        'opened' => 'খোলা হয়েছে',
        'waiting' => 'অপেক্ষায়',
        'waiting_phone' => 'ফোনে অপেক্ষায়',
        'sent' => 'গেছে :time',
        'delivered' => 'পৌঁছেছে :time',
        'read' => 'পড়েছেন :time',
        'failed' => 'ব্যর্থ',
    ],
    'fell_back' => ':reason → SMS-এ গেছে',
    'no_reason' => 'কারণ জানা যায়নি',

    // সারির কাজ
    'menu' => [
        'view' => 'লেখাটা দেখুন',
        'again' => 'আবার পাঠান',
        'other' => 'অন্য পথে পাঠান',
        'ledger' => 'পার্টির খাতা খুলুন',
    ],
    'other_to' => [
        'cloud' => 'WhatsApp',
        'wame' => 'WhatsApp',
        'phone' => 'SMS',
    ],
    'road_off' => [
        'whatsapp' => 'WhatsApp-এর পথ বন্ধ',
        'sms' => 'SMS-এর পথ বন্ধ, বা অফিসের ফোন বসানো নেই',
        'sms_abroad' => 'SMS কেবল বাংলাদেশের মোবাইলে যায়',
        'cloud' => 'WhatsApp Cloud এখন তৈরি নেই, বা এই লেখা Meta-র অনুমোদিত নয়',
    ],
    'resent' => 'আবার পাঠানো হলো — খাতায় নতুন সারি।',
    'resent_failed' => 'পাঠানো গেল না: :reason',

    // লেখার পাতা
    'message_title' => 'যে লেখা গেছে',
    'language' => 'ভাষা',
    'template' => 'যে লেখা থেকে',
    'back' => 'খাতায় ফিরুন',

    // অ্যাপের দরজা
    'party_phone' => 'পার্টির নম্বর',
    // ⓘ নিজে থেকে পাঠানোর নিয়ম — ছাঁকনির ঘর
    'rule' => 'নিজে থেকে',
    // ⓘ নিয়মের নামগুলো উপরের 'rules'-এ — একই চাবি দুইবার নয় (ADuplicateKeySwallowedTheWordsTest)
];
