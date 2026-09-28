<?php

declare(strict_types=1);

/*
 * গেট পাস — রওনার মুহূর্তে নিজে জন্মায় ([[GatePassService]]), মালিকের "Delivery Processing" নকশা।
 */
return [
    'title' => 'গেট পাস',
    'subtitle' => 'রওনার মুহূর্তে নিজে তৈরি — প্রতিটা রওনার একটা',
    'empty' => 'কোনো গেট পাস নেই।',
    'search' => 'গেট পাস, চালান, গ্রাহক বা গাড়ির নম্বর…',
    'show_cancelled' => 'বাতিলগুলোও দেখান',

    'column' => [
        'number' => 'গেট পাস',
        'challan' => 'চালান',
        'customer' => 'গ্রাহক',
        'vehicle' => 'গাড়ি',
        'driver' => 'চালক',
        'issued_at' => 'কখন',
        'issued_by' => 'যিনি দিলেন',
        'status' => 'অবস্থা',
        'trip' => 'ট্রিপ',
    ],

    'status' => [
        'issued' => 'দেওয়া হয়েছে',
        'cancelled' => 'বাতিল',
    ],

    'print' => 'ছাপুন',
    'cancel' => 'বাতিল করুন',
    'cancel_reason' => 'বাতিলের কারণ',
    'cancelled' => 'গেট পাস :no বাতিল হলো।',
    'cancelled_note' => 'বাতিল — :reason (:by, :at)',
    'reason_required' => 'বাতিলের কারণ লিখুন।',
    'already_cancelled' => 'গেট পাস :no আগেই বাতিল।',
    'view_only' => 'গেট পাস রওনার মুহূর্তে তৈরি হয়; বদলানো যায় না, কেবল কারণসহ বাতিল।',
    'on_challan' => 'এই চালানের গেট পাস',
];
