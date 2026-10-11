<?php

declare(strict_types=1);

return [
    'opening_to_capital' => 'Opening balances (customer dues, opening stock, account balances) go to the owner capital — off sends them to retained earnings',
    'backdate_days' => 'How many days back an entry may be dated',
    'cash_ceiling_enabled' => 'Cash ceiling per person',
    'cash_ceiling_blocks' => 'Block money in over the ceiling (otherwise only warn)',
    'require_narration' => 'Narration required on vouchers',
    'asset_capitalisation_threshold' => 'Fixed asset capitalisation threshold: anything cheaper goes to expense (0 means no threshold)',
    'asset_prorata' => 'First-month depreciation',
    'asset_prorata_full_month' => 'Full month',
    'asset_prorata_daily' => 'Pro-rata by day',
    'asset_idle_stops_depreciation' => 'Stop depreciation while an asset is idle',
    'asset_auto_run' => 'Post monthly depreciation automatically (on the 1st, for the month before)',
    'print_signature_lines' => 'Signature lines on printouts',
    'paper_voucher' => 'Paper for vouchers',
    'design_voucher' => 'Voucher design',
    'design' => [
        'standard' => 'Standard',
        'aurora' => 'Aurora Gradient',
        'bento' => 'Bento Cards',
        'neo_brutal' => 'Neo-Brutal',
        'soft_minimal' => 'Soft Minimal',
        'dark_mode' => 'Dark Header',
        'quick_green' => 'Accounting Green',
        'cloud_blue' => 'Cloud Blue',
        'tally_classic' => 'Tally Classic',
        'sheet_grid' => 'Sheet Grid',
        'bank_form' => 'Bank Form',
        'modern_green' => 'Modern Green',
        'corporate_navy' => 'Corporate Navy',
        'modern_card' => 'Modern Card',
        'swiss_grid' => 'Swiss',
        'sidebar_band' => 'Sidebar Band',
        'bangla_heritage' => 'All Bangla',
        'premium_gold' => 'Premium Gold',
        'editorial_serif' => 'Editorial Serif',
        'ink_saver' => 'Ink Saver',
        'seal_boxes' => 'Seal Boxes',
    ],
    'paper_transfer' => 'Paper for the money handover slip',
    'paper_note' => 'Paper for debit and credit notes',
    'note_footnote' => 'Footnote on debit and credit notes',
    'voucher_maker_checker' => 'Whoever writes a voucher does not post it; someone else does',

    // ⭐ Fixed assets phase 3
    'asset_revaluation' => 'Allow fixed asset revaluation (off means the cost model)',

    // ⭐ Fixed assets phase 4
    'paper_asset_labels' => 'Paper for asset labels',
];
