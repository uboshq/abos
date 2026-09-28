<?php

declare(strict_types=1);

/*
 * The coupon screen — [[PromotionCouponController]].
 * Field labels (code, total uses…) live in `coupon.php`; only the screen's own words are here.
 */
return [
    'menu' => 'Coupons',
    'all_offers' => 'Coupons of all offers',
    'offer' => 'Offer',
    'back_to_offer' => 'Back to the offer',
    'search' => 'Search code',
    'search_button' => 'Search',
    'issue_heading' => 'Issue new coupons',
    'code_hint' => 'Leave blank and the system makes the code (series + random tail).',
    'count_hint' => 'A typed code makes exactly one. The system makes at most :max at a time.',
    'customer_hint' => 'Customer number — leave blank and anyone may use it.',
    'dates_hint' => 'Leave blank for the whole offer period.',
    'any_customer' => 'Anyone',
    'no_limit' => 'No limit',
    'whole_offer' => 'Whole offer period',
    'off' => 'Off',
    'none' => 'No coupons have been issued yet.',
    'not_coupon_type' => ':code is not a coupon offer — coupons cannot be issued here.',
    'offer_final' => ':code has ended or was cancelled — no new coupons can be issued.',
    'pick_offer' => 'To issue coupons, come here from the offer page.',
    'made_one' => 'Coupon :code was issued.',
    'made_many' => ':count coupons were issued.',
    'refused' => 'The coupon could not be applied.',
];
