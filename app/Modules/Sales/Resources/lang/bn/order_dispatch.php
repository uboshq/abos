<?php

declare(strict_types=1);

/*
 * ⭐ আদেশ থেকে রওনার সময় — বিক্রয় পরিকল্পনা সংস্করণ ২ §৯ (ঘ) ([[DeliveryReports::ORDER_TO_DISPATCH]])।
 */
return [
    'title' => 'আদেশ থেকে রওনার সময়',
    'do' => 'DO',
    'invoice' => 'বিল',
    'submitted_at' => 'পাঠানো',
    'approved_at' => 'অনুমোদন',
    'invoiced_at' => 'বিল ও চালান',
    'hours_to_approve' => 'পাঠানো → অনুমোদন (ঘণ্টা)',
    'hours_to_invoice' => 'অনুমোদন → বিল (ঘণ্টা)',
    'hours_to_gate' => 'বিল → গেট পাস (ঘণ্টা)',
    'hours_to_leave' => 'গেট পাস → রওনা (ঘণ্টা)',
    'hours_total' => 'মোট (ঘণ্টা)',
    'dispatched' => 'রওনা হয়েছে',
    'summary' => 'গড় সময়, পাঠানো থেকে রওনা',
    'summary_text' => 'গড় :hours ঘণ্টা — :count DO রওনা হয়েছে',
    'none_left' => 'এই সময়ে কোনো DO রওনা হয়নি।',
];
