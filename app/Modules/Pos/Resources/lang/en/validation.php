<?php

declare(strict_types=1);

/*
 * পিওএস-এর নিজের কথা — কাউন্টার আর শিফটের যে কী কেবল এই মডিউল পড়ে, বিক্রয়ের একই নামের ফাইল থেকে
 * হুবহু সরানো (পিওএস ধাপ ২, ১১ অক্টোবর ২০২৬)। ⓘ দুই মডিউলের ভাগের কী (গ্রাহক, মোট, ছাড়…) বিক্রয়েই থাকে।
 */
return [
    'unknown_payment_method' => 'That payment method was not found, or it has been switched off.',
    'reference_required' => 'Money taken through :method needs its transaction number — without it there is no way to reconcile against the statement later.',
    // Counter shifts
    'till_already_open' => 'A shift is already open on :till. Two people answerable for one drawer means nobody is — close the other one first.',
    'you_already_have_a_shift' => 'You already have a shift open. With two open there would be no saying which drawer a shortfall came from.',
    'shift_already_closed' => 'That shift is already closed.',
    // Bills held at the counter
    'not_parked' => 'That bill is not waiting at the counter, so there is nothing to bring back.',
    'already_done' => 'Bill :no has already been completed — it cannot be picked up again.',
    'approved_at_counter' => 'Approved at the counter by :name.',
    'no_payment_parts' => 'None of the payment rows has an amount.',
    'split_does_not_add_up' => 'The rows add to :sum but :paid was taken — the two do not agree.',
    'change_needs_cash' => 'Change has to come out of a cash row — money sent by bKash or card cannot be handed back.',
    'no_such_bill' => 'No confirmed bill numbered :no was found.',
];
