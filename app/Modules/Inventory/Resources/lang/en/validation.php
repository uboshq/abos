<?php

declare(strict_types=1);

return [
    'code_taken' => 'Another product already uses this code.',
    'barcode_taken' => 'Another product already uses barcode :barcode — a scanner could not tell them apart.',
    'not_negative' => ':field cannot be negative.',
    'nothing_moves' => 'No quantity was given — a zero row only lengthens the ledger.',
    'hold_needs_quantity' => 'Say how much to hold.',
    'wrong_reason_context' => 'That reason is not for holding stock.',
    'not_enough_available' => 'Not that much is available — there is :available.',
    'not_that_much_held' => 'Not that much is held — there is :held.',
    'not_enough_on_floor' => 'There is not that much :product in :warehouse — there is :have.',
    'warehouse_code_taken' => 'Another warehouse already uses this code.',
    'not_enough_free' => 'Only :have free stock of :product is in :warehouse — no more can be given.',

    // Transfer
    'no_lines' => 'A transfer needs at least one line — otherwise it moves nothing.',
    'unknown_product' => 'That product is not in this company list.',
    'unknown_warehouse' => 'That warehouse is not in this company list.',
    'same_warehouse' => 'A warehouse cannot transfer to itself — pick a different destination.',
    'not_enough_to_transfer' => 'Only :available of :product is in :warehouse — no more can be sent.',
    'only_draft_dispatches' => ':no is not a draft, so it cannot be dispatched again.',
    'only_dispatched_receives' => ':no has not been dispatched yet — there is nothing to receive.',
    'received_cannot_cancel' => ':no has arrived — transfer it back instead of cancelling.',
    'only_draft_edits' => ':no is not a draft — it cannot be changed once dispatched.',
    'already_cancelled' => ':no was already cancelled.',
    'no_financial_year' => ':date does not fall in any open financial year.',
];
