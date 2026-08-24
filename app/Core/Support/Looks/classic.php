<?php

declare(strict_types=1);

/*
 * Classic — খতিয়ান রূপ — উপরে টানা মেনু, ডোরাকাটা সারি, অ্যাম্বার।
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
        '--cmd-font' => '12px',
        '--color-badge-danger-bg' => '#f8e9e6',
        '--color-badge-danger-ink' => '#a32a18',
        '--color-badge-draft-bg' => '#efebe2',
        '--color-badge-draft-ink' => '#5c574c',
        '--color-badge-info-bg' => '#e8eef6',
        '--color-badge-info-ink' => '#1d4e89',
        '--color-badge-pending-bg' => '#fbf1dc',
        '--color-badge-pending-ink' => '#9a5b00',
        '--color-badge-success-bg' => '#eaf2ec',
        '--color-badge-success-ink' => '#1b6e3c',
        '--color-badge-warning-bg' => '#fbf1dc',
        '--color-badge-warning-ink' => '#9a5b00',
        '--color-border' => '#c9c2b4',
        '--color-border-strong' => '#a79e8c',
        '--color-cell-line' => '#efece4',
        '--color-ink' => '#1a1a1a',
        '--color-ink-body' => '#1a1a1a',
        '--color-ink-muted' => '#5c574c',
        '--color-link' => '#1d4e89',
        '--color-row-alt' => '#fbfaf6',
        '--color-section-head' => '#efebe2',
        '--color-sidebar' => '#23303c',
        '--color-sidebar-active' => '#e08c1a',
        '--color-sidebar-border' => '#1a242e',
        '--color-sidebar-hover' => '#415264',
        '--color-sidebar-icon' => '#dde4ea',
        '--color-sidebar-panel' => '#f5f2ec',
        '--color-state-off' => '#8c8778',
        '--color-state-on' => '#1b6e3c',
        '--color-surface-app' => '#edeae3',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#fdf3df',
        '--color-surface-muted' => '#efebe2',
        '--color-surface-selected' => '#f5f2ec',
        '--color-surface-sunken' => '#efebe2',
        '--color-table-head' => '#5e6b78',
        '--color-table-head-ink' => '#ffffff',
        '--color-toolbar' => '#f5f2ec',
        '--color-topbar' => '#23303c',
        '--color-topbar-border' => '#1a242e',
        '--color-topbar-field' => '#33424f',
        '--color-topbar-hover' => '#33424f',
        '--color-topbar-ink' => '#e9edf1',
        '--color-topbar-ink-muted' => '#b9c4cf',
        '--color-topnav' => '#33424f',
        '--color-topnav-border' => '#23303c',
        '--color-topnav-hover' => '#415264',
        '--color-topnav-ink' => '#1a1a1a',
        '--color-topnav-ink-muted' => '#dde4ea',
        '--color-topnav-selected' => '#e08c1a',
        '--font-size-table' => '12.5px',
        '--grid-pad-x' => '10px',
        '--grid-pad-y' => '4px',
        '--grid-pad-y-tight' => '3px',
        '--lines-pad' => '6px',
        '--lines-pad-input' => '3px',
        '--radius-badge' => '2px',
        '--radius-card' => '0',
        '--radius-field' => '2px',
        '--radius-pill' => '2px',
        '--row-height' => '26px',
        '--row-height-dense' => '24px',
        '--spacing-header' => '34px',
        '--topbar-control-h' => '26px',
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
        '--color-border' => '#2c343d',
        '--color-border-strong' => '#3d4752',
        '--color-cell-line' => '#2c343d',
        '--color-footer' => '#171c22',
        '--color-footer-border' => '#2c343d',
        '--color-footer-ink' => '#a1a9b3',
        '--color-ink' => '#e8e4da',
        '--color-ink-body' => '#e8e4da',
        '--color-ink-muted' => '#a1a9b3',
        '--color-link' => '#79b4f0',
        '--color-row-alt' => '#1a2027',
        '--color-sidebar' => '#12161b',
        '--color-sidebar-active' => '#e08c1a',
        '--color-sidebar-border' => '#2c343d',
        '--color-sidebar-icon' => '#a1a9b3',
        '--color-sidebar-panel' => '#1a2027',
        '--color-surface-app' => '#171c22',
        '--color-surface-card' => '#1e242c',
        '--color-surface-hover' => '#262e37',
        '--color-surface-muted' => '#1a2027',
        '--color-surface-selected' => '#33291a',
        '--color-surface-sunken' => '#1a2027',
        '--color-table-head' => '#1a2027',
        '--color-table-head-ink' => '#a1a9b3',
        '--color-toolbar' => '#1e242c',
        '--color-topbar' => '#1a242e',
        '--color-topbar-border' => '#2c343d',
        '--color-topbar-field' => '#262e37',
        '--color-topbar-hover' => '#262e37',
        '--color-topbar-ink' => '#e8e4da',
        '--color-topbar-ink-muted' => '#a1a9b3',
        '--color-topnav' => '#23303c',
        '--color-topnav-border' => '#2c343d',
        '--color-topnav-hover' => '#33424f',
        '--color-topnav-ink' => '#1a1a1a',
        '--color-topnav-ink-muted' => '#dde4ea',
        '--color-topnav-selected' => '#e08c1a',
    ],
];
