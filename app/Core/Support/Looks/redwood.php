<?php

declare(strict_types=1);

/*
 * Redwood — Oracle Fusion Cloud — ইটরঙা পটি, গোল কার্ড, অনেক ফাঁকা।
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
        '--color-badge-danger-bg' => '#f9e7e4',
        '--color-badge-danger-ink' => '#a93a2b',
        '--color-badge-pending-bg' => '#fbf1e0',
        '--color-badge-pending-ink' => '#9a5b00',
        '--color-badge-success-bg' => '#e8f1eb',
        '--color-badge-success-ink' => '#146b3a',
        '--color-badge-warning-bg' => '#fbf1e0',
        '--color-badge-warning-ink' => '#9a5b00',
        '--color-border' => '#e6e2dc',
        '--color-border-strong' => '#d9d4cc',
        '--color-cell-line' => 'transparent',
        '--color-footer' => '#fffdfb',
        '--color-footer-border' => '#e6e2dc',
        '--color-footer-ink' => '#8a8681',
        '--color-ink' => '#161513',
        '--color-ink-body' => '#161513',
        '--color-ink-muted' => '#8a8681',
        '--color-link' => '#0b5a9e',
        '--color-sidebar' => '#f5f4f2',
        '--color-sidebar-active' => '#c74634',
        '--color-sidebar-border' => '#e6e2dc',
        '--color-sidebar-hover' => '#efece7',
        '--color-sidebar-icon' => '#8a8681',
        '--color-sidebar-panel' => '#f5f4f2',
        '--color-state-on' => '#146b3a',
        '--color-surface-app' => '#f5f4f2',
        '--color-surface-card' => '#fffdfb',
        '--color-surface-hover' => '#faf8f5',
        '--color-surface-muted' => '#f1efec',
        '--color-surface-selected' => '#efece7',
        '--color-surface-sunken' => '#f9f7f4',
        '--color-table-head' => 'transparent',
        '--color-table-head-ink' => '#8a8681',
        '--color-toolbar' => '#fffdfb',
        '--color-topbar' => '#fffdfb',
        '--color-topbar-border' => '#e6e2dc',
        '--color-topbar-field' => '#f5f4f2',
        '--color-topbar-hover' => '#f1efec',
        '--color-topbar-ink' => '#161513',
        '--color-topbar-ink-muted' => '#5c5a57',
        '--color-topnav' => '#fffdfb',
        '--color-topnav-border' => '#e6e2dc',
        '--color-topnav-hover' => '#f1efec',
        '--color-topnav-ink' => '#161513',
        '--color-topnav-ink-muted' => '#8a8681',
        '--color-topnav-selected' => '#f7e6e2',
        '--font-size-table' => '13px',
        '--grid-pad-x' => '12px',
        '--grid-pad-y' => '10px',
        '--radius-badge' => '999px',
        '--radius-card' => '16px',
        '--radius-field' => '999px',
        '--radius-pill' => '999px',
        '--rail-item-radius' => '12px',
        '--rail-item-shadow' => '0 1px 3px rgb(22 21 19 / 0.09)',
        '--row-height' => '52px',
        '--row-height-dense' => '44px',
        '--spacing-header' => '56px',
        '--topbar-control-h' => '34px',
    ],

    /*
     * গাঢ় থিমে এই রূপের নিজের মান।
     *
     * খালি থাকলে রূপটা গাঢ় থিমের সাধারণ মানই নেয়। আজ দশটারই
     * নিজের গাঢ় রূপ আছে — SAP Horizon Dark, Fluent 2 Dark ও
     * Salesforce Dark-এর নিজের ধাপ ধরে।
     */
    'dark' => [
        '--color-border' => '#332f2b',
        '--color-border-strong' => '#474139',
        '--color-cell-line' => '#332f2b',
        '--color-footer' => '#161513',
        '--color-footer-border' => '#332f2b',
        '--color-footer-ink' => '#a8a198',
        '--color-ink' => '#f2efe9',
        '--color-ink-body' => '#f2efe9',
        '--color-ink-muted' => '#a8a198',
        '--color-link' => '#e08a76',
        '--color-row-alt' => '#1b1a18',
        '--color-sidebar' => '#131210',
        '--color-sidebar-active' => '#c74634',
        '--color-sidebar-border' => '#332f2b',
        '--color-sidebar-hover' => '#1e293b',
        '--color-sidebar-icon' => '#a8a198',
        '--color-sidebar-panel' => '#1b1a18',
        '--color-surface-app' => '#161513',
        '--color-surface-card' => '#201f1d',
        '--color-surface-hover' => '#2a2926',
        '--color-surface-muted' => '#1b1a18',
        '--color-surface-selected' => '#33201c',
        '--color-surface-sunken' => '#1b1a18',
        '--color-table-head' => '#1b1a18',
        '--color-table-head-ink' => '#a8a198',
        '--color-toolbar' => '#201f1d',
        '--color-topbar' => '#201f1d',
        '--color-topbar-border' => '#332f2b',
        '--color-topbar-field' => '#2a2926',
        '--color-topbar-hover' => '#2a2926',
        '--color-topbar-ink' => '#f2efe9',
        '--color-topbar-ink-muted' => '#a8a198',
        '--color-topnav' => '#1b1a18',
        '--color-topnav-border' => '#332f2b',
        '--color-topnav-hover' => '#2a2926',
        '--color-topnav-ink' => '#f2efe9',
        '--color-topnav-ink-muted' => '#a8a198',
        '--color-topnav-selected' => '#33201c',
    ],
];
