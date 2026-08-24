<?php

declare(strict_types=1);

/*
 * Suite — Oracle NetSuite — নেভি হেডার, সরু লেখা, এক পর্দায় অনেক।
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
        '--cmd-font' => '11px',
        '--cmd-gradient' => 'linear-gradient(#fdfdfd, #e4e8ec)',
        '--cmd-gradient-primary' => 'linear-gradient(#4e7ba6, #2e4b6b)',
        '--color-badge-danger-bg' => '#f8e8e6',
        '--color-badge-danger-ink' => '#b02a1b',
        '--color-badge-pending-bg' => '#fbf2df',
        '--color-badge-pending-ink' => '#9a6100',
        '--color-badge-success-bg' => '#e6f2ea',
        '--color-badge-success-ink' => '#1a7a3c',
        '--color-badge-warning-bg' => '#fbf2df',
        '--color-badge-warning-ink' => '#9a6100',
        '--color-border' => '#c3ccd5',
        '--color-border-strong' => '#a9b6c2',
        '--color-cell-line' => '#edeff2',
        '--color-ink' => '#333333',
        '--color-ink-body' => '#333333',
        '--color-ink-muted' => '#666666',
        '--color-link' => '#1b62a5',
        '--color-row-alt' => '#f5f7f9',
        '--color-sidebar' => '#2e4b6b',
        '--color-sidebar-active' => '#1b62a5',
        '--color-sidebar-border' => '#24405e',
        '--color-sidebar-hover' => '#4e7ba6',
        '--color-sidebar-icon' => '#d9e1e9',
        '--color-sidebar-panel' => '#e4e9ee',
        '--color-state-on' => '#1a7a3c',
        '--color-surface-app' => '#f0f0f0',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#fff8dc',
        '--color-surface-muted' => '#e4e9ee',
        '--color-surface-selected' => '#d9e1e9',
        '--color-surface-sunken' => '#e4e9ee',
        '--color-table-head' => '#d9e1e9',
        '--color-table-head-ink' => '#22303f',
        '--color-toolbar' => '#e4e9ee',
        '--color-topbar' => '#2e4b6b',
        '--color-topbar-border' => '#24405e',
        '--color-topbar-field' => '#24405e',
        '--color-topbar-hover' => '#4e7ba6',
        '--color-topbar-ink' => '#ffffff',
        '--color-topbar-ink-muted' => '#c6d4e2',
        '--color-topnav' => '#3c5f84',
        '--color-topnav-border' => '#24405e',
        '--color-topnav-hover' => '#4e7ba6',
        '--color-topnav-ink' => '#2e4b6b',
        '--color-topnav-ink-muted' => '#eaf0f6',
        '--color-topnav-selected' => '#f0f0f0',
        '--font-size-table' => '11px',
        '--grid-pad-x' => '8px',
        '--grid-pad-y' => '3px',
        '--grid-pad-y-head' => '4px',
        '--lines-pad' => '6px',
        '--lines-pad-input' => '2px',
        '--radius-badge' => '2px',
        '--radius-card' => '0',
        '--radius-field' => '2px',
        '--radius-pill' => '2px',
        '--row-height' => '24px',
        '--row-height-dense' => '22px',
        '--spacing-command' => '22px',
        '--spacing-header' => '36px',
        '--topbar-control-h' => '28px',
        '--topbar-label' => 'none',
        '--topnav-caret' => '""',
    ],

    /*
     * গাঢ় থিমে এই রূপের নিজের মান।
     *
     * খালি থাকলে রূপটা গাঢ় থিমের সাধারণ মানই নেয়। আজ দশটারই
     * নিজের গাঢ় রূপ আছে — SAP Horizon Dark, Fluent 2 Dark ও
     * Salesforce Dark-এর নিজের ধাপ ধরে।
     */
    'dark' => [
        '--color-border' => '#31424f',
        '--color-border-strong' => '#42566a',
        '--color-cell-line' => '#31424f',
        '--color-footer' => '#141d26',
        '--color-footer-border' => '#31424f',
        '--color-footer-ink' => '#a0aebc',
        '--color-ink' => '#e3e9ef',
        '--color-ink-body' => '#e3e9ef',
        '--color-ink-muted' => '#a0aebc',
        '--color-link' => '#77b6ee',
        '--color-row-alt' => '#18222c',
        '--color-sidebar' => '#101820',
        '--color-sidebar-active' => '#1b62a5',
        '--color-sidebar-border' => '#31424f',
        '--color-sidebar-icon' => '#a0aebc',
        '--color-sidebar-panel' => '#18222c',
        '--color-surface-app' => '#141d26',
        '--color-surface-card' => '#1e2a36',
        '--color-surface-hover' => '#26343f',
        '--color-surface-muted' => '#18222c',
        '--color-surface-selected' => '#1f3a52',
        '--color-surface-sunken' => '#18222c',
        '--color-table-head' => '#18222c',
        '--color-table-head-ink' => '#a0aebc',
        '--color-toolbar' => '#1e2a36',
        '--color-topbar' => '#16202b',
        '--color-topbar-border' => '#31424f',
        '--color-topbar-field' => '#26343f',
        '--color-topbar-hover' => '#26343f',
        '--color-topbar-ink' => '#e3e9ef',
        '--color-topbar-ink-muted' => '#a0aebc',
        '--color-topnav' => '#18222c',
        '--color-topnav-border' => '#31424f',
        '--color-topnav-hover' => '#26343f',
        '--color-topnav-ink' => '#e3e9ef',
        '--color-topnav-ink-muted' => '#a0aebc',
        '--color-topnav-selected' => '#1f3a52',
    ],
];
