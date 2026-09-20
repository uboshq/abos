<?php

declare(strict_types=1);

/* ঝুঁকির ড্যাশবোর্ড — মানচিত্র §১, ২০ সেপ্টেম্বর ২০২৬। */
return [
    'title' => 'ঝুঁকির ড্যাশবোর্ড',
    'note' => 'আজ যেগুলো দেখা দরকার — টাকা, পাওনা, সীমা, মেয়াদ আর বসে থাকা মাল।',
    'all_clear' => 'এখন দেখার মতো কিছু নেই।',
    'all_clear_hint' => 'নগদ শূন্যের নিচে নামছে না, মেয়াদ পেরোনো পাওনা নেই, সুবিধার সীমাও ভরেনি।',
    'level_bad' => 'আজই দেখুন',
    'level_warn' => 'সামনে আসছে',
    'look' => 'দেখুন →',

    'cash_runs_out' => 'নগদ ফুরিয়ে আসছে',
    'cash_runs_out_hint' => ':until তারিখের মধ্যে হাতে টাকা শূন্যের নিচে নামে।',

    'receivables_overdue' => 'মেয়াদ পেরোনো পাওনা',
    'receivables_overdue_hint' => 'এখনই পাওয়ার কথা; মোট পাওনা :total।',

    'payables_due' => 'এখনই দেওয়ার দেনা',
    'payables_due_hint' => 'সরবরাহকারীকে এখনই দেওয়ার কথা; মোট দেনা :total।',

    'facility_used' => 'ব্যাংক সুবিধার সীমা ভরে আসছে',
    'facility_used_hint' => ':name — :used ব্যবহার হয়েছে, সীমা :ceiling।',

    'deposits_maturing' => 'জমার মেয়াদ শেষ হচ্ছে',
    'deposits_maturing_hint' => ':days দিনের মধ্যে, মোট :amount।',

    'loan_instalments' => 'ঋণের কিস্তি',
    'loan_instalments_hint' => ':days দিনের মধ্যে :count টা কিস্তি।',

    'stock_stuck' => 'বসে থাকা মালে আটকে আছে',
    'stock_stuck_hint' => ':count টা পণ্য :days দিন ধরে নড়েনি।',
];
