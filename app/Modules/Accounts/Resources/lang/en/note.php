<?php

declare(strict_types=1);

/*
 * ডেবিট ও ক্রেডিট নোট — মানচিত্র §৭।
 *
 * ⚠️ ফেরতের কাগজের সাথে সীমানাটা একটাই বাক্যে: নোটে মাল নড়ে না।
 */

return [
    'title' => 'Debit and credit notes',
    'subtitle' => 'Correcting the money without moving any goods',
    'credit_note' => 'Credit note',
    'debit_note' => 'Debit note',
    'credit_hint' => 'Given to a customer — what they owe us goes down',
    'debit_hint' => 'Given to a supplier — what we owe them goes down',
    'new_credit' => 'New credit note',
    'new_debit' => 'New debit note',
    'party' => 'To whom',
    'against_no' => 'Against which paper',
    'amount' => 'Amount',
    'tax_amount' => 'VAT',
    'total' => 'Total',
    'reason' => 'Reason',
    'reason_price_correction' => 'The price was wrong',
    'reason_damaged_goods' => 'Goods damaged, not coming back',
    'reason_short_delivery' => 'Short on the count',
    'reason_agreed_discount' => 'A discount agreed later',
    'reason_other' => 'Another reason',
    'saved' => 'Saved as a draft — it reaches the books when you confirm it',
    'confirm' => 'Confirm',
    'confirmed' => 'The note is in the books',
    'cancel' => 'Cancel',
    'cancelled' => 'The note is cancelled and its entries are reversed',
    'cancel_reason_prompt' => 'Why is it being cancelled?',
    'none_yet' => 'No notes have been written yet',
    'amount_must_be_positive' => 'The amount has to be more than zero',
    'only_a_draft_can_be_confirmed' => 'Only a draft note can be confirmed',
    'unknown_direction' => 'That is not a direction a note can have',
    'missing_account' => 'The chart has no account :code — install it first',
    'no_goods_move' => 'No goods move. If goods come back it is a return, not a note.',
];
