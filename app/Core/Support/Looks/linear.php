<?php

declare(strict_types=1);

/*
 * Linear — Linear — প্রায়-কালো, একটাই ইন্ডিগো, খোলস প্রায় নেই।
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

        '--color-badge-danger-bg' => '#33191b',
        '--color-badge-danger-ink' => '#f78c8c',
        '--color-badge-draft-bg' => '#1c1d21',
        '--color-badge-draft-ink' => '#b0b4bb',
        '--color-badge-info-bg' => '#1a1d38',
        '--color-badge-info-ink' => '#a5b0f2',
        '--color-badge-inventory-bg' => '#10262b',
        '--color-badge-inventory-ink' => '#6fd6e4',
        '--color-badge-pending-bg' => '#2f2510',
        '--color-badge-pending-ink' => '#fbbf4a',
        '--color-badge-success-bg' => '#122c1d',
        '--color-badge-success-ink' => '#6ee7a0',
        '--color-badge-warning-bg' => '#2f2510',
        '--color-badge-warning-ink' => '#fbbf4a',
        '--color-border' => '#26272b',
        '--color-border-strong' => '#35363b',
        '--color-cell-line' => '#26272b',
        '--color-footer' => '#0e0f11',
        '--color-footer-border' => '#26272b',
        '--color-footer-ink' => '#8a8f98',
        '--color-ink' => '#eeeef0',
        '--color-ink-body' => '#eeeef0',
        '--color-ink-muted' => '#8a8f98',
        '--color-link' => '#98a0e8',
        '--color-module-accounts' => '#2a2b30',
        '--color-module-approval' => '#2a2b30',
        '--color-module-customer' => '#2a2b30',
        '--color-module-dashboard' => '#2a2b30',
        '--color-module-governance' => '#2a2b30',
        '--color-module-hr' => '#2a2b30',
        '--color-module-inventory' => '#2a2b30',
        '--color-module-purchase' => '#2a2b30',
        '--color-module-reports' => '#2a2b30',
        '--color-module-sales' => '#2a2b30',
        '--color-module-supplier' => '#2a2b30',
        '--color-row-alt' => 'transparent',
        '--color-section-head' => 'transparent',
        '--color-sidebar' => '#0e0f11',
        '--color-sidebar-active' => '#5e6ad2',
        '--color-sidebar-border' => '#26272b',
        '--color-sidebar-hover' => '#1c1d21',
        '--color-sidebar-icon' => '#8a8f98',
        '--color-sidebar-panel' => '#0e0f11',
        '--color-state-on' => '#6ee7a0',
        '--color-surface-app' => '#0e0f11',
        '--color-surface-card' => '#17181b',
        '--color-surface-hover' => '#1c1d21',
        '--color-surface-muted' => '#1c1d21',
        '--color-surface-selected' => '#22232b',
        '--color-surface-sunken' => '#1c1d21',
        '--color-table-head' => 'transparent',
        '--color-table-head-ink' => '#8a8f98',
        '--color-toolbar' => '#0e0f11',
        '--color-topbar' => '#0e0f11',
        '--color-topbar-border' => 'transparent',
        '--color-topbar-field' => '#1c1d21',
        '--color-topbar-hover' => '#1c1d21',
        '--color-topbar-ink' => '#eeeef0',
        '--color-topbar-ink-muted' => '#8a8f98',
        '--color-topnav' => '#0e0f11',
        '--color-topnav-border' => 'transparent',
        '--color-topnav-hover' => '#1c1d21',
        '--color-topnav-ink' => '#eeeef0',
        '--color-topnav-ink-muted' => '#8a8f98',
        '--color-topnav-selected' => '#22232b',
        '--font-size-table' => '13px',
        '--grid-pad-x' => '10px',
        '--grid-pad-y' => '6px',
        '--radius-badge' => '6px',
        '--radius-card' => '8px',
        '--radius-field' => '6px',
        '--radius-pill' => '999px',
        '--rail-item-radius' => '6px',
        '--rail-item-shadow' => 'none',
        '--row-height' => '36px',
        '--row-height-dense' => '32px',
        '--spacing-header' => '48px',
        '--topbar-control-h' => '28px',
    ],

    /*
     * গাঢ় থিমে এই রূপের নিজের মান।
     *
     * খালি থাকলে রূপটা গাঢ় থিমের সাধারণ মানই নেয়। আজ দশটারই
     * নিজের গাঢ় রূপ আছে — SAP Horizon Dark, Fluent 2 Dark ও
     * Salesforce Dark-এর নিজের ধাপ ধরে।
     */
    'dark' => [
        '--color-border' => '#26272b',
        '--color-border-strong' => '#35363b',
        '--color-footer' => '#0e0f11',
        '--color-ink' => '#eeeef0',
        '--color-ink-body' => '#eeeef0',
        '--color-ink-muted' => '#8a8f98',
        '--color-sidebar' => '#0e0f11',
        '--color-sidebar-panel' => '#0e0f11',
        '--color-surface-app' => '#0e0f11',
        '--color-surface-card' => '#17181b',
        '--color-surface-hover' => '#1c1d21',
        '--color-surface-muted' => '#1c1d21',
        '--color-surface-selected' => '#22232b',
        '--color-surface-sunken' => '#1c1d21',
        '--color-topbar' => '#0e0f11',
        '--color-topbar-ink' => '#eeeef0',
    ],
];
