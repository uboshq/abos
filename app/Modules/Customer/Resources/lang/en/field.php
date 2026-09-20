<?php

declare(strict_types=1);

return [
    'code' => 'Code',
    'name' => 'Name',

    'customer_name_en' => 'Customer name (English)',
    'customer_name_bn' => 'Customer name (Bangla)',
    'name_en' => 'Name (English)',
    'name_bn' => 'Name (Bangla)',
    'phone' => 'Mobile',
    'email' => 'Email',
    'type' => 'Type',
    'address_en' => 'Address (English)',
    'address' => 'Address',
    'full_address' => 'Full address',
    'last_billed_on' => 'Last bill',
    'last_collected_on' => 'Last collection',
    'address_bn' => 'Address (Bangla)',
    'credit_limit' => 'Credit limit (amount)',
    'credit_days' => 'Credit duration (days)',
    'opening_balance' => 'Opening balance',
    'opening_date' => 'Opening date',
    'outstanding' => 'Outstanding',

    // Ageing buckets, in days
    'bucket_current' => '0–30 days',
    'bucket_30' => '31–60 days',
    'bucket_60' => '61–90 days',
    'bucket_90' => '90+ days',
    'state' => 'Status',
    'point' => 'Point',
    'area' => 'Area',
    'owner_name' => 'Owner name',
    'available_limit' => 'Available limit',

    // 'last_purchase' moved out — it is a sales fact, so the key lives
    // there now (sales::field.last_purchase, SalesFacts)
    'billed_in_period' => 'Billed',
    'collected_in_period' => 'Collected',
    'net_change' => 'Net change',
    'portal_state' => 'State',
    'portal_code' => 'Login code',
    'portal_last_login' => 'Last signed in',
    'portal_password' => 'Password',
    'portal_password_again' => 'Password again',

    /* Import template columns — see lang/bn/field.php. */
    'party_type' => 'Party type',
    'payment_term' => 'Payment term',
];
