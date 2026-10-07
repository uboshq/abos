<?php

declare(strict_types=1);

/*
 * ⭐ বাকি ও আদায় — নতুন বাকির দেয়াল আর তার রিপোর্ট (৫ অক্টোবর ২০২৬; [[CreditExposure::stopsFor()]])।
 */
return [
    'overdue_stop' => ':days দিনের পুরনো বাকি আছে (:bills, :amount) — নতুন বাকি বন্ধ',
    'uncleared_cheques' => 'ক্লিয়ার না হওয়া চেক',
    'blocked_stop' => 'এই গ্রাহকের বাকি বন্ধ (:reason) — পুরো টাকা দিলে কেনা যাবে',
    // ── বাকি ও আদায়ের রিপোর্ট ([[CreditControlReports]]) ──
    'use_title' => 'বাকির সীমার ব্যবহার',
    'blocked_title' => 'বাকি বন্ধের তালিকা',
    'risk_title' => 'ঝুঁকির গ্রাহক',
    'history_title' => 'বাকির সীমা বদলের ইতিহাস',
    'code' => 'কোড',
    'customer' => 'গ্রাহক',
    'limit' => 'বাকির সীমা',
    'outstanding' => 'বকেয়া',
    'held' => 'আটকে আছে',
    'available' => 'অবশিষ্ট সীমা',
    'used' => 'ব্যবহার %',
    'reason' => 'কারণ',
    'blocked_by' => 'বন্ধ করেছেন',
    'blocked_on' => 'বন্ধের দিন',
    'overdue_amount' => 'পুরনো বাকি',
    'bounced_cheques' => 'চেক ফেরত (৯০ দিন)',
    'over_limit' => 'সীমার বেশি',
    'risk_flags' => 'ঝুঁকির নোট',
    'requested_on' => 'অনুরোধের দিন',
    'asked_limit' => 'চাওয়া সীমা',
    'status' => 'অবস্থা',
    'requested_by' => 'অনুরোধ করেছেন',
    'decided_on' => 'সিদ্ধান্তের দিন',
    'status_pending' => 'সইয়ের অপেক্ষায়',
    'status_approved' => 'অনুমোদিত',
    'status_rejected' => 'নামঞ্জুর',
    'status_cancelled' => 'বাতিল',
];
