<?php

declare(strict_types=1);

return [
    'count_not_draft' => 'This count has already been accepted or cancelled - only a draft can be accepted.',
    'code_taken' => 'Another product already uses this code.',
    'barcode_taken' => 'Another product already uses barcode :barcode — a scanner could not tell them apart.',
    'not_negative' => ':field cannot be negative.',
    'nothing_moves' => 'No quantity was given — a zero row only lengthens the ledger.',

    /*
     * The message names the number, not just "too many" — the person is
     * standing at the goods with the paper in hand, and "how much is
     * left" is their very next question.
     */
    'more_than_unplaced' => 'That much is not waiting to be placed — :waiting remains. Placing more would put goods on the books that are not in the warehouse.',

    // Packs — box, strip, piece
    'unknown_unit' => 'That unit is not in this company list.',
    'product_has_no_unit' => ':product has no unit set, so there is nothing to convert into.',
    'units_do_not_meet' => ':entered and :stocking do not share a base unit, so there is no honest way to turn one into the other.',
    'unit_does_not_split' => 'One :entered does not divide into whole :stocking — set the unit to allow fractions, or enter the quantity in :stocking.',

    // Batch allocation
    'qty_positive' => 'Say how much — a quantity of zero picks no lot at all.',
    'free_batch_short' => 'Not enough free :product across its lots — :short short. Expired lots were not counted.',
    'batch_no_required' => ':product is tracked by lot, so the lot number has to be written down as the goods come in — there is no way to learn it later.',
    'batch_short' => 'Not enough :product across its lots — :short short. Unexpired lots only; check whether some has expired.',
    'batch_untracked_stock' => ':qty of :product is on the shelf, but it arrived before lot tracking began — there is no way to know which lot it belongs to, so it cannot be sold. Give it a lot on the Stock > Assign a lot screen — the goods do not have to be moved.',
    'reprice_needs_a_reason' => 'Say why — in six months this line will be the only answer to that question.',
    'not_a_price' => 'A price must be a number, and not negative.',

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

    // ── Opening stock, from a file ──────────────────────────────────
    'opening_must_be_positive' => ':column must be more than zero — an opening of nothing, or at no cost, says nothing.',
    'opening_already_set' => ':product already has opening stock in :warehouse. Twice in the file would double the stock, and both rows would look right.',

    /* Recipes — every mistake here lands straight in the store figures. */
    'recipe_needs_lines' => 'Add at least one ingredient. Without them the sale takes nothing off the shelf.',
    'recipe_self_reference' => 'A dish cannot be its own ingredient.',
    'recipe_duplicate_line' => 'The same ingredient twice is not allowed — raise the amount instead.',
    'recipe_waste_too_high' => 'Waste must be under 100%, or nothing survives.',

    /* Batch cooking. */
    'production_not_draft' => 'This paper is already confirmed — doing it again would take the ingredients twice.',
    'production_needs_warehouse' => 'Say which store the ingredients come from.',
    'production_recipe_empty' => 'The recipe for :product has no ingredients — cooking it would make food out of nothing.',
    'production_recipe_no_yield' => 'The recipe yields nothing — without knowing how many one cooking makes there is no arithmetic.',
    'production_not_enough' => 'Not enough :ingredient to cook :product — :available left.',

    /* Physical stock count — the book against the shelf. */
    'count_warehouse_required' => 'Say which store is being counted — a count with no store cannot be matched against any book.',
    'count_needs_lines' => 'Add at least one product with a counted quantity — an empty count says nothing.',
    'count_product_missing' => 'One of the counted products no longer exists.',
    'count_qty_negative' => 'A counted quantity cannot be less than zero — count what is there, or zero if the shelf is empty.',
    'count_duplicate_product' => 'The same product appears twice — count it once and enter the total found.',

    // Checked by reading the file, not its name: a client can call
    // anything image/png, so the message says the file is not an image
    // rather than that the type is wrong.
    'image_only' => 'That file is not an image. Use JPEG, PNG or WebP — the right name is not enough if the contents are not an image.',
    'location_not_in_warehouse' => 'That place is not in this warehouse.',
    /* Product packs — ProductPackService, 19 September 2026 */
    'unit_locked' => 'This product’s stock and papers are counted in :unit — changing the unit now would give every old number a new meaning (12 pieces suddenly 12 cartons). Open a new product instead.',
    'unit_change_needs_packs' => 'The packs were written against the old unit — write the pack table again for the new unit and save.',
    'pack_is_base' => ':unit is this product’s own unit — it is always 1 and needs no pack row.',
    'pack_twice' => ':unit is written twice — one product has one size per unit.',
    'pack_qty_positive' => 'How much is in 1 :unit must be a number above zero.',
    'pack_of_itself' => '1 :unit cannot be measured in :unit — pick another unit.',
    'pack_circle' => 'The packs measure each other in a circle (carton in boxes, box in cartons) — measure one of them in the product’s own unit.',
    'pack_per_unknown' => 'The unit it is measured in is not in this product’s table — add that row first.',
    'pack_splits_base' => '1 :unit comes to part of a :base — but :base does not split. Check the size.',
    'pack_default_unknown' => 'The pack chosen as a default is not in this product’s table.',
    'already_that_unit' => ":product's stock is already counted in :unit — there is nothing to bring down.",
    'barcode_is_not_alone' => 'Barcode :barcode belongs to more than one product, so there is no way to say which one this stock is for. Fix the barcodes first, or use the product code.',

    'lot_of_another_product' => 'Lot :lot does not belong to :product. Putting a lot from a different product on these goods would send the recall call to the wrong buyers.',
    'lot_needs_qty' => 'Say how much gets the lot — zero does nothing.',
    'lot_over_untracked' => 'Only :have of :product is lot-less in this warehouse, so no more than that can be given a lot. Past that, goods whose lot is already known would move into the new lot, and the same carton would sit in two lots.',
    'lot_over_untracked_free' => 'Only :have free :product is lot-less — no more than that.',
    'qc_product_required' => 'Pick the product that was inspected.',
    'qc_needs_quantity' => 'How much was inspected?',
    'qc_already_decided' => 'This inspection already has a decision.',
    'qc_unknown_result' => 'That is not a decision this system knows.',
    'qc_parts_must_add_up' => 'Accepted plus rejected must come to :total.',
    'qc_reason_missing' => 'The hold reason :code is missing from the master list, so the goods cannot be held.',
    'serial_not_tracked' => ':product does not keep a number for every piece. Tick it on the product first.',
    'serial_needs_numbers' => 'No numbers were given.',
    'serial_twice' => 'The number :no was given twice.',
    'serial_taken' => 'These numbers are already on the books: :no',
    'serial_unknown' => 'No piece carries the number :no.',
    'serial_already_out' => 'The piece :no has already gone out.',
    'qc_dispose_needs_verdict' => 'Goods cannot be disposed of before the inspection has a verdict.',
    'qc_dispose_needs_qty' => 'How much to dispose of has to be a positive number.',
    'qc_dispose_needs_place' => 'This paper names no product or warehouse, so nothing can be taken off the shelf.',
    'qc_dispose_over' => 'This paper holds :held - no more than that can be disposed of.',
];
