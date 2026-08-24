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
        '--color-border' => '#e5e7eb',
        '--color-border-strong' => '#cbd5e1',
        '--color-cell-line' => 'transparent',
        '--color-ink' => '#0f172a',
        '--color-ink-body' => '#0f172a',
        '--color-ink-muted' => '#64748b',
        '--color-link' => '#1565c0',
        '--color-sidebar' => '#08132b',
        '--color-sidebar-active' => '#f5b800',
        '--color-sidebar-border' => '#0e1c3a',
        '--color-sidebar-hover' => '#0e1c3a',
        '--color-sidebar-icon' => '#94a3b8',
        '--color-sidebar-panel' => '#f2f6fc',
        '--color-state-on' => '#047857',
        '--color-surface-app' => '#f2f6fc',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#f2f6fc',
        '--color-surface-muted' => '#f8fafc',
        '--color-surface-selected' => '#e2ecfa',
        '--color-surface-sunken' => '#f8fafc',
        '--color-table-head' => '#f8fafc',
        '--color-table-head-ink' => '#475569',
        '--color-toolbar' => '#ffffff',
        '--color-topbar' => '#ffffff',
        '--color-topbar-border' => '#e5e7eb',
        '--color-topbar-field' => '#f8fafc',
        '--color-topbar-hover' => '#f2f6fc',
        '--color-topbar-ink' => '#0f172a',
        '--color-topbar-ink-muted' => '#475569',
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
        '--radius-card' => '8px',
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
