<?php

declare(strict_types=1);

/**
 * ⭐ কোপাইলট — ABOS-এর ভেতরে ব্যবসার মাথা (মালিকের "ok", পূর্ণ পরিকল্পনা 9a6nHmbFk5NF7YB5NbzCC2, ১১ নভেম্বর ২০২৬)।
 *
 * ⓘ কোডে নাম assistant; পর্দায় মগজের ছবি আর নিচে ছোট "Copilot" — নামটা কন্ট্রোল প্যানেল থেকে বদলায়, কোড লাগে না
 *   ([[AssistantEngine::name()]]; মালিক, ১০ নভেম্বর ২০২৬)।
 *
 * ── ⛔ কোপাইলট নিজে কিছুই গোনে না ─────────────────────────────────────
 * প্রতিটা সংখ্যা মডিউলের নিজের সংজ্ঞা থেকে: নজর = চার ড্যাশবোর্ডের সমস্যা কেন্দ্র ([[Watch]]), সারাংশ = তাদের "এক নজরে"।
 *   তাই কোপাইলটের সংখ্যা আর ড্যাশবোর্ডের সংখ্যা কখনো আলাদা হয় না।
 * ⛔ টাকা ছোঁয় না, খাতায় তোলে না, ডাটাবেস ছোঁয় না — শুধু পড়ে, দেখায়, খসড়া বানায় (খসড়া ধাপ ৩-এ)।
 * ⓘ ভাষার মডেল লাগে কেবল প্রশ্নের উত্তরে (ধাপ ৪) — সেটা NEXUS-এর সার্ভারে; এই মডিউল শুধু তার সাথে যুক্ত হবে।
 */
