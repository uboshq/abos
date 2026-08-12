<?php

declare(strict_types=1);

return [
    'code_taken' => 'Another product already uses this code.',
    'barcode_taken' => 'Another product already uses barcode :barcode — a scanner could not tell them apart.',
    'not_negative' => ':field cannot be negative.',
    'nothing_moves' => 'No quantity was given — a zero row only lengthens the ledger.',

    // Batch allocation
    'qty_positive' => 'Say how much — a quantity of zero picks no lot at all.',
    'batch_short' => 'Not enough :product across its lots — :short short. Unexpired lots only; check whether some has expired.',

    // The printed price is a ceiling, and it is per lot
    'above_printed_price' => 'Lot :batch is printed at :mrp — :asked is above it, and selling above the printed price is not allowed.',

    // The 2D barcode on the pack
    'barcode_truncated' => 'The (:ai) part of the barcode is cut short — a damaged scan would look up the wrong product.',
    'barcode_unknown_part' => "Unrecognised part ':part' in the barcode — is this a GS1 code, or is the scanner set up wrongly?",
    'barcode_bad_date' => "Cannot read ':date' as a date — GS1 expects YYMMDD.",
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
    'no_cost_layer' => ':qty of :product has no purchase cost on record — the shelf has it, but nothing says which consignment it came in on. Bring it in with a stock adjustment that carries a rate, then try again.',
    'return_exceeds_issue' => 'More of :product cannot come back than went out.',
    'layer_already_used' => 'Goods from :document have already left, so it can no longer be cancelled — the sales they went into are already costed at that price. Use a purchase return instead.',
    'surplus_needs_rate' => 'Surplus found in a count needs a rate — only you know which consignment it came from, and without a rate it can never leave the shelf again.',
    'issue_needs_qty' => 'The quantity issued must be more than zero.',
    'issue_more_than_stock' => 'Only :have is on the shelf — no more than that can go out.',
];
