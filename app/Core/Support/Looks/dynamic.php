<?php

declare(strict_types=1);

/*
 * Dynamic — Microsoft Dynamics ৩৬৫ — উপরে কমান্ড বার, ঘন গ্রিড।
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
        '--color-badge-danger-bg' => '#fde7e9',
        '--color-badge-danger-ink' => '#a4262c',
        '--color-badge-pending-bg' => '#fbf1e0',
        '--color-badge-pending-ink' => '#8a5a00',
        '--color-badge-success-bg' => '#e7f3e7',
        '--color-badge-success-ink' => '#107c10',
        '--color-badge-warning-bg' => '#fbf1e0',
        '--color-badge-warning-ink' => '#8a5a00',
        '--color-border' => '#e1e1e1',
        '--color-border-strong' => '#c8c6c4',
        '--color-cell-line' => 'transparent',
        '--color-ink' => '#161a1f',
        '--color-ink-body' => '#3b3a39',
        '--color-ink-muted' => '#616161',
        '--color-link' => '#0f6cbd',
        '--color-sidebar' => '#0b2a4a',
        '--color-sidebar-active' => '#0b2a4a',
        '--color-sidebar-border' => '#082033',
        '--color-sidebar-hover' => '#17415f',
        '--color-sidebar-icon' => '#d6e4ef',
        '--color-sidebar-panel' => '#f5f5f5',
        '--color-state-on' => '#107c10',
        '--color-surface-app' => '#ffffff',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#f3f9fd',
        '--color-surface-muted' => '#f5f5f5',
        '--color-surface-selected' => '#edebe9',
        '--color-surface-sunken' => '#faf9f8',
        '--color-table-head' => '#ffffff',
        '--color-table-head-ink' => '#3b3a39',
        '--color-toolbar' => '#ffffff',
        '--color-topbar' => '#0b2a4a',
        '--color-topbar-border' => '#082033',
        '--color-topbar-field' => '#082033',
        '--color-topbar-hover' => '#17415f',
        '--color-topbar-ink' => '#ffffff',
        '--color-topbar-ink-muted' => '#c6d8e6',
        '--color-topnav' => '#ffffff',
        '--color-topnav-border' => '#e1e1e1',
        '--color-topnav-hover' => '#f3f2f1',
        '--color-topnav-ink' => '#161a1f',
        '--color-topnav-ink-muted' => '#616161',
        '--color-topnav-selected' => '#edebe9',
        '--font-size-table' => '12.5px',
        '--grid-pad-x' => '12px',
        '--grid-pad-y' => '8px',
        '--radius-badge' => '2px',
        '--radius-card' => '2px',
        '--radius-field' => '2px',
        '--radius-pill' => '2px',
        '--row-height' => '44px',
        '--row-height-dense' => '36px',
        '--spacing-header' => '44px',
        '--stage-clip' => 'polygon(0 0, calc(100% - 12px) 0, 100% 50%, calc(100% - 12px) 100%, 0 100%, 12px 50%)',
        '--stage-overlap' => '-10px',
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
        '--color-border' => '#3d3d3d',
        '--color-border-strong' => '#525252',
        '--color-cell-line' => '#3d3d3d',
        '--color-footer' => '#141414',
        '--color-footer-border' => '#3d3d3d',
        '--color-footer-ink' => '#adadad',
        '--color-ink' => '#f5f5f5',
        '--color-ink-body' => '#f5f5f5',
        '--color-ink-muted' => '#adadad',
        '--color-link' => '#479ef5',
        '--color-row-alt' => '#1f1f1f',
        '--color-sidebar' => '#0f0f0f',
        '--color-sidebar-active' => '#0f6cbd',
        '--color-sidebar-border' => '#3d3d3d',
        '--color-sidebar-icon' => '#adadad',
        '--color-sidebar-panel' => '#1f1f1f',
        '--color-surface-app' => '#141414',
        '--color-surface-card' => '#292929',
        '--color-surface-hover' => '#333333',
        '--color-surface-muted' => '#1f1f1f',
        '--color-surface-selected' => '#12314f',
        '--color-surface-sunken' => '#1f1f1f',
        '--color-table-head' => '#1f1f1f',
        '--color-table-head-ink' => '#adadad',
        '--color-toolbar' => '#292929',
        '--color-topbar' => '#0b2a4a',
        '--color-topbar-border' => '#3d3d3d',
        '--color-topbar-field' => '#333333',
        '--color-topbar-hover' => '#333333',
        '--color-topbar-ink' => '#ffffff',
        '--color-topbar-ink-muted' => '#adadad',
        '--color-topnav' => '#1f1f1f',
        '--color-topnav-border' => '#3d3d3d',
        '--color-topnav-hover' => '#333333',
        '--color-topnav-ink' => '#f5f5f5',
        '--color-topnav-ink-muted' => '#adadad',
        '--color-topnav-selected' => '#12314f',
    ],
];
