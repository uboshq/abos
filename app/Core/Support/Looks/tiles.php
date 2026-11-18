<?php

declare(strict_types=1);

/*
 * Tiles — SAP Fiori, থিম "Horizon" (২০২৪–২৬) — সাদা শেল বার, স্পেসের ট্যাব, সাদা গোল কার্ড, নীল #0070F2।
 *
 * ⭐ মালিক, ১৯ নভেম্বর ২০২৬: *"একদম 100% হুবহু কপি"*। মানগুলো আসল SAP-এর চালু নমুনা-অ্যাপের পর্দা থেকে পিক্সেল ধরে মাপা
 *   (ui5.sap.com — sap_horizon); গাঢ়টা "Evening Horizon"। গড়নের যা টোকেনে হয় না তা [[resources/css/fiori-look.css]]-এ।
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
        '--color-badge-danger-bg' => '#ffeaf4',
        '--color-badge-danger-ink' => '#aa0808',
        '--color-badge-pending-bg' => '#fff3b8',
        '--color-badge-pending-ink' => '#a93e00',
        '--color-badge-success-bg' => '#f5fae5',
        '--color-badge-success-ink' => '#256f3a',
        '--color-badge-warning-bg' => '#fff3b8',
        '--color-badge-warning-ink' => '#a93e00',
        '--color-border' => '#e5e5e5',
        '--color-border-strong' => '#bcc3ca',
        '--color-cell-line' => 'transparent',
        '--color-footer' => '#ffffff',
        '--color-footer-border' => '#d9dee3',
        '--color-footer-ink' => '#556b82',
        '--color-ink' => '#131e29',
        '--color-ink-body' => '#1d2d3e',
        '--color-ink-muted' => '#556b82',
        '--color-link' => '#0064d9',
        '--color-sidebar' => '#ffffff',
        '--color-sidebar-active' => '#0064d9',
        '--color-sidebar-border' => '#d9dee3',
        '--color-sidebar-hover' => '#eaecee',
        '--color-sidebar-icon' => '#1d2d3e',
        '--color-sidebar-panel' => '#ffffff',
        '--color-state-on' => '#30914c',
        '--color-surface-app' => '#f5f6f7',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#eaecee',
        '--color-surface-muted' => '#f5f6f7',
        '--color-surface-selected' => '#ebf8ff',
        '--color-surface-sunken' => '#f5f6f7',
        '--color-table-head' => '#ffffff',
        '--color-table-head-ink' => '#131e29',
        '--color-toolbar' => '#ffffff',
        '--color-topbar' => '#ffffff',
        '--color-topbar-border' => '#ffffff',
        '--color-topbar-field' => '#eff1f2',
        '--color-topbar-hover' => '#eaecee',
        '--color-topbar-ink' => '#131e29',
        '--color-topbar-ink-muted' => '#1d2d3e',
        '--color-topnav' => '#ffffff',
        '--color-topnav-border' => '#ffffff',
        '--color-topnav-hover' => '#eaecee',
        '--color-topnav-ink' => '#131e29',
        '--color-topnav-ink-muted' => '#131e29',
        '--color-topnav-selected' => 'transparent',
        '--font-size-table' => '14px',
        '--grid-pad-x' => '8px',
        '--grid-pad-y' => '10px',
        '--radius-badge' => '8px',
        '--radius-card' => '12px',
        '--radius-field' => '4px',
        '--radius-pill' => '8px',
        '--row-height' => '44px',
        '--row-height-dense' => '32px',
        '--spacing-header' => '52px',
        '--stage-gap' => '12px',
        '--stage-tile' => '1',
        '--state-fill' => '0',
        '--topbar-control-h' => '36px',
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
        '--color-border' => '#34404b',
        '--color-border-strong' => '#4e5b69',
        '--color-cell-line' => 'transparent',
        '--color-footer' => '#1d232a',
        '--color-footer-border' => '#34404b',
        '--color-footer-ink' => '#a9b4be',
        '--color-ink' => '#eaecee',
        '--color-ink-body' => '#eaecee',
        '--color-ink-muted' => '#a9b4be',
        '--color-link' => '#4db1ff',
        '--color-row-alt' => 'transparent',
        '--color-sidebar' => '#1d232a',
        '--color-sidebar-active' => '#4db1ff',
        '--color-sidebar-border' => '#34404b',
        '--color-sidebar-icon' => '#a9b4be',
        '--color-sidebar-panel' => '#1d232a',
        '--color-surface-app' => '#12171c',
        '--color-surface-card' => '#1d232a',
        '--color-surface-hover' => '#29313a',
        '--color-surface-muted' => '#12171c',
        '--color-surface-selected' => '#002a4d',
        '--color-surface-sunken' => '#12171c',
        '--color-table-head' => '#1d232a',
        '--color-table-head-ink' => '#eaecee',
        '--color-toolbar' => '#1d232a',
        '--color-topbar' => '#1d232a',
        '--color-topbar-border' => '#1d232a',
        '--color-topbar-field' => '#29313a',
        '--color-topbar-hover' => '#29313a',
        '--color-topbar-ink' => '#eaecee',
        '--color-topbar-ink-muted' => '#a9b4be',
        '--color-topnav' => '#1d232a',
        '--color-topnav-border' => '#1d232a',
        '--color-topnav-hover' => '#29313a',
        '--color-topnav-ink' => '#eaecee',
        '--color-topnav-ink-muted' => '#eaecee',
        '--color-topnav-selected' => 'transparent',
    ],
];
