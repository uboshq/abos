<?php

declare(strict_types=1);

/*
 * Navy — ABOS-এর নিজের রূপ, আর ডিফল্ট — গভীর নীল, ঘন, শান্ত।
 *
 * ── এই ফাইলটা কী, আর কেন CSS নয় ─────────────────────────────────────
 * থিম ইঞ্জিনের ধাপ ১: রূপ **ডাটায়**, CSS ফাইলে নয়। আজ পর্যন্ত দশটা
 * রূপের টোকেন `resources/css/themes.css`-এ `[data-ui='x']` ব্লক হয়ে
 * ছিল, আর তার ফল ছিল দুইটা:
 *
 *   · পাতায় **দশটা রূপেরই** টোকেন নামত — যিনি Navy চালান তিনিও
 *     Odoo, Fiori, Linear সবার রং ডাউনলোড করতেন
 *   · নতুন রূপ মানে CSS ফাইলে হাতে আরেকটা ব্লক, আর ভুল টোকেনের নাম
 *     নীরবে কিছুই করত না
 *
 * ডাটায় এলে সংকলক কেবল চলতি রূপটা পাঠাতে পারে, আর স্কিমা ভুল নামটা
 * সেভের সময়েই ফেরাতে পারে।
 *
 * ── মানগুলো হাতে টোকা হয়নি ──────────────────────────────────────────
 * `themes.css` পড়ে যন্ত্র দিয়ে বের করা, কারণ প্ল্যানের শর্ত হলো
 * **একটাও পিক্সেল নড়বে না**। দশটা রূপে দুই হাজারের বেশি মান; হাতে
 * টুকলে একটা ভুল হেক্স কেউ ধরতে পারত না।
 *
 * `LooksMatchTheStylesheetTest` দুই দিক মিলিয়ে দেখে — এই ফাইল আর
 * স্টাইলশিট আলাদা কিছু বললে সেটা ভাঙে।
 */

