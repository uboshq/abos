<?php

declare(strict_types=1);

/** Core strings, English. Every key here must also exist in lang/bn/core.php — rule 9. */
return [
    'status' => [
        'draft' => 'Draft',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
        'closed' => 'Closed',
    ],

    'drill' => [
        'unavailable' => ':type — the source document is no longer available',
        'view_source' => 'View source',
    ],

    'source' => [
        'sales_invoice' => 'Sales Invoice',
        'purchase_invoice' => 'Purchase Invoice',
        'receipt_voucher' => 'Receipt Voucher',
        'payment_voucher' => 'Payment Voucher',
        'expense_voucher' => 'Expense Voucher',
        'journal_voucher' => 'Journal Voucher',
        'contra_voucher' => 'Contra Voucher',
        'money_transfer' => 'Money Transfer',
        'cash_count' => 'Cash Count',
        'customer' => 'Customer',
    ],

    'posting' => [
        'reversal_of' => 'Reversal of :document',
        'unbalanced' => 'Debit and credit do not match',
    ],

    'menu' => [
        'dashboard' => 'Dashboard',
        'master' => 'Master',
        'transactions' => 'Transactions',
        'approval' => 'Approval',
        'reports' => 'Reports',
        'settings' => 'Settings',
    ],

    'action' => [
        'create' => 'Create',
        'save' => 'Save',
        'edit' => 'Edit',
        'approve' => 'Approve',
        'print' => 'Print',
        'export' => 'Export',
        'share' => 'Share',
        'duplicate' => 'Duplicate',
        'history' => 'History',
        'help' => 'Help',
        'cancel' => 'Cancel',
        'search' => 'Search',
        'search_anything' => 'Search anything…',
        'switch_language' => 'Switch language',
        'logout' => 'Log out',
        'more' => 'More',
        'fullscreen' => 'Full screen',
        'exit_fullscreen' => 'Exit full screen (Esc)',
    ],

    'dashboard' => [
        'foundation_ready' => 'The foundation is in place',
        'foundation_note' => 'Core engines, tenant scoping, approvals, attachments and both languages are working. Modules sit on top of this.',
    ],

    'accounting' => [
        'receivable' => 'Receivable',
        'payable' => 'Payable',
    ],

    'brand' => [
        'developed_by' => 'Developed by Al-Amin Shuvo',
        'full_name' => 'All Business Operating System',
        'tagline' => 'Built Around Your Business.',
        'powered_by' => 'Powered by UNIVER BANGLADESH',
    ],

    'appearance' => [
        'title' => 'Appearance',
        'subtitle' => 'Your own colour, theme and language — not a company setting',
        'accent' => 'Colour',
        'accent_note' => 'A fixed set rather than a free picker: each one has had its contrast checked, so no choice leaves a button unreadable.',
        'theme' => 'Theme',
        'theme_note' => 'Light or dark background.',
        'light' => 'Light',
        'dark' => 'Dark',
        'language' => 'Language',
        'saved' => 'Saved.',
    ],

    'accent' => [
        'blue' => 'Blue',
        'teal' => 'Teal',
        'indigo' => 'Indigo',
        'violet' => 'Violet',
        'emerald' => 'Emerald',
        'slate' => 'Slate',
    ],

    'components' => [
        'title' => 'Components',
        'subtitle' => 'Every screen is built from these — one toolbar, one form, one table',
        'buttons' => 'Buttons',
        'badges' => 'Badges and status',
    ],

    'table' => [
        'date' => 'Date',
        'document' => 'Document',
        'party' => 'Party',
        'debit' => 'Debit',
        'credit' => 'Credit',
        'balance' => 'Balance',
        'narration' => 'Narration',
    ],

    'print' => [
        'paper' => [
            'a4' => 'A4',
            '80_mm' => '80 mm (thermal)',
            '58_mm' => '58 mm (thermal)',
        ],
        'document_no' => 'No.',
        'date' => 'Date',
        'party' => 'Party',
        'account' => 'Account',
        'total' => 'Total',
        'in_words' => 'In words',
        'phone' => 'Phone',
        'printed_at' => 'Printed',
        'prepared_by' => 'Prepared by',
        'approved_by' => 'Approved by',
        'received_by' => 'Received by',
        'show_vendor_credit' => 'Show "Powered by ABOS" on printouts',
    ],

    'role' => [
        'owner' => 'Owner',
        'accountant' => 'Accountant',
        'salesman' => 'Salesman',
    ],

    'notice' => [
        'backup_stale' => 'No backup for over two days — nothing could be recovered if the disk fails.',
        'awaiting_decision' => '{1} 1 waiting for a decision|[2,*] :count waiting for a decision',
    ],

    'yes' => 'Yes',
    'no' => 'No',

    'toolbar' => [
        // ব্যবহারকারীর দেওয়া নমুনায় লেখা ছিল "Filter By", "Filter" নয়
        'filter' => 'Filter By',
        'sort_by' => 'Sort by',
        'view' => 'View',
        'view_list' => 'As a list',
        'view_grid' => 'As cards',
        'columns' => 'Columns',
        'density' => 'Density',
        'refresh' => 'Refresh',
        'group' => 'Group',
        'freeze' => 'Freeze',
    ],

    'empty' => [
        'nothing_here' => 'Nothing here yet',
        'no_results' => 'Nothing matched that search',
    ],

    'status_bar' => [
        'operational' => 'Operational',
        'maintenance' => 'Maintenance',
        'incident' => 'Incident',
    ],

    'a11y' => [
        'skip_to_content' => 'Skip to content',
        'module_navigation' => 'Switch module',
        'collapse_sidebar' => 'Collapse sidebar',
        'expand_sidebar' => 'Expand sidebar',
        'filter_menu' => 'Filter menu…',
        'main_navigation' => 'Main navigation',
    ],

    'company' => [
        'switch' => 'Switch company',
        'branch' => 'Branch',
        'financial_year' => 'Financial year',
    ],

    'form' => [
        'required' => 'required',
        'optional' => 'optional',
    ],

    'profile' => [
        'title' => 'My profile',
        'subtitle' => 'Your name and photo',
        'identity' => 'Identity',
        'name' => 'Name',
        'email' => 'Email',
        'photo' => 'Photo',
        'photo_note' => 'JPG, PNG or WebP — up to :mb MB. The photo will be cropped square.',
        'upload_photo' => 'Upload photo',
        'change_photo' => 'Change photo',
        'remove_photo' => 'Remove photo',
        'remove_confirm' => 'The photo will be removed. Continue?',
        'saved' => 'Saved.',
    ],

    'avatar' => [
        'not_an_image' => 'That file could not be opened as an image. Use JPG, PNG or WebP.',
        'too_large' => 'That photo is too large. Please use a smaller one.',
    ],
];
