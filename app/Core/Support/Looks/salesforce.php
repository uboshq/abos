<?php

declare(strict_types=1);

/*
 * Salesforce — Salesforce Lightning — দুই স্তরের মাথা, ৪px ধার।
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

        '--color-badge-danger-bg' => '#fdeaea',
        '--color-badge-danger-ink' => '#a4262c',
        '--color-badge-pending-bg' => '#fdf3e3',
        '--color-badge-pending-ink' => '#8c4b02',
        '--color-badge-success-bg' => '#e3f7e8',
        '--color-badge-success-ink' => '#196b3a',
        '--color-badge-warning-bg' => '#fdf3e3',
        '--color-badge-warning-ink' => '#8c4b02',
        '--color-border' => '#dddbda',
        '--color-border-strong' => '#c9c7c5',
        '--color-cell-line' => '#dddbda',
        '--color-footer' => '#032d60',
        '--color-footer-border' => 'rgb(0 0 0 / 0.25)',
        '--color-footer-ink' => 'rgb(255 255 255 / 0.75)',
        '--color-ink' => '#181818',
        '--color-ink-body' => '#181818',
        '--color-ink-muted' => '#706e6b',
        '--color-link' => '#0176d3',
        '--color-row-alt' => '#ffffff',
        '--color-section-head' => 'transparent',
        '--color-sidebar' => '#032d60',
        '--color-sidebar-active' => '#0176d3',
        '--color-sidebar-border' => 'rgb(0 0 0 / 0.25)',
        '--color-sidebar-hover' => 'rgb(255 255 255 / 0.1)',
        '--color-sidebar-icon' => 'rgb(255 255 255 / 0.75)',
        '--color-sidebar-panel' => '#032d60',
        '--color-state-on' => '#196b3a',
        '--color-surface-app' => '#f3f3f3',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#f3f9fe',
        '--color-surface-muted' => '#f2f2f2',
        '--color-surface-selected' => '#ecf5fd',
        '--color-surface-sunken' => '#f2f2f2',
        '--color-table-head' => '#fafaf9',
        '--color-table-head-ink' => '#514f4d',
        '--color-toolbar' => '#ffffff',
        '--color-topbar' => '#032d60',
        '--color-topbar-border' => 'rgb(0 0 0 / 0.2)',
        '--color-topbar-field' => 'rgb(255 255 255 / 0.14)',
        '--color-topbar-hover' => 'rgb(255 255 255 / 0.14)',
        '--color-topbar-ink' => '#ffffff',
        '--color-topbar-ink-muted' => 'rgb(255 255 255 / 0.75)',
        '--color-topnav' => '#ffffff',
        '--color-topnav-border' => '#dddbda',
        '--color-topnav-hover' => '#f3f3f3',
        '--color-topnav-ink' => '#181818',
        '--color-topnav-ink-muted' => '#706e6b',
        '--color-topnav-selected' => '#ecf5fd',
        '--font-size-table' => '13px',
        '--grid-pad-x' => '12px',
        '--grid-pad-y' => '8px',
        '--radius-badge' => '4px',
        '--radius-card' => '4px',
        '--radius-field' => '4px',
        '--radius-pill' => '999px',
        '--row-height' => '36px',
        '--row-height-dense' => '30px',
        '--spacing-header' => '48px',
        '--topbar-control-h' => '32px',
        '--topnav-caret' => 'none',
    ],

    /*
     * গাঢ় থিমে এই রূপের নিজের মান।
     *
     * খালি থাকলে রূপটা গাঢ় থিমের সাধারণ মানই নেয়। আজ দশটারই
     * নিজের গাঢ় রূপ আছে — SAP Horizon Dark, Fluent 2 Dark ও
     * Salesforce Dark-এর নিজের ধাপ ধরে।
     */
    'dark' => [
        '--color-border' => '#3e3e3c',
        '--color-border-strong' => '#514f4d',
        '--color-cell-line' => '#3e3e3c',
        '--color-footer' => '#032d60',
        '--color-footer-border' => '#3e3e3c',
        '--color-footer-ink' => '#b0b0ae',
        '--color-ink' => '#f3f3f3',
        '--color-ink-body' => '#f3f3f3',
        '--color-ink-muted' => '#b0b0ae',
        '--color-link' => '#57a3fd',
        '--color-row-alt' => '#1f1f1f',
        '--color-sidebar' => '#032d60',
        '--color-sidebar-active' => '#0176d3',
        '--color-sidebar-border' => '#3e3e3c',
        '--color-sidebar-icon' => '#b0b0ae',
        '--color-sidebar-panel' => '#1f1f1f',
        '--color-surface-app' => '#181818',
        '--color-surface-card' => '#242424',
        '--color-surface-hover' => '#2e2e2e',
        '--color-surface-muted' => '#1f1f1f',
        '--color-surface-selected' => '#10314f',
        '--color-surface-sunken' => '#1f1f1f',
        '--color-table-head' => '#1f1f1f',
        '--color-table-head-ink' => '#b0b0ae',
        '--color-toolbar' => '#242424',
        '--color-topbar' => '#032d60',
        '--color-topbar-border' => '#3e3e3c',
        '--color-topbar-field' => '#2e2e2e',
        '--color-topbar-hover' => '#2e2e2e',
        '--color-topbar-ink' => '#ffffff',
        '--color-topbar-ink-muted' => '#b0b0ae',
        '--color-topnav' => '#242424',
        '--color-topnav-border' => '#3e3e3c',
        '--color-topnav-hover' => '#2e2e2e',
        '--color-topnav-ink' => '#f3f3f3',
        '--color-topnav-ink-muted' => '#b0b0ae',
        '--color-topnav-selected' => '#10314f',
    ],
];