return [
    'light' => [
        '--color-badge-danger-bg' => '#fef2f2',
        '--color-badge-danger-ink' => '#b91c1c',
        '--color-badge-pending-bg' => '#fff7ed',
        '--color-badge-pending-ink' => '#9a3412',
        '--color-badge-success-bg' => '#ecfdf5',
        '--color-badge-success-ink' => '#047857',
        '--color-badge-warning-bg' => '#fff7ed',
        '--color-badge-warning-ink' => '#9a3412',
        '--color-border' => '#e4ebef',
        '--color-border-strong' => '#bce3ea',
        '--color-cell-line' => 'transparent',
        '--color-ink' => '#0b1f33',
        '--color-ink-body' => '#0b1f33',
        '--color-ink-muted' => '#41525e',
        '--color-link' => '#1565c0',
        '--color-sidebar' => '#06323c',
        '--color-sidebar-active' => '#08b8c8',
        '--color-sidebar-border' => '#06323c',
        '--color-sidebar-hover' => '#0a4351',
        '--color-sidebar-icon' => '#9fc4cc',
        '--color-sidebar-panel' => '#ffffff',
        '--color-state-on' => '#047857',
        '--color-surface-app' => '#f5fafb',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#f2f6fc',
        '--color-surface-muted' => '#f8fafc',
        '--color-surface-selected' => '#e2ecfa',
        '--color-surface-sunken' => '#f1f5f7',
        '--color-table-head' => '#f8fafc',
        '--color-table-head-ink' => '#41525e',
        '--color-toolbar' => '#ffffff',
        '--color-topbar' => '#ffffff',
        '--color-topbar-border' => '#e4ebef',
        '--color-topbar-field' => '#f8fafc',
        '--color-topbar-hover' => '#f2f6fc',
        '--color-topbar-ink' => '#0b1f33',
        '--color-topbar-ink-muted' => '#41525e',
        '--color-topnav' => '#ffffff',
        '--color-topnav-border' => '#e5e7eb',
        '--color-topnav-hover' => '#f2f6fc',
        '--color-topnav-ink' => '#0f172a',
        '--color-topnav-ink-muted' => '#475569',
        '--color-topnav-selected' => '#e2ecfa',
        '--font-size-table' => '12.5px',
        '--grid-pad-x' => '12px',
        '--grid-pad-y' => '7px',
        '--radius-badge' => '6px',
        '--radius-card' => '10px',

        /*
         * বাঁ মেনুর মাপ — মালিকের নমুনা থেকে হুবহু
         * (`E:\ABOSrand\design\_ui.css`: `.nav a{height:30px;
         * font-size:12.5px}`)।
         *
         * ⚠️ এই অ্যাপে `1rem = 20px`, তাই ডিফল্ট `--text-sm`
         * (0.8125rem) পর্দায় ১৬.২৫px হয় — নমুনার চেয়ে ঢের বড়। মানটা
         * তাই px-এ লেখা, rem-এ নয়।
         *
         * ⓘ ঘর দুইটা `tokens.css`-এ ডিফল্ট নিয়ে বসানো, আর ডিফল্ট মানে
         * আজকের আচরণ — তাই বাকি ন'টা রূপে একটা পিক্সেলও নড়েনি।
         */

        /*
         * ── রেলের আইকন: রঙিন টাইল নয়, শান্ত এক রঙ ─────────────
         *
         * মালিকের নমুনা (`E:\ABOS\brand\design\_ui.css`):
         * `.rail a{color:#9FC4CC}` — প্রতিটা আইকনের নিজের রং নেই,
         * নিজের টালিও নেই। গাঢ় টিয়া জমিনে একটাই হালকা রং।
         *
         * ⚠️ আগে প্রতিটা মডিউলের নিজের রং ছিল — নীল, ইন্ডিগো,
         * এমারেল্ড, অ্যাম্বার… ষোলোটা। ⓘ পুরনো মানগুলো ফেলে দেওয়া
         * হয়নি, মালিকের নির্দেশে তুলে রাখা:
         * `E:\ABOS\old icon\module-colours-as-of-2026-09-04.css`
         *
         * ⭐ কেন নমুনাটা এক রঙ চায়: ষোলোটা রং মানে চোখের জন্য
         * ষোলোটা দাবি, আর তখন **চালু মডিউলটা** আলাদা করে চেনা
         * যায় না। এক রঙে চালুটাই একমাত্র উজ্জ্বল জিনিস।
         *
         * ⓘ ঘরগুলো কেবল এই রূপে — বাকি ন'টা `tokens.css`-এর
         * ডিফল্ট রংই পায়, তাই তাদের একটা পিক্সেলও নড়ে না।
         */
        '--color-module-dashboard' => '#0e4b59',
        '--color-module-accounts' => '#0e4b59',
        '--color-module-finance' => '#0e4b59',
        '--color-module-sales' => '#0e4b59',
        '--color-module-purchase' => '#0e4b59',
        '--color-module-inventory' => '#0e4b59',
        '--color-module-customer' => '#0e4b59',
        '--color-module-supplier' => '#0e4b59',
        '--color-module-hr' => '#0e4b59',
        '--color-module-approval' => '#0e4b59',
        '--color-module-master_data' => '#0e4b59',
        '--color-module-reports' => '#0e4b59',
        '--color-module-governance' => '#0e4b59',
        '--color-module-system_admin' => '#0e4b59',
        '--color-module-backup' => '#0e4b59',
        '--color-module-restaurant' => '#0e4b59',
        /*
         * টাইল নেই — নমুনায় রেলের আইকন খালি জমিনে বসে।
         *
         * ⓘ `.rail a{width:40px;height:40px;border-radius:9px;
         * color:#9FC4CC}` — চৌকো ভরাট নয়, কেবল আইকনের রং।
         */
        '--rail-tile-bg' => 'transparent',
        '--rail-tile-radius' => '9px',
        '--rail-tile-ink' => '#9fc4cc',

        /*
         * চালু আইটেম — ভরাট নয়, ছোপ।
         *
         * ⓘ নমুনা: `.nav a.on{background:var(--brand-wash);
         * color:var(--brand)}` — অর্থাৎ হালকা টিয়া জমিনে গাঢ় টিয়া লেখা।
         * ⚠️ ভরাট পিলে লেখাটা সাদা হয়ে যেত, আর পাশের সারিগুলোর সাথে
         * তার সম্পর্কও ছিঁড়ে যেত।
         */
        '--rail-item-on-bg' => '#e6f5f8',
        '--rail-item-on-ink' => '#087f91',

        '--rail-item-h' => '30px',
        '--rail-item-font' => '12.5px',
        '--radius-field' => '6px',
        '--radius-pill' => '6px',
        '--row-height' => '44px',
        '--row-height-dense' => '36px',
        '--spacing-header' => '56px',
    ],

    /*
     * গাঢ় থিমে এই রূপের নিজের মান।
     *
     * খালি থাকলে রূপটা গাঢ় থিমের সাধারণ মানই নেয়। আজ দশটারই
     * নিজের গাঢ় রূপ আছে — SAP Horizon Dark, Fluent 2 Dark ও
     * Salesforce Dark-এর নিজের ধাপ ধরে।
     */
    'dark' => [
        '--color-border' => '#1e2a3d',
        '--color-border-strong' => '#2b3b55',
        '--color-cell-line' => '#1e2a3d',
        '--color-footer' => '#0a101c',
        '--color-footer-border' => '#1e2a3d',
        '--color-footer-ink' => '#93a5bf',
        '--color-ink' => '#e6ecf5',
        '--color-ink-body' => '#e6ecf5',
        '--color-ink-muted' => '#93a5bf',
        '--color-link' => '#7fb2f5',
        '--color-row-alt' => '#0e1626',
        '--color-sidebar' => '#070c15',
        '--color-sidebar-active' => '#3b82f6',
        '--color-sidebar-border' => '#1e2a3d',
        '--color-sidebar-hover' => '#0e1c3a',
        '--color-sidebar-icon' => '#93a5bf',
        '--color-sidebar-panel' => '#0c1422',
        '--color-surface-app' => '#0a101c',
        '--color-surface-card' => '#111a2b',
        '--color-surface-hover' => '#16213a',
        '--color-surface-muted' => '#0e1626',
        '--color-surface-selected' => '#1b2b49',
        '--color-surface-sunken' => '#0e1626',
        '--color-table-head' => '#0e1626',
        '--color-table-head-ink' => '#93a5bf',
        '--color-toolbar' => '#111a2b',
        '--color-topbar' => '#0d1626',
        '--color-topbar-border' => '#1e2a3d',
        '--color-topbar-field' => '#16213a',
        '--color-topbar-hover' => '#16213a',
        '--color-topbar-ink' => '#e6ecf5',
        '--color-topbar-ink-muted' => '#93a5bf',
        '--color-topnav' => '#0c1422',
        '--color-topnav-border' => '#1e2a3d',
        '--color-topnav-hover' => '#16213a',
        '--color-topnav-ink' => '#e6ecf5',
        '--color-topnav-ink-muted' => '#93a5bf',
        '--color-topnav-selected' => '#1b2b49',
    ],
];
