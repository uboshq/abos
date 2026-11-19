<?php

declare(strict_types=1);

/*
 * Dynamic — Microsoft Dynamics ৩৬৫ — উপরে কমান্ড বার, ঘন গ্রিড।
 *
 * ⭐ ১৯ নভেম্বর ২০২৬ (মালিক, "ok final"): রং Business Central-এর — কালো অ্যাপ-বার #1e1e1e, টিয়া লিংক #00788a,
 * চৌকো কোণ, ১৪px ঘন সারি, বাছা সারি #b3e5ec। খোলসের গড়ন (মেনুর সারি, ফাস্ট-ট্যাব, রোল সেন্টার) `resources/css/dynamic-look.css`-এ।
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
        '--color-border' => '#dddddd',
        '--color-border-strong' => '#bbbbbb',
        '--color-cell-line' => 'transparent',
        '--color-ink' => '#212121',
        '--color-ink-body' => '#212121',
        '--color-ink-muted' => '#505050',
        '--color-link' => '#00788a',
        '--color-sidebar' => '#0b2a4a',
        '--color-sidebar-active' => '#0b2a4a',
        '--color-sidebar-border' => '#082033',
        '--color-sidebar-hover' => '#17415f',
        '--color-sidebar-icon' => '#d6e4ef',
        '--color-sidebar-panel' => '#f5f5f5',
        '--color-state-on' => '#107c10',
        '--color-surface-app' => '#ffffff',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#f2f2f2',
        '--color-surface-muted' => '#f5f5f5',
        '--color-surface-selected' => '#b3e5ec',
        '--color-surface-sunken' => '#f8f8f8',
        '--color-table-head' => '#ffffff',
        '--color-table-head-ink' => '#505050',
        '--color-toolbar' => '#ffffff',
        '--color-topbar' => '#1e1e1e',
        '--color-topbar-border' => '#1e1e1e',
        '--color-topbar-field' => '#2d2d2d',
        '--color-topbar-hover' => '#3b3b3b',
        '--color-topbar-ink' => '#ffffff',
        '--color-topbar-ink-muted' => '#d6d6d6',
        '--color-topnav' => '#ffffff',
        '--color-topnav-border' => '#e1e1e1',
        '--color-topnav-hover' => '#f3f2f1',
        '--color-topnav-ink' => '#161a1f',
        '--color-topnav-ink-muted' => '#616161',
        '--color-topnav-selected' => '#edebe9',
        '--font-size-table' => '14px',
        '--grid-pad-x' => '8px',
        '--grid-pad-y' => '8px',
        '--radius-badge' => '2px',
        '--radius-card' => '0px',
        '--radius-field' => '0px',
        '--radius-pill' => '2px',
        '--row-height' => '36px',
        '--row-height-dense' => '34px',
        '--spacing-header' => '48px',
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
        '--color-link' => '#4cc2d1',
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
        '--color-topbar' => '#1e1e1e',
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
