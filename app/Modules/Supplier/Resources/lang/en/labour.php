<?php

declare(strict_types=1);

// ⭐ Unloading and loading labour — owner, 18 Nov 2026 ("ok"); [[LabourCost]], [[UnloadingSplit]]
return [
    'unloading' => 'Unloading cost',
    'loading' => 'Loading cost',
    'how' => 'How was it paid?',

    'mode_cash' => 'Paid in cash now',
    'mode_due' => 'Due to labour contractor',
    'mode_supplier' => 'Paid by the supplier',
    'mode_none' => 'Not needed',
    'not_chosen' => 'Not chosen yet',

    'amount' => 'Amount paid',
    'till' => 'From which cash till',
    'contractor' => 'Labour contractor',
    'reason' => 'Why not needed',
    'expected' => 'Expected: :cartons cartons × :rate = :amount',
    'expected_short' => 'Expected',
    'cartons' => 'Cartons',
    'rate_per_carton' => 'Rate per carton',
    'hand_rate' => 'Unload rate per carton (blank = shared equally)',
    'hand_rate_short' => 'Unload/ctn',
    'share' => 'Unloading share',
    'landed' => 'Landed cost (with unloading)',
    'amount_hint' => 'Blank = cartons × the rate per carton. Write what was really paid.',
    'split_hint' => 'Lines with a hand-set rate per carton take cartons × that rate; the rest is shared equally per carton over the other lines; the last line takes the paisa left over, so the total is always the amount paid.',
    'no_contractor_party' => 'No supplier of the type "Labour Contractor" yet — add one in Suppliers, or pay in cash.',
    'save' => 'Save unloading',
    'saved' => 'Unloading cost saved.',
    'loading_saved' => 'Loading cost saved.',
    'loading_only_confirmed' => 'Loading can be written here only on a confirmed challan — on a draft, write it on the challan form.',
    'loading_already' => 'Loading is already written on this challan — it is not booked twice.',

    // ⛔ walls
    'pick_one' => 'Choose how the labour was paid: cash now, due to a contractor, paid by the supplier, or not needed.',
    'needs_reason' => 'Write a short reason why it was not needed.',
    'needs_amount' => 'Write the amount paid (more than zero).',
    'needs_till' => 'Choose the cash till the money came from.',
    'cash_only' => 'Only a cash till — bank or mobile money is not cash in hand.',
    'not_your_till' => 'This cash till is not yours — pay from your own till.',
    'needs_contractor' => 'Choose the labour contractor the money is owed to.',
    'not_a_contractor' => 'This supplier is not a Labour Contractor — choose a supplier of the "Labour Contractor" kind, or pay in cash.',
    'unloading_required' => 'Unloading cost is not set on :no — choose cash, due to a contractor, paid by the supplier, or not needed, before it is confirmed.',
    'loading_required' => 'Loading cost is not set on :no — write the amount or choose "not needed" before the challan is confirmed.',
    'hand_over_amount' => 'The hand-set lines come to :hand, more than the :amount paid. Lower a rate or write the real amount.',
    'only_draft' => ':no is no longer a draft — the unloading cost cannot change now.',

    // ⓘ ledger narrations
    'unloading_narration' => 'Unloading :no (:cartons cartons)',
    'loading_narration' => 'Loading :no',

    // ⓘ the labour payable list (2117)
    'payable_title' => 'Labour payable',
    'payable_link' => 'Labour payable (who is owed how much)',

    // ⓘ Day Closing
    'day_title' => 'Loading and unloading labour',
    'day_unloading' => 'Unloading (into stock cost)',
    'day_loading' => 'Loading (selling expense)',
    'day_paper' => 'Paper',
    'day_kind' => 'Kind',
    'day_party' => 'Supplier / customer',
    'day_how' => 'How',
    'day_total' => 'Labour today',
    'day_payable' => 'Owed to labour contractors (2117) at day end',
    'day_none' => 'No labour cost today.',
    'day_column' => 'Loading',
];
