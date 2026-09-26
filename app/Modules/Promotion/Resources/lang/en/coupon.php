<?php

declare(strict_types=1);

/* Coupons — spec §7 Coupon; refusals come from [[CouponDesk]] under the `coupon` key */
return [
    'title' => 'Coupons',
    'code' => 'Coupon code',
    'max_uses' => 'Total uses allowed',
    'max_uses_per_customer' => 'Uses per customer',
    'customer' => 'Only for this customer',
    'used_count' => 'Used',
    'uses_left' => 'Uses left',
    'valid_from' => 'Valid from',
    'valid_to' => 'Valid to',
    'is_active' => 'Active',
    'issue' => 'Issue coupons',
    'count' => 'How many',

    /* Issuing */
    'not_coupon_offer' => ':code is not a coupon offer — coupons can only be issued for a coupon-type offer.',
    'one_code_one_coupon' => 'A typed code makes exactly one coupon. Leave the code empty to generate several.',
    'count_range' => 'Between 1 and :max coupons can be issued at once.',
    'code_shape' => 'Use 3 to 40 letters, digits or dashes for the code.',
    'code_taken' => 'The code :code is already in use.',
    'max_uses_min' => 'A coupon must be usable at least once.',
    'per_customer_range' => 'Uses per customer must be between 1 and the total (:max).',
    'customer_unknown' => 'That customer was not found.',
    'dates_backwards' => 'The end date is before the start date.',
    'dates_outside_offer' => 'The coupon dates must fall within the offer (:from to :to).',

    /* Redeeming */
    'not_found' => 'No active coupon with the code :code.',
    'outside_dates' => 'The coupon :code is not valid today.',
    'offer_not_live' => 'The offer behind :code is not running today.',
    'used_up' => 'The coupon :code has already been used :max time(s), its full allowance.',
    'customer_limit' => 'This customer has already used :code :max time(s), the most allowed.',
    'needs_customer' => 'The coupon :code is limited per customer — choose the customer on the bill first.',
    'bound_elsewhere' => 'The coupon :code was issued for another customer.',
    'already_on_bill' => 'The coupon :code is already on this bill.',
    'customer_mismatch' => 'The customer given does not match the customer on the line.',
];
