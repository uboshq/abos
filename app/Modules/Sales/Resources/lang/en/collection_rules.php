<?php

declare(strict_types=1);

/*
 * Customer collection rules — the owner's design 100 (21 Oct 2026). The screen and the phone show exactly what the server says.
 */
return [
    'customer_fixed' => 'A draft\'s customer cannot change — write a new collection.',
    'rounding_over_limit' => 'Rounding ৳:amount — the limit is ৳:limit. Above it this is a discount; choose "discount" and send it for the owner\'s signature.',
    'unknown_item' => 'That open paper is not this customer\'s, or is no longer open.',
    'unknown_kind' => 'Unknown paper kind: :kind',
    'unknown_difference' => 'A difference can only be a discount or rounding.',
    'over_open' => ':no has ৳:open open — no more can be applied.',
    'credit_over_bills' => 'Credit ৳:credit but the chosen bills are ৳:bills — a credit is applied only to this paper\'s bills.',
    'reference_used' => 'Reference ":reference" was already taken into this account — :no (:url). The same money is not taken twice.',
    'vds_on' => ':no — VAT withheld by the customer (VDS), awaiting the Mushak-6.6 certificate',
    'ait_on' => ':no — income tax withheld by the customer (AIT)',
    'discount_on' => ':no — sales discount on collection',
    'rounding_on' => ':no — rounding difference',
    'discount_awaiting' => 'This collection carries a discount — it needs the owner\'s signature; the request is sent. The collection stays a draft; press "Post" again once signed.',
    'discount_rejected' => 'The discount request came back. Change the discount and send it again.',
    'attachment_required' => 'This company requires the slip, advice or screenshot on a collection — attach it first.',
    'other_branch_account' => 'The money account belongs to another branch — a collection sits in the customer\'s branch, and inter-branch posting is not switched on yet (awaiting the owner\'s decision).',
    'second_person_cancels' => 'You cannot reverse a posted collection you made yourself — a second person reverses it, with the reason.',
    'unknown_transfer_mode' => 'Unknown transfer mode — choose one from the setup list.',
    'sign_mismatch' => 'The line\'s sign does not match its kind (:kind ৳:amount) — credit notes and prior credits are negative, bills and debit notes positive.',
];
