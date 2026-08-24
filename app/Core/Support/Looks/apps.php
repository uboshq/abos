<?php

declare(strict_types=1);

/*
 * Apps — Odoo — অবার্জিন মাথা, খোলা তালিকা, নরম কোণ।
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
        '--color-avatar' => '#f5eff3',
        '--color-avatar-ink' => '#714b67',
        '--color-badge-danger-bg' => '#f9e8e5',
        '--color-badge-danger-ink' => '#a32a18',
        '--color-badge-pending-bg' => '#fbf0dc',
        '--color-badge-pending-ink' => '#8a6100',
        '--color-badge-success-bg' => '#e7f4ec',
        '--color-badge-success-ink' => '#1b7a47',
        '--color-badge-warning-bg' => '#fbf0dc',
        '--color-badge-warning-ink' => '#8a6100',
        '--color-border' => '#e5dee3',
        '--color-border-strong' => '#dfcfda',
        '--color-cell-line' => 'transparent',
        '--color-footer' => '#fbf9fb',
        '--color-footer-border' => '#e5dee3',
        '--color-footer-ink' => '#8a7f90',
        '--color-ink' => '#1f1b24',
        '--color-ink-body' => '#1f1b24',
        '--color-ink-muted' => '#8a7f90',
        '--color-link' => '#5b3c54',
        '--color-sidebar' => '#714b67',
        '--color-sidebar-active' => '#5b3c54',
        '--color-sidebar-border' => '#5c3d53',
        '--color-sidebar-hover' => '#8a5e7e',
        '--color-sidebar-icon' => '#e7dbe4',
        '--color-sidebar-panel' => '#fbf9fb',
        '--color-state-on' => '#1b7a47',
        '--color-surface-app' => '#ffffff',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#fbf9fb',
        '--color-surface-muted' => '#fbf9fb',
        '--color-surface-selected' => '#f3edf2',
        '--color-surface-sunken' => '#ffffff',
        '--color-table-head' => '#ffffff',
        '--color-table-head-ink' => '#8a7f90',
        '--color-toolbar' => '#ffffff',
        '--color-topbar' => '#714b67',
        '--color-topbar-border' => '#5c3d53',
        '--color-topbar-field' => '#5c3d53',
        '--color-topbar-hover' => '#8a5e7e',
        '--color-topbar-ink' => '#f5eff3',
        '--color-topbar-ink-muted' => '#e0cfda',
        '--color-topnav' => '#ffffff',
        '--color-topnav-border' => '#e5dee3',
        '--color-topnav-hover' => '#fbf9fb',
        '--color-topnav-ink' => '#1f1b24',
        '--color-topnav-ink-muted' => '#8a7f90',
        '--color-topnav-selected' => '#f3edf2',
        '--font-size-table' => '13px',
        '--grid-pad-x' => '12px',
        '--grid-pad-y' => '8px',
        '--grid-pad-y-head' => '6px',
        '--radius-badge' => '999px',
        '--radius-card' => '6px',
        '--radius-field' => '6px',
        '--radius-pill' => '999px',
        '--row-height' => '44px',
        '--row-height-dense' => '36px',
        '--spacing-header' => '46px',
        '--table-head-spacing' => '0.05em',
        '--table-head-transform' => 'uppercase',
        '--topbar-control-h' => '32px',
        '--topbar-label' => 'none',
    ],

    /*
     * গাঢ় থিমে এই রূপের নিজের মান।
     *
     * খালি থাকলে রূপটা গাঢ় থিমের সাধারণ মানই নেয়। আজ দশটারই
     * নিজের গাঢ় রূপ আছে — SAP Horizon Dark, Fluent 2 Dark ও
     * Salesforce Dark-এর নিজের ধাপ ধরে।
     */
    'dark' => [
        '--color-border' => '#39323a',
        '--color-border-strong' => '#4c424d',
        '--color-cell-line' => '#39323a',
        '--color-footer' => '#1b171a',
        '--color-footer-border' => '#39323a',
        '--color-footer-ink' => '#b0a4ae',
        '--color-ink' => '#e9e4e9',
        '--color-ink-body' => '#e9e4e9',
        '--color-ink-muted' => '#b0a4ae',
        '--color-link' => '#d8a7c8',
        '--color-row-alt' => '#201c20',
        '--color-sidebar' => '#171316',
        '--color-sidebar-active' => '#714b67',
        '--color-sidebar-border' => '#39323a',
        '--color-sidebar-icon' => '#b0a4ae',
        '--color-sidebar-panel' => '#201c20',
        '--color-surface-app' => '#1b171a',
        '--color-surface-card' => '#242024',
        '--color-surface-hover' => '#2d272d',
        '--color-surface-muted' => '#201c20',
        '--color-surface-selected' => '#332931',
        '--color-surface-sunken' => '#201c20',
        '--color-table-head' => '#201c20',
        '--color-table-head-ink' => '#b0a4ae',
        '--color-toolbar' => '#242024',
        '--color-topbar' => '#714b67',
        '--color-topbar-border' => '#39323a',
        '--color-topbar-field' => '#2d272d',
        '--color-topbar-hover' => '#2d272d',
        '--color-topbar-ink' => '#ffffff',
        '--color-topbar-ink-muted' => '#b0a4ae',
        '--color-topnav' => '#242024',
        '--color-topnav-border' => '#39323a',
        '--color-topnav-hover' => '#2d272d',
        '--color-topnav-ink' => '#e9e4e9',
        '--color-topnav-ink-muted' => '#b0a4ae',
        '--color-topnav-selected' => '#332931',
    ],
];