return [
    'code' => 'assistant',

    'name' => [
        'en' => 'Copilot',
        'bn' => 'কোপাইলট',
    ],

    'version' => '0.1.0',

    // ⓘ উপরের সারিতে, হোমের পাশে — প্রতিদিন সকালে প্রথম যা খোলা হয়
    // ⓘ মালিক, ১০ অক্টোবর ২০২৬: নতুন মডিউল সিস্টেম প্রশাসনের নিচে, ক্রমে — স্থায়ী সম্পদের (১৪) পরে, ব্যাকআপ (২০) সবার শেষেই
    'nav' => ['section' => 'system', 'order' => 15],

    /*
     * ⓘ যাদের সংখ্যা পড়া হয় — প্রতিটা মডিউলের নিজের বোর্ড ([[Watch]])।
     * ⚠️ চক্র হয় না: এদের কেউ কোপাইলটের উপর দাঁড়ায় না।
     */
    'depends_on' => ['accounts', 'sales', 'inventory', 'finance', 'customer', 'credit_control', 'purchase', 'notification'],

    'menu' => [
        'dashboard' => [
            ['label' => 'assistant::menu.today', 'icon' => 'brain', 'route' => 'assistant.today', 'permission' => 'assistant.use'],
        ],
        'reports' => [
            // ⭐ কে কী খুঁজল, কে কী জিজ্ঞেস করল, কোপাইলট কী বলল — কেবল মালিক (১০ নভেম্বরের নির্দেশ)
            ['label' => 'assistant::menu.audit', 'icon' => 'clock', 'route' => 'assistant.audit', 'permission' => 'assistant.audit'],
        ],
    ],

    'permissions' => [
        'assistant.use',      // কোপাইলটের পাতা, নজর, সারাংশ — প্রত্যেকে নিজের চাবির তথ্যটুকুই পান
        'assistant.audit',    // খোঁজা ও কোপাইলটের খাতা — কেবল মালিক (migration-এ super_admin)
        // ⓘ সেটিংসের আলাদা চাবি নেই — নাম, অংশ চালু/বন্ধ, মডেলের ঠিকানা প্রতিটা ঘর super_admin_only (EveryRightWeHandOutStopsSomethingTest)
    ],

    /*
     * ⭐ কন্ট্রোল প্যানেলে কোপাইলটের সেটিং — কোম্পানি ধরে, কেবল মালিক বদলান।
     * ⓘ নাম বাংলা আর ইংরেজি আলাদা — "Copilot" Microsoft-এর নাম; অন্যকে বিক্রির দিন এক চাপে বদলানো যায়।
     */
    'settings' => [
        ['key' => 'assistant.name_bn', 'super_admin_only' => true, 'label' => 'assistant::settings.name_bn', 'type' => 'string', 'default' => 'Copilot', 'group' => 'general'],
        ['key' => 'assistant.name_en', 'super_admin_only' => true, 'label' => 'assistant::settings.name_en', 'type' => 'string', 'default' => 'Copilot', 'group' => 'general'],
        ['key' => 'assistant.watch', 'super_admin_only' => true, 'label' => 'assistant::settings.watch', 'type' => 'boolean', 'default' => true, 'group' => 'general'],
        ['key' => 'assistant.brief', 'super_admin_only' => true, 'label' => 'assistant::settings.brief', 'type' => 'boolean', 'default' => true, 'group' => 'general'],
        ['key' => 'assistant.suggest', 'super_admin_only' => true, 'label' => 'assistant::settings.suggest', 'type' => 'boolean', 'default' => true, 'group' => 'general'],
        ['key' => 'assistant.draft', 'super_admin_only' => true, 'label' => 'assistant::settings.draft', 'type' => 'boolean', 'default' => true, 'group' => 'general'],
        ['key' => 'assistant.ask', 'super_admin_only' => true, 'label' => 'assistant::settings.ask', 'type' => 'boolean', 'default' => true, 'group' => 'general'],
        // ⓘ প্রশ্নের লেখা কতদিন থাকে — তারপর মোছে, "কে কখন কী" থেকে যায়
        ['key' => 'assistant.keep_days', 'super_admin_only' => true, 'label' => 'assistant::settings.keep_days', 'type' => 'integer', 'default' => 365, 'group' => 'general'],
        // ⓘ একজন দিনে কত প্রশ্ন (ধাপ ৪)
        ['key' => 'assistant.daily_questions', 'super_admin_only' => true, 'label' => 'assistant::settings.daily_questions', 'type' => 'integer', 'default' => 200, 'group' => 'general'],
        // ⓘ ভাষার মডেলের ঠিকানা — NEXUS-এর সার্ভার (অফিসের Mac mini); খালি = বন্ধ, নজর-সারাংশ-প্রস্তাব-খসড়া তবু চলে
        ['key' => 'assistant.model_url', 'super_admin_only' => true, 'label' => 'assistant::settings.model_url', 'type' => 'string', 'default' => '', 'group' => 'model'],
        // ⓘ সার্ভারের ভাষা — NEXUS-এর পুরনো চুক্তি (/v1/assistant/interpret) বা OpenAI-ধাঁচের চ্যাট (/v1/chat/completions — NEXUS-এর গেটওয়ে বা Ollama; মালিক, ১২ নভেম্বর ২০২৬: Mac mini-তে মডেল বসেছে)
        ['key' => 'assistant.model_kind', 'super_admin_only' => true, 'label' => 'assistant::settings.model_kind', 'type' => 'select', 'options' => ['nexus', 'chat'], 'option_label' => 'assistant::settings.kind.', 'default' => 'nexus', 'group' => 'model'],
        ['key' => 'assistant.model_name', 'super_admin_only' => true, 'label' => 'assistant::settings.model_name', 'type' => 'string', 'default' => 'qwen3:4b-instruct', 'group' => 'model'],
    ],

    'role_templates' => [
        // ⓘ কোপাইলট দেখা — নিজের চাবির তথ্যটুকুই; খাতা আর সেটিং মালিকের
        'Copilot user' => ['assistant.use'],
    ],
];
