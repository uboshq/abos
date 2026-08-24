<?php

declare(strict_types=1);

/*
 * Tiles — SAP Fiori ৩ — শেল বার, লঞ্চপ্যাডের টালি, ঠান্ডা নীল।
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

        '--color-badge-danger-bg' => '#fbe6e6',
        '--color-badge-danger-ink' => '#bb0000',
        '--color-badge-pending-bg' => '#fdf0e3',
        '--color-badge-pending-ink' => '#b45c07',
        '--color-badge-success-bg' => '#e8f5ec',
        '--color-badge-success-ink' => '#107e3e',
        '--color-badge-warning-bg' => '#fdf0e3',
        '--color-badge-warning-ink' => '#b45c07',
        '--color-border' => '#e5e5e5',
        '--color-border-strong' => '#c8cdd3',
        '--color-cell-line' => 'transparent',
        '--color-footer' => '#f5f6f7',
        '--color-footer-border' => '#e5e5e5',
        '--color-footer-ink' => '#6a6d70',
        '--color-ink' => '#32363a',
        '--color-ink-body' => '#32363a',
        '--color-ink-muted' => '#6a6d70',
        '--color-link' => '#0a6ed1',
        '--color-sidebar' => '#354a5f',
        '--color-sidebar-active' => '#0a6ed1',
        '--color-sidebar-border' => '#2b3c4d',
        '--color-sidebar-hover' => '#46617c',
        '--color-sidebar-icon' => '#d9e2ea',
        '--color-sidebar-panel' => '#f5f6f7',
        '--color-state-on' => '#107e3e',
        '--color-surface-app' => '#f5f6f7',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#eaf2fb',
        '--color-surface-muted' => '#f5f6f7',
        '--color-surface-selected' => '#eaf2fb',
        '--color-surface-sunken' => '#f5f6f7',
        '--color-table-head' => '#f5f6f7',
        '--color-table-head-ink' => '#6a6d70',
        '--color-toolbar' => '#ffffff',
        '--color-topbar' => '#354a5f',
        '--color-topbar-border' => '#2b3c4d',
        '--color-topbar-field' => '#2b3c4d',
        '--color-topbar-hover' => '#46617c',
        '--color-topbar-ink' => '#ffffff',
        '--color-topbar-ink-muted' => '#c8d4de',
        '--color-topnav' => '#ffffff',
        '--color-topnav-border' => '#e5e5e5',
        '--color-topnav-hover' => '#eaf2fb',
        '--color-topnav-ink' => '#32363a',
        '--color-topnav-ink-muted' => '#6a6d70',
        '--color-topnav-selected' => '#eaf2fb',
        '--font-size-table' => '13px',
        '--grid-pad-x' => '12px',
        '--grid-pad-y' => '7px',
        '--radius-badge' => '4px',
        '--radius-card' => '4px',
        '--radius-field' => '4px',
        '--radius-pill' => '4px',
        '--row-height' => '32px',
        '--row-height-dense' => '28px',
        '--spacing-header' => '44px',
        '--stage-gap' => '8px',
        '--stage-tile' => '1',
        '--state-fill' => '0',
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
        '--color-border' => '#3a4149',
        '--color-border-strong' => '#4a525b',
        '--color-cell-line' => '#3a4149',
        '--color-footer' => '#12171c',
        '--color-footer-border' => '#3a4149',
        '--color-footer-ink' => '#a9b4be',
        '--color-ink' => '#eaecee',
        '--color-ink-body' => '#eaecee',
        '--color-ink-muted' => '#a9b4be',
        '--color-link' => '#7ab8ff',
        '--color-row-alt' => '#1d232a',
        '--color-sidebar' => '#12171c',
        '--color-sidebar-active' => '#0a6ed1',
        '--color-sidebar-border' => '#3a4149',
        '--color-sidebar-icon' => '#a9b4be',
        '--color-sidebar-panel' => '#1d232a',
        '--color-surface-app' => '#12171c',
        '--color-surface-card' => '#2b3138',
        '--color-surface-hover' => '#333a42',
        '--color-surface-muted' => '#1d232a',
        '--color-surface-selected' => '#22344a',
        '--color-surface-sunken' => '#1d232a',
        '--color-table-head' => '#1d232a',
        '--color-table-head-ink' => '#a9b4be',
        '--color-toolbar' => '#2b3138',
        '--color-topbar' => '#1d232a',
        '--color-topbar-border' => '#3a4149',
        '--color-topbar-field' => '#333a42',
        '--color-topbar-hover' => '#333a42',
        '--color-topbar-ink' => '#eaecee',
        '--color-topbar-ink-muted' => '#a9b4be',
        '--color-topnav' => '#1d232a',
        '--color-topnav-border' => '#3a4149',
        '--color-topnav-hover' => '#333a42',
        '--color-topnav-ink' => '#eaecee',
        '--color-topnav-ink-muted' => '#a9b4be',
        '--color-topnav-selected' => '#22344a',
    ],
];
