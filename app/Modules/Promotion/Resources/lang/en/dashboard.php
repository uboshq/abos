<?php

declare(strict_types=1);

return [
    'title' => 'Trade Promotion',
    'subtitle' => 'What is running, what is coming, and what has quietly run out',

    'active' => 'Running',
    'active_hint' => 'Applies to bills written today',

    'upcoming' => 'Coming up',
    'upcoming_hint' => 'Approved, not started yet',

    /* A warning, not a count — above zero means something forgot to run */
    'lapsed' => 'Past its end date',
    'lapsed_hint' => 'The dates ended, but the status still says running',

    'used_this_month' => 'Campaign results — how often each offer applied this month',
    'used_this_month_hint' => 'Reversed offers left out; the sales comparison lives on the sales dashboard',
    'ending_soon' => 'Ending soon — live offers',
    'ending_soon_hint' => 'Days from today until each ends',
    'within_7' => 'Within 7 days',
    'within_15' => '8–15 days',
    'within_30' => '16–30 days',
    'later' => 'After 30 days',

    // The rest of the spec: discount, gifts, coupons, budget, live offers (6 Oct 2026)
    'discount_this_month' => 'Discount given this month',
    'discount_this_month_hint' => ':range — amount and percent discounts, reversed ones left out',
    'gifts_this_month' => 'Gifts given this month',
    'gifts_this_month_hint' => ':range — gift slips out of the godown, fully returned ones left out',
    'coupons' => 'Coupons — issued vs redeemed',
    'coupons_issued' => 'Issued',
    'coupons_redeemed' => 'Redeemed',
    'budget_used' => 'Budget vs spent — open offers',
    'budget_ceiling' => 'Budget',
    'budget_spent' => 'Spent',
    'live_list' => 'Running now — which offer ends when',
    'live_list_empty' => 'No offer is running right now.',
    'col_code' => 'Code',
    'col_name' => 'Offer',
    'col_type' => 'Type',
    'col_ends' => 'Ends',
    'col_days_left' => 'Days left',
];
