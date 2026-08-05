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
        'apply' => 'Apply',
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
        'powered_by' => 'Powered by',
        /* Not translated: a company's name is its name. */
        'powered_by_name' => 'UNIVER BANGLADESH',
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
        'item' => 'Item',
        'unit' => 'Unit',
        'qty' => 'Qty',
        'rate' => 'Rate',
        'amount' => 'Amount',
        'minus' => 'Minus',
        'subtotal' => 'Subtotal',
        'discount' => 'Discount',
        'tax' => 'VAT',
        'delivered_by' => 'Delivered by',
        'driver' => 'Driver\'s signature',
        'gate_officer' => 'Security officer',
        'storekeeper' => 'Storekeeper',
        'customer_copy' => 'Customer copy',
        'office_copy' => 'Office copy',
        'draft_notice' => 'DRAFT — this is not a final bill',
        'no_price_notice' => 'Prices are not shown on this document',
        'print' => 'Print',
        'choose_paper' => 'Choose paper',
        'show_vendor_credit' => 'Show "Powered by ABOS" on printouts',
    ],

    'role' => [
        'owner' => 'Owner',
        'accountant' => 'Accountant',
        'salesman' => 'Salesman',
    ],

    'create' => [
        'nothing_yet' => 'Nothing to create yet — the modules that make records are not installed.',
    ],
    'notice' => [
        'awaiting_mine' => 'Waiting for your decision',
        'approvals_later' => 'Approvals — the screen arrives with the module that raises them.',
        'title' => 'Needs attention',
        'none' => 'Nothing needs attention.',
        'backup_stale' => 'No backup for over two days — nothing could be recovered if the disk fails.',
        'awaiting_decision' => '{1} 1 waiting for a decision|[2,*] :count waiting for a decision',
    ],

    'import' => [
        'title' => 'Bring in from the old books',
        'note' => 'Download the template, fill it in and send it back. You will see which rows are usable before anything is saved.',
        'what' => 'What to bring in',
        'file' => 'CSV file',
        'template' => 'Template',
        'check' => 'Check first',
        'commit' => 'Bring them in',
        'line' => 'Row',
        'problem' => 'Problem',
        'ok_rows' => '{0} No usable rows|{1} 1 usable row|[2,*] :count usable rows',
        'bad_rows' => '{0} No rows with problems|{1} 1 row has a problem|[2,*] :count rows have problems',
        'imported' => '{0} Nothing was brought in|{1} 1 row brought in|[2,*] :count rows brought in',
        'truncated' => 'The file has more than :max rows — the rest were not read. Split it and send again.',
        'empty_file' => 'The file is empty.',
        'missing_column' => ':column is empty.',
        'not_a_number' => ':column is not a number.',
        'not_a_date' => ':column is not a date — use day/month/year.',
        'unknown_value' => 'Could not match ":value" in :column.',
        'nothing_to_import' => 'No usable rows, so nothing was saved.',
    ],

    'yes' => 'Yes',
    'no' => 'No',

    'toolbar' => [
        'columns' => 'Columns',
        'columns_note' => 'Untick them all and the table shows every column — an empty table helps nobody.',
        'export' => 'Export',
        'export_csv' => 'CSV (opens in Excel)',
        'export_pdf' => 'PDF — print, then save as PDF',
        'share' => 'Share',
        'share_email' => 'Email',
        'share_copy' => 'Copy link',
        'share_copied' => 'Link copied',
        // ব্যবহারকারীর দেওয়া নমুনায় লেখা ছিল "Filter By", "Filter" নয়
        'filter' => 'Filter By',
        'sort_by' => 'Sort by',
        'view' => 'View',
        'view_list' => 'As a list',
        'view_grid' => 'As cards',
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
        'company' => 'Company',
        'branch' => 'Branch',
        'branch_of' => 'Branch — :company',
        'stamped_with_branch' => 'Everything you enter is stamped with the branch you are in.',
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
