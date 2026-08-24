<?php

declare(strict_types=1);

/*
 * Rose — ABOS-এর নিজের রূপ — উষ্ণ গোলাপি, নরম ধার, সোনার রেখা।
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
        '--color-badge-danger-bg' => '#fbe9ec',
        '--color-badge-danger-ink' => '#b4243c',
        '--color-badge-pending-bg' => '#fdf3e3',
        '--color-badge-pending-ink' => '#7d5005',
        '--color-badge-success-bg' => '#e9f6ef',
        '--color-badge-success-ink' => '#116b3d',
        '--color-badge-warning-bg' => '#fdf3e3',
        '--color-badge-warning-ink' => '#7d5005',
        '--color-border' => '#d9e1ec',
        '--color-border-strong' => '#b9c6d8',
        '--color-cell-line' => 'transparent',
        '--color-ink' => '#1b2430',
        '--color-ink-body' => '#1b2430',
        '--color-ink-muted' => '#5b6575',
        '--color-link' => '#9d1249',
        '--color-sidebar' => '#f6f8fb',
        '--color-sidebar-active' => '#c2185b',
        '--color-sidebar-border' => '#d9e1ec',
        '--color-sidebar-hover' => '#eef2f8',
        '--color-sidebar-icon' => '#5b6575',
        '--color-sidebar-panel' => '#f6f8fb',
        '--color-state-on' => '#116b3d',
        '--color-surface-app' => '#ffffff',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#fafbfd',
        '--color-surface-muted' => '#f6f8fb',
        '--color-surface-selected' => '#fff1f6',
        '--color-surface-sunken' => '#f6f8fb',
        '--color-table-head' => '#f6f8fb',
        '--color-table-head-ink' => '#5b6575',
        '--color-toolbar' => '#ffffff',
        '--color-topbar' => '#ffffff',
        '--color-topbar-border' => '#d9e1ec',
        '--color-topbar-field' => '#f6f8fb',
        '--color-topbar-hover' => '#eef2f8',
        '--color-topbar-ink' => '#1b2430',
        '--color-topbar-ink-muted' => '#5b6575',
        '--color-topnav' => '#ffffff',
        '--color-topnav-border' => '#d9e1ec',
        '--color-topnav-hover' => '#eef2f8',
        '--color-topnav-ink' => '#9d1249',
        '--color-topnav-ink-muted' => '#5b6575',
        '--color-topnav-selected' => '#fff1f6',
        '--font-size-table' => '13px',
        '--gold-hairline-h' => '2px',
        '--grid-pad-x' => '12px',
        '--grid-pad-y' => '8px',
        '--radius-badge' => '999px',
        '--radius-card' => '12px',
        '--radius-field' => '8px',
        '--radius-pill' => '999px',
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
        '--color-border' => '#31212a',
        '--color-border-strong' => '#452e3a',
        '--color-cell-line' => '#31212a',
        '--color-footer' => '#170e13',
        '--color-footer-border' => '#31212a',
        '--color-footer-ink' => '#bb9aa6',
        '--color-ink' => '#f7eaf0',
        '--color-ink-body' => '#f7eaf0',
        '--color-ink-muted' => '#bb9aa6',
        '--color-link' => '#f08bb0',
        '--color-row-alt' => '#1b1116',
        '--color-sidebar' => '#140c11',
        '--color-sidebar-active' => '#c2185b',
        '--color-sidebar-border' => '#31212a',
        '--color-sidebar-hover' => '#1e293b',
        '--color-sidebar-icon' => '#bb9aa6',
        '--color-sidebar-panel' => '#1a1015',
        '--color-surface-app' => '#170e13',
        '--color-surface-card' => '#1f141a',
        '--color-surface-hover' => '#271923',
        '--color-surface-muted' => '#1b1116',
        '--color-surface-selected' => '#33202c',
        '--color-surface-sunken' => '#1b1116',
        '--color-table-head' => '#1b1116',
        '--color-table-head-ink' => '#bb9aa6',
        '--color-toolbar' => '#1f141a',
        '--color-topbar' => '#1f141a',
        '--color-topbar-border' => '#31212a',
        '--color-topbar-field' => '#271923',
        '--color-topbar-hover' => '#271923',
        '--color-topbar-ink' => '#f7eaf0',
        '--color-topbar-ink-muted' => '#bb9aa6',
        '--color-topnav' => '#1a1015',
        '--color-topnav-border' => '#31212a',
        '--color-topnav-hover' => '#271923',
        '--color-topnav-ink' => '#f7eaf0',
        '--color-topnav-ink-muted' => '#bb9aa6',
        '--color-topnav-selected' => '#33202c',
    ],
];
