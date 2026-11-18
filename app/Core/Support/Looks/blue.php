<?php

declare(strict_types=1);

/*
 * নীল প্যানেল — মালিকের নিজের নীল (১৮ নভেম্বর ২০২৬), ১.৩.৫ থেকে ডিফল্ট রূপ।
 *
 * ── রং কোথা থেকে ─────────────────────────────────────────────────────
 * মালিকের পাঠানো CRM-এর ছবি আর তাঁর অনুমোদিত নমুনা (claude.ai artifact BTey3zZ3nnwspGzPMDVfMd, সংস্করণ ৫)।
 * নমুনার `:root`-এর টোকেন হুবহু এখানে: ফ্রেম #3a7be9, রেল ও উপরের বার #2160d8, কাজের জায়গা #eef3fc,
 * সাদা কার্ড, কালি #152238, ফিকে কালি #5f6f8a, দাগ #e3e9f4, ব্র্যান্ড #2563eb (অ্যাকসেন্ট `blue`-এর ৬০০)।
 * ⛔ মালিক Microsoft Fluent-এর গাঢ় নেভিতে রেগে গিয়েছিলেন — এই রংগুলো বদলানো চলবে না।
 *
 * ── কোণ ──────────────────────────────────────────────────────────────
 * "স্পষ্ট গোল": কার্ড ২২px, ঘর ১৪px (কার্ডের ভিতরে বসে, তাই ছোট — ভিতরের কোণ বাইরেরটার সমান হলে রেখা কাটে),
 * চিপ আর মেনুর সারি পুরো গোল। ভাসমান প্যানেলের গড়ন CSS-এ ([[resources/css/blue-look.css]]), কারণ ওটা টোকেন নয়।
 *
 * ── অবস্থার চার রং — প্রতিটার একটাই অর্থ ───────────────────────────────
 * লাল জরুরি · কমলা আজই · হলুদ এ সপ্তাহে · সবুজ ঠিক আছে; নীল সাধারণ তথ্য। পাশে সবসময় লেখার চিপ, রং একা নয়
 * ([[x-ui.status-chip]])। মানগুলো `tokens.css`-এও আছে (সব রূপের জন্য), এখানে নমুনার হুবহু।
 */

