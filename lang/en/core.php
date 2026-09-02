<?php

declare(strict_types=1);

/** Core strings, English. Every key here must also exist in lang/bn/core.php — rule 9. */
return [
    'band' => [
        'process' => 'Where the papers stand',
    ],

    'status' => [
        'draft' => 'Draft',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
        'closed' => 'Closed',
    ],

    /*
     * What a figure means — shown beside the figure itself.
     *
     * A number whose definition is hidden gets two readings from two
     * people. Whether "today's sales" counts drafts, and whether the
     * date is the paper's or the typist's, is exactly where two screens
     * once gave two answers.
     */
    'metric' => [
        'definition' => 'Counts :statuses documents · dated by :date · :scale decimal places · rounded :rounding',
        'by_transaction_date' => 'transaction date',
        'by_entry_date' => 'entry date',
        'round_per_row' => 'per row',
        'round_at_total' => 'once at the total',
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
        'loan' => 'Loan',
        'loan_movement' => 'Loan Movement',
        'loan_instalment' => 'Loan Instalment',
        'product' => 'Product',
        'supplier' => 'Supplier',
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

    /* Sidebar sections — the level ABOVE a module. Not to be confused with
       `menu.*`, which is the six groups INSIDE one module. `top` carries no
       heading, so it has no key here. See lang/bn/core.php. */
    'nav_section' => [
        'finance' => 'Finance',
        'business' => 'Business',
        'people' => 'People & Governance',
        'system' => 'System',
    ],

    'action' => [
        'see_all' => 'See all',
        'refresh' => 'Refresh',
        'close' => 'Close',
        'create' => 'Create',
        'apply' => 'Apply',
        'discard' => 'Discard',
        'save' => 'Save',
        'edit' => 'Edit',
        'view' => 'View',
        'approve' => 'Approve',
        'print' => 'Print',
        'export' => 'Export',
        'share' => 'Share',
        'duplicate' => 'Duplicate',
        'history' => 'History',
        'help' => 'Help',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'search' => 'Search',
        'search_anything' => 'Search anything…',
        'switch_language' => 'Switch language',
        'logout' => 'Log out',
        'more' => 'More',
        'fullscreen' => 'Full screen',
        'exit_fullscreen' => 'Exit full screen (Esc)',
    ],

    'attachment' => [
        'title' => 'Papers',
        'none' => 'No papers have been added to this document.',
        'upload' => 'Add a paper',
        'remove' => 'Remove',
        'uploaded' => 'The paper was added.',
        'removed' => 'The paper was removed.',
        'limit' => 'Up to 10 MB. Images, PDF, Excel are fine; programs are not.',
        'unknown_source' => 'Papers cannot be kept against this kind of document.',
        'refused' => 'The file was not accepted — :reason',
    ],

    'custom_field' => [
        'title' => 'Your own fields',
        'subtitle' => 'Fields you need that the system does not ship with — on customers, products, suppliers.',
        'add' => 'New field',
        'none' => 'No fields of your own yet.',
        'entity' => 'On what',
        'key' => 'Key',
        'key_hint' => 'Lowercase letters and underscores (route_no). It cannot be changed later — changing it would orphan every value already written.',
        'label_bn' => 'Name (Bangla)',
        'label_en' => 'Name (English)',
        'type' => 'Type',
        'sort' => 'Order',
        'required' => 'Must be filled',
        'active' => 'Active',
        'options' => 'Choices',
        'options_hint' => 'One per line. Not commas — "Dhaka, North" can be a single choice.',
        'select_needs_options' => 'A choice field needs at least one choice, or it would open with nothing to pick.',
        'created' => 'The field was added.',
        'updated' => 'The field was updated.',
        'deactivated' => 'The field is off — everything already written to it stays.',
        'required_field' => ':label cannot be left empty.',
        'not_a_number' => ':label takes a number.',
        'not_a_date' => ':label takes a date.',
        'unknown_choice' => ':label has no such choice.',
        'unknown_entity' => 'Fields of your own cannot be put on this kind of record.',
        'types' => [
            'text' => 'Text',
            'number' => 'Number',
            'date' => 'Date',
            'boolean' => 'Yes / no',
            'select' => 'Choice',
        ],
    ],

    'dashboard' => [
        'across_the_business' => 'Across the business',
        'today' => 'Today',
        'this_month' => 'This month',
        'this_year' => 'This year',
        'against_last' => 'against last :day',
        'oldest_is' => 'oldest is :days days old',
        'needs_doing' => 'Needs doing',
        'just_happened' => 'Just happened',
        // The zero rows fold into one line — "looked, nothing there"
        'nothing_pending' => 'Nothing pending on one other thing|Nothing pending on :count other things',
        'nothing_to_show' => 'Nothing to show here — the figures come from the modules you have permission to open.',
        'foundation_ready' => 'The foundation is in place',
        'foundation_note' => 'Core engines, tenant scoping, approvals, attachments and both languages are working. Modules sit on top of this.',
    ],

    'accounting' => [
        'receivable' => 'Receivable',
        'payable' => 'Payable',
    ],

    'brand' => [
        'developed_by' => 'Developed by Al-Amin Shuvo',
        'full_name' => 'A Business Operating System',
        'tagline' => 'Simple to Run. Powerful to Grow.',
        'powered_by' => 'Powered by',
        /* Not translated: a company's name is its name. */
        'powered_by_name' => 'UNIVER BANGLADESH',
        /*
         * পণ্যের নাম — শুধু ABOS।
         *
         * ── কেন বদলাল (২ সেপ্টেম্বর ২০২৬) ───────────────────────────
         * এতদিন লেখা ছিল `ADI | ABOS`, আর সেটা পর্দায় তিন জায়গায়
         * দেখাত। মালিকের সিদ্ধান্ত: ADI কোথাও থাকবে না, শুধু ABOS —
         * পণ্যটা নিজের নামেই বিক্রি হবে।
         *
         * `house` ঘরটা রাখা হয়েছে, মুছে দেওয়া হয়নি: অনুপস্থিত চাবি
         * খুঁজতে গিয়ে পাতা ভাঙার চেয়ে একটা সঠিক মান রাখা ভালো।
         */
        'house' => 'ABOS',
        'name' => 'ABOS',
        /* Nor is a company's own slogan, for the same reason. */
        'powered_by_slogan' => 'Empowering Tomorrow',
    ],

    'appearance' => [
        'title' => 'Appearance',
        'subtitle' => 'Your own colour, theme and language — not a company setting',
        'ui' => 'How the ERP looks',
        'ui_note' => 'Whatever you pick here, the whole of ABOS becomes — every screen, every list. Not a colour change: five are exact copies of real ERPs, two are ABOS own designs.',
        'match_accent' => 'Use the colour this look was designed with',
        'accent' => 'Colour',
        'accent_note' => 'A fixed set rather than a free picker: each one has had its contrast checked, so no choice leaves a button unreadable.',
        'theme' => 'Theme',
        'theme_note' => 'Light or dark background.',
        'light' => 'Light',
        'dark' => 'Dark',
        'language' => 'Language',
        'saved' => 'Saved.',

        'band_shape' => 'Different arrangements',
        'band_shape_note' => 'These change where the menu, the bars and the lists sit — not just the colour.',
        'band_colour' => 'Same arrangement, different colour',
        'band_colour_note' => 'These lay the screen out the same way — the rail, the same row height. Only the colours differ.',
    ],

    'backup' => [
        'title' => 'Backups',
        'subtitle' => 'What is taken every night — when the last one was, where it sits, and how to bring it back',
        'newest' => 'Newest backup',
        'none_yet' => 'No backup yet',
        'take_now' => 'Take one now',
        'taken' => 'Backup taken — :name (:size).',
        'failed' => 'The backup could not be taken: :reason',
        'mirror' => 'Second destination',
        'no_mirror' => 'Not set',
        'no_mirror_how' => 'The books and the backups sit on one disk. Lose it and you lose both. Set ABOS_BACKUP_MIRROR in .env to another disk or drive.',
        'never_mirrored' => 'Set, but nothing has been copied',
        'how_it_runs' => 'How it runs',
        'every_night_at' => 'Every night at',
        'kept_for' => 'Kept for',
        'days' => '{1} :count day|[2,*] :count days',
        'folder' => 'Where they sit',
        'restore' => 'Bringing one back',
        'restore_note' => "Restoring wipes today's work, so there is no button for it. Run the command below on the server — and it is better done with someone beside you who knows what is happening.",
        'all_of_them' => 'All of them',
    ],

    'accent' => [
        /* ABOS own colour, taken from the logo. First in the list. */
        'abos' => 'ABOS teal',
        'blue' => 'Blue',
        'teal' => 'Teal',
        'indigo' => 'Indigo',
        'violet' => 'Violet',
        'emerald' => 'Emerald',
        'slate' => 'Slate',
        'amber' => 'Amber',
        'aubergine' => 'Aubergine',
        'brick' => 'Brick',
        'salesforce' => 'Lightning blue',
        'linear' => 'Linear indigo',
        'crimson' => 'Crimson',
        'fiori' => 'Fiori blue',
        'netsuite' => 'Suite blue',
        'fluent' => 'Fluent blue',
    ],

    /* The names stay in English in both languages — they are identities,
       not labels. A colleague says "go to Apps" on the phone, and both
       ends need to hear the same word. */
    'ui' => [
        'area_switch' => 'Change area',
        'launcher' => 'Pick an app',
        'launcher_search' => 'Search apps…',
        'classic' => 'Classic',
        'classic_blurb' => 'Menu across the top, striped rows, amber. The densest of the eight.',
        'tiles' => 'Tiles',
        'tiles_blurb' => 'A page of tiles, round buttons, cool blue.',
        'suite' => 'Suite',
        'suite_blurb' => 'Dense rows, narrow type — a lot on one screen.',
        'apps' => 'Apps',
        'apps_blurb' => 'Aubergine header, open lists, soft edges.',
        'dynamic' => 'Dynamic',
        'dynamic_blurb' => 'Command bar on top, dense grids, an office feel.',
        'redwood' => 'Redwood',
        'redwood_blurb' => 'Brick header, rounded cards, plenty of air.',
        'salesforce' => 'Salesforce',
        'salesforce_blurb' => 'Dark navy header, a white tab row beneath it, sharp 4px corners.',
        'linear' => 'Linear',
        'linear_blurb' => 'A near-black screen, one indigo, almost no chrome at all.',
        'rose' => 'Rose',
        'rose_blurb' => 'An ABOS original — warm red, gentle edges.',
        'navy' => 'ABOS',
        'navy_blurb' => 'An ABOS original — the brand teal, dense and quiet.',
        'like' => 'Like :erp',
    ],

    'components' => [
        'title' => 'Components',
        'subtitle' => 'Every screen is built from these — one toolbar, one form, one table',
        'buttons' => 'Buttons',
        'badges' => 'Badges and status',
    ],

    'report' => [
        'contribution' => 'Share %',
        'change' => 'Change %',
        'compare_previous' => 'Same length before',
        'compare_last_year' => 'Same period last year',
        'compare' => 'Compare',
        'compare_none' => 'No comparison',
        'top' => 'Show top',
        'top_all' => 'All rows',
        'top_n' => 'Top :count',
        'showing_top' => 'Top :count of :total — the rest are not in this list',
        'new_in_period' => 'New',
    ],

    'export' => [
        'someone_gone' => 'A removed user',
    ],

    'table' => [
        'range' => ':from - :to of :total',
        'page_total' => 'This page',
        'code' => 'Code',
        'name' => 'Name',
        'actions' => 'Actions',
        'serial' => 'SL#',
        'date' => 'Date',
        'from_date' => 'From date',
        'to_date' => 'To date',
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

        // ⚠️ The hotline is written in both languages, each in its own
        //    digits. Change it here AND in bn/core.php.
        'vendor_line' => 'Powered by UNIVER BANGLADESH',
        'hotline' => 'Hotline 01911048185',
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

        // Second and later prints of the same paper
        'duplicate_notice' => 'DUPLICATE — this paper has been printed before',

        // A cancelled paper looks exactly like a valid one; the line is the only difference
        'cancelled_notice' => 'CANCELLED — this document has been cancelled and is not valid',

        /*
         * জলছাপের লেখাটা আলাদা, আর ছোট -- ইচ্ছাকৃত।
         *
         * উপরের বাক্সে পুরো বাক্যটা যায় ("এই কাগজটি বাতিল করা হয়েছে,
         * এর কোনো মূল্য নেই")। জলছাপ কোনাকুনি গোটা পাতা জুড়ে বসে, তাই
         * সেখানে লম্বা বাক্য দিলে অক্ষরগুলো এত ছোট হত যে কিছুই পড়া
         * যেত না -- আর পড়া না গেলে জলছাপের কোনো মানে নেই।
         */
        'cancelled_watermark' => 'CANCELLED',
        'print' => 'Print',
        'choose_paper' => 'Choose paper',
        'show_vendor_credit' => 'Show "Powered by UNIVER BANGLADESH" and the hotline on printouts',
    ],

    'role' => [
        'owner' => 'Owner',
        'accountant' => 'Accountant',
        'salesman' => 'Salesman',
    ],

    /*
     * সক্রিয় / নিষ্ক্রিয় — এক জায়গায়, প্রতিটা মডিউলে আলাদা নয়।
     *
     * গ্রাহক, সরবরাহকারী, পণ্য, গুদাম — সবার জন্য একই দুইটা শব্দ। আগে
     * প্রতিটা মডিউল নিজের লেখা রাখত, আর একদিন একটায় "বন্ধ" আর অন্যটায়
     * "নিষ্ক্রিয়" লেখা থাকত, অথচ জিনিসটা এক।
     */
    'state' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],

    'create' => [
        'nothing_yet' => 'Nothing to create yet — the modules that make records are not installed.',

        /*
         * কোডের ঘরে যা লেখা থাকে, আর তার নিচের ছোট লাইনটা।
         *
         * ঘরটা ফাঁকা রাখা যায় বলেই এই দুইটা দরকার: placeholder না থাকলে
         * ব্যবহারকারী ভাবতেন কিছু একটা লিখতেই হবে, আর hint না থাকলে
         * ফাঁকা রেখে সেভ করার পর কোডটা কোথা থেকে এল তা রহস্যই থাকত।
         */
        'code_auto' => 'Assigned automatically',
        'code_auto_hint' => 'Leave it empty and one will be given. Type one only to keep a code from your old books.',
    ],
    'notice' => [
        'awaiting_mine' => 'Waiting for your decision',
        'approvals_later' => 'Approvals — the screen arrives with the module that raises them.',
        'title' => 'Needs attention',
        'none' => 'Nothing needs attention.',
        'backup_no_mirror' => 'Backups and the books sit on one disk — lose it and you lose both. Set a second destination.',
        'backup_mirror_stale' => 'Nothing has reached the second destination for two days — the copy has stopped.',
        'backup_stale' => 'No backup for over two days — nothing could be recovered if the disk fails.',
        'awaiting_decision' => '{1} 1 waiting for a decision|[2,*] :count waiting for a decision',

        // The screen is switched off in Control Panel, so knowing the address does not open it
        'screen_switched_off' => 'This screen is switched off for this company. To turn it on: System Management → Control Panel.',
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
        'remove_filter' => 'Remove this filter',
        'columns' => 'Columns',
        'columns_note' => 'Untick them all and the table shows every column — an empty table helps nobody.',
        'export' => 'Export',
        'export_csv' => 'CSV (opens in Excel)',
        'export_csv_note' => 'Exactly what you see on this page',
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

    /* Saved views — a person's own filters, kept under a name. */
    'view' => [
        'views' => 'Views',
        'all_rows' => 'All rows',
        'save_current' => 'Save this view',
        'name_placeholder' => 'Name it — e.g. "Overdue, Mymensingh"',
        'set_as_default' => 'Open this screen with it',
        'is_default' => 'This one opens by default',
        'make_default' => 'Open this screen with it',
        'remove' => 'Delete this view',
        'confirm_remove' => 'Delete ":name". Its filters will no longer be saved.',
        'saved' => 'Saved ":name".',
        'default_set' => 'This screen will now open with ":name".',
        'removed' => 'Deleted ":name".',
        'unknown_screen' => 'There is no screen by that name.',
        'screen_needs_a_record' => 'This screen opens for one particular record, so it has no view to save.',
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
        'filter_this_menu' => ':module · search…',
        'main_navigation' => 'Main navigation',
        // The path row — "Home / Module / Screen"
        'breadcrumb' => 'Where you are',
    ],

    'company' => [
        'switch' => 'Switch company',
        'company' => 'Company',
        'branch' => 'Branch',
        'branch_of' => 'Branch — :company',
        'stamped_with_branch' => 'Everything you enter is stamped with the branch you are in.',
        'financial_year' => 'Financial year',
    ],

    /* Explains the mask on a hidden field, on hover. */
    'field' => [
        'hidden' => 'You do not have permission to see this field.',
    ],

    'form' => [
        'required' => 'required',
        'optional' => 'optional',
        // Dates read day-month-year everywhere, never the browser's locale
        'date_hint' => 'dd-mm-yyyy',
        'pick_date' => 'Open calendar',
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
    'count' => [
        'records' => ':count record|:count records',
    ],

    // মাস বন্ধ ও পেছনের তারিখের জানালা
    'period' => [
        'month_closed' => ':month has been closed (:reason). To enter anything dated in it, the month has to be reopened first.',
        'no_reason' => 'no reason given',
        'too_far_back' => 'An entry cannot be dated more than :days days back. The earliest date allowed is :date.',
    ],

    // নকল ঠেকানো — একই পক্ষ দুইবার ঢোকা
    'duplicate' => [
        'phone_taken' => 'That phone number already belongs to :name (:code). Two rows for one party split their dues in half.',
        'name_matches' => 'A party with this name already exists — check before you go on.',
        'confirm_hint' => 'If this really is a different business, tick the box and save again.',
        'allow' => 'This is a different business',
    ],

    // বিজ্ঞপ্তি — একজনের জন্য একটা খবর
    'notify' => [
        'title' => 'For you',
        'none' => 'Nothing new.',
        'mark_all' => 'Mark all read',
        'approval_approved' => 'Approved: :document',
        'approval_rejected' => 'Turned down: :document',
    ],
    'look' => [
        'too_faint' => ':ink on :on is only :ratio:1 — it needs at least :need:1 or the text cannot be read.',
        'wrong_kind' => ':name wants a :wants and got :value.',
        'kind_colour' => 'colour',
        'kind_length' => 'measurement',
        'kind_number' => 'number',
        'unknown_token' => ':name — no token by that name. Check the spelling; a wrong name does nothing, quietly.',
        'empty_token' => ':name has no value.',
        'not_a_length' => ':name wants a measurement (44px, say), and got :value.',
        'not_a_colour' => ':name wants a colour and got a measurement — :value.',
        'title' => 'Company look',
        'subtitle' => "The ERP's colours and shape — standing on a ready-made look, changing only what you want changed",
        'none' => 'No look of your own yet.',
        'new' => 'New look',
        'name' => 'Name',
        'parent' => 'Stands on',
        'tokens' => 'What you are changing',
        'token_name' => 'Token',
        'token_value' => 'Value',
        'add_token' => 'Add another',
        'export' => 'Save this look to a file',
        'import' => 'Add a look from a file',
        'imported_ok' => '":name" is in, as a draft. Look it over, then publish.',
        'file_not_a_look' => 'That is not an ABOS look file.',
        'file_not_json' => 'The file could not be read — it does not contain valid JSON.',
        'file_wrong_format' => 'The file is format :found and this ABOS knows up to :known. It probably comes from a newer version.',
        'file_unknown_parent' => 'The file stands on ":name", and there is no look by that name here.',
        'mine_note' => 'Looks your own company built. Each stands on one of the ten above, with only a few colours changed.',
        'stands_on' => 'Stands on :name',
        'dark_note' => 'Leave the dark box empty and the light value is used at night too — so if you darken a ground, change its ink in the dark box as well, or the text disappears and publishing is refused.',
        'draft' => 'Draft',
        'unpublished' => 'Has unpublished changes',
        'published' => 'Published',
        'publish' => 'Publish',
        'published_ok' => 'Published — this is what everyone sees now.',
        'saved' => "Draft saved. Nobody's screen changed until you publish.",
        'note' => 'Why you changed it',
        'note_hint' => 'Six months from now this line is what says why the blue changed.',
        'versions' => 'Versions',
        'version_n' => 'Version :n',
        'revert' => 'Go back to this',
        'reverted_ok' => 'Back on version :n.',
        'reverted_note' => 'Back to version :version',
        'reverted_from' => 'reverted from :n',
        'version_not_this_skin' => 'That version belongs to another look.',
        'parent_is_draft' => '":name" has not been published — you cannot stand on something unpublished.',
        'preview' => 'Try it on',
        'preview_on' => 'You are trying on ":name" — on your screen only, and it stops by itself in :minutes minutes.',
        'preview_stop' => 'Stop preview',
        'preview_started' => 'Preview on — walk through any screen in the ERP.',
        'preview_stopped' => 'Preview stopped.',
    ],
];