return [
    'light' => [
        '--color-border' => '#e3e9f4',
        '--color-border-strong' => '#c9d6ee',
        '--color-cell-line' => 'transparent',
        '--color-ink' => '#152238',
        '--color-ink-body' => '#152238',
        '--color-ink-muted' => '#5f6f8a',
        '--color-link' => '#2563eb',

        // ⓘ রেল আর উপরের বার একই নীলে — নমুনার `.app{background:#2160d8}`; ফ্রেমের হালকা নীল CSS-এ পাতার জমিন
        '--color-sidebar' => '#2160d8',
        '--color-sidebar-active' => '#ffffff',
        '--color-sidebar-border' => '#2160d8',
        '--color-sidebar-hover' => 'rgb(255 255 255 / 0.14)',
        '--color-sidebar-icon' => '#dbe7ff',
        '--color-sidebar-panel' => '#ffffff',
        '--color-state-on' => '#107c10',

        '--color-surface-app' => '#eef3fc',
        '--color-surface-card' => '#ffffff',
        '--color-surface-hover' => '#f2f6fd',
        '--color-surface-muted' => '#f5f8fe',
        '--color-surface-selected' => '#e8f0ff',
        '--color-surface-sunken' => '#eef3fc',
        '--color-table-head' => '#f5f8fe',
        '--color-table-head-ink' => '#5f6f8a',
        '--color-toolbar' => '#ffffff',
        '--color-row-alt' => '#f8faff',
        '--color-section-head' => '#f5f8fe',

        '--color-topbar' => '#2160d8',
        '--color-topbar-border' => '#2160d8',
        '--color-topbar-field' => 'rgb(255 255 255 / 0.14)',
        '--color-topbar-hover' => 'rgb(255 255 255 / 0.18)',
        '--color-topbar-ink' => '#ffffff',
        '--color-topbar-ink-muted' => '#eef5ff',

        '--color-modulebar' => '#ffffff',
        '--color-modulebar-border' => '#e3e9f4',
        '--color-topnav' => '#ffffff',
        '--color-topnav-border' => '#e3e9f4',
        '--color-topnav-hover' => '#f2f6fd',
        '--color-topnav-ink' => '#152238',
        '--color-topnav-ink-muted' => '#5f6f8a',
        '--color-topnav-selected' => '#e8f0ff',

        '--color-footer' => '#ffffff',
        '--color-footer-border' => 'transparent',
        '--color-footer-ink' => '#5f6f8a',

        // ⭐ অবস্থার চার রং — নমুনার হুবহু (জরুরি · আজই · এ সপ্তাহে · ঠিক আছে)
        '--color-status-urgent' => '#c50f1f',
        '--color-status-urgent-soft' => '#fdf3f4',
        '--color-status-today' => '#bc4b09',
        '--color-status-today-soft' => '#fdf6f3',
        '--color-status-week' => '#8a6d00',
        '--color-status-week-bar' => '#eaa300',
        '--color-status-week-soft' => '#fefbf4',
        '--color-status-week-on' => '#1b1b1b',
        '--color-status-ok' => '#107c10',
        '--color-status-ok-soft' => '#f1faf1',
        '--color-status-on' => '#ffffff',
        '--color-status-off' => '#94a3b8',

        // ⓘ নমুনার নরম ছায়া — কার্ডে দাগ নেই, ছায়া আর ফাঁক
        '--shadow-card' => '0 1px 2px rgb(0 0 0 / 0.06), 0 4px 14px rgb(0 0 0 / 0.06)',

        '--font-size-table' => '12.5px',
        '--grid-pad-x' => '12px',
        '--grid-pad-y' => '7px',
        '--radius-badge' => '999px',
        '--radius-card' => '22px',
        '--radius-field' => '14px',
        '--radius-pill' => '999px',

        '--rail-tile-radius' => '16px',
        '--rail-item-on-bg' => '#e8f0ff',
        '--rail-item-on-ink' => '#2563eb',
        '--rail-item-h' => '32px',
        '--rail-item-font' => '13.5px',
        '--rail-item-radius' => '999px',
        '--row-height' => '44px',
        '--row-height-dense' => '36px',
        '--spacing-header' => '64px',
        '--topbar-control-h' => '40px',
    ],

    /*
     * গাঢ় থিম — নমুনার `[data-theme="dark"]` হুবহু: ফ্রেম #1c3f8f, রেল #16336f, জমিন #0f1830, কার্ড #17223f।
     * ⓘ কোণ, ঘনত্ব, সারির উচ্চতা এখানে নেই — রাতে ওগুলো বদলায় না ([[LookRegistry::tokens()]] আলোর উপর গাঢ় বসায়)।
     */
    'dark' => [
        '--color-border' => '#26345a',
        '--color-border-strong' => '#33457a',
        '--color-cell-line' => '#26345a',
        '--color-ink' => '#e7eefc',
        '--color-ink-body' => '#e7eefc',
        '--color-ink-muted' => '#9fb0cf',
        '--color-link' => '#8fb3ff',

        '--color-sidebar' => '#16336f',
        '--color-sidebar-active' => '#ffffff',
        '--color-sidebar-border' => '#16336f',
        '--color-sidebar-hover' => 'rgb(255 255 255 / 0.10)',
        '--color-sidebar-icon' => '#a9c2fb',
        '--color-sidebar-panel' => '#17223f',
        '--color-state-on' => '#6ccb5f',

        '--color-surface-app' => '#0f1830',
        '--color-surface-card' => '#17223f',
        '--color-surface-hover' => '#1e2b4f',
        '--color-surface-muted' => '#1b2849',
        '--color-surface-selected' => '#1e2f5c',
        '--color-surface-sunken' => '#0f1830',
        '--color-table-head' => '#1b2849',
        '--color-table-head-ink' => '#9fb0cf',
        '--color-toolbar' => '#17223f',
        '--color-row-alt' => '#192544',
        '--color-section-head' => '#1b2849',
        '--color-surface-success-tint' => '#17223f',

        '--color-topbar' => '#16336f',
        '--color-topbar-border' => '#16336f',
        '--color-topbar-field' => 'rgb(255 255 255 / 0.10)',
        '--color-topbar-hover' => 'rgb(255 255 255 / 0.14)',
        '--color-topbar-ink' => '#e7eefc',
        '--color-topbar-ink-muted' => '#c3d3f3',

        '--color-modulebar' => '#17223f',
        '--color-modulebar-border' => '#26345a',
        '--color-topnav' => '#17223f',
        '--color-topnav-border' => '#26345a',
        '--color-topnav-hover' => '#1e2b4f',
        '--color-topnav-ink' => '#e7eefc',
        '--color-topnav-ink-muted' => '#9fb0cf',
        '--color-topnav-selected' => '#1e2f5c',

        '--color-footer' => '#17223f',
        '--color-footer-border' => 'transparent',
        '--color-footer-ink' => '#9fb0cf',

        '--color-status-urgent' => '#ff8a93',
        '--color-status-urgent-soft' => '#3b1519',
        '--color-status-today' => '#ffa36b',
        '--color-status-today-soft' => '#3a2010',
        '--color-status-week' => '#f5d16b',
        '--color-status-week-bar' => '#f5c400',
        '--color-status-week-soft' => '#352b0a',
        '--color-status-week-on' => '#1b1b1b',
        '--color-status-ok' => '#6ccb5f',
        '--color-status-ok-soft' => '#14301a',
        '--color-status-on' => '#1b1b1b',
        '--color-status-off' => '#8a9bbb',

        '--shadow-card' => '0 1px 2px rgb(0 0 0 / 0.4), 0 4px 14px rgb(0 0 0 / 0.35)',

        '--rail-item-on-bg' => '#1e2f5c',
        '--rail-item-on-ink' => '#a9c2fb',
    ],
];
