<?php

declare(strict_types=1);

return [
    'none_yet' => 'No products yet.',
    'no_warehouses' => 'No warehouses yet.',
    'search_placeholder' => 'Search name, code or barcode...',
    'warehouse_search' => 'Search name or code...',
    'created' => 'Product added.',
    'updated' => 'Product updated.',
    'deactivated' => 'Product deactivated.',
    'activated' => 'Product is active again.',
    'warehouse_created' => 'Warehouse added.',
    'warehouse_updated' => 'Warehouse updated.',
    'code_auto' => 'Leave blank and the code will be filled in.',
    'barcode_hint' => 'What the scanner sends. Must be unique.',
    'reorder_hint' => 'You will be told when stock falls below this. 0 means no alert.',
    'no_movements' => 'No movements for this product yet.',
    'stock_math' => 'What is on the floor, less what is reserved and held, is what may be sold.',
    'adjusted' => 'Adjusted — difference :difference.',
    'adjust_matched' => 'The count matched the books — no adjustment was needed.',
    'held' => 'Stock held.',
    'released' => 'Stock released.',
    'hold_note' => 'Stock cannot be held without a reason. Holding back for a better price is a reason too, and it is not a fault.',
    'adjust_note' => 'Enter what was actually found. The difference is what goes in the ledger, not the new figure — so it stays possible to ask where the stock went.',
    'count' => '{0} No products|{1} 1 product|[2,*] :count products',
    'warehouse_count' => '{0} No warehouses|{1} 1 warehouse|[2,*] :count warehouses',

    // Transfer
    'transfer_created' => 'The transfer was created — dispatching it holds the goods.',
    'transfer_updated' => 'The transfer was updated.',
    'transfer_dispatched' => 'On the way — the goods are held at the source and cannot be sold.',
    'transfer_received' => 'Arrived — the goods left the source and are on the destination shelf.',
    'transfer_cancelled' => 'The transfer was cancelled — the held goods were released.',
    'no_transfers' => 'No transfers yet.',
    'transfer_search' => 'Search by number…',
    'transfer_note' => 'Dispatching holds the goods at the source; they only reach the destination when received.',
    'transfer_holding' => 'The goods are still at :warehouse but held — waiting to be received.',
    'transfer_on_the_way' => ':no — dispatched',
    'transfer_left' => ':no — left the source warehouse',
    'transfer_arrived' => ':no — onto the destination shelf',
    'lines' => 'Lines',
    'cancel_reason' => 'Reason for cancelling',
    'surplus_rate_note' => 'Only needed when the count finds more than the books say. Leave it empty for a shortage — the cost then comes from the goods own consignment.',

    // Opening stock — the day the old books are carried in
    'opening_narration' => 'Opening stock',
    'opening_saved' => ':product — :qty onto the shelf, :value into Inventory.',

    // Lot corrections — both land in the audit trail with their reason
    'batch_repriced' => 'The printed price on lot :batch has been changed. Past sales are untouched.',
    'batch_expiry_corrected' => 'The expiry on lot :batch has been corrected.',
    'opening_needs_qty' => 'The quantity must be greater than zero.',
    'opening_needs_cost' => 'Opening stock needs a rate. Goods at zero cost are not an asset on the balance sheet, yet selling them would show the whole price as profit.',
    'opening_already_done' => 'Opening stock for :product at :warehouse has already been entered. Use Count & Adjust to change it.',
    'opening_too_late' => ':product has already moved at :warehouse, so the moment for opening stock has passed. FIFO draws layers in the order they were laid down — entering it now would put the opening goods at the back of the queue and quietly distort the profit. Use Count & Adjust instead.',
    'opening_none' => 'No opening stock has been entered yet.',
    'opening_note' => 'What was on the shelf the day the old books were carried into ABOS. The rate is needed beside the quantity — without it the first sale asks FIFO what the goods cost, and there is no answer.',
    'opening_total' => 'Entered so far',
    'opening_search' => 'Search by product name or code…',
    'issue_note' => 'Goods that leave without being sold — entertainment, gifts, owner use, samples.',
    'issued' => ':qty went out — :reason, and the value went to :account.',
    'issue_no_account' => 'Inventory Shortage & Surplus',
    'issue_where_it_goes' => 'Where each reason posts',
    'issue_narration_hint' => 'Who took it, or why it was given — for a gift this matters.',
    'issue_cost_note' => 'Stock falls at cost, not at the selling price — nobody paid, so it is not a sale.',

    // The movement row says where the figure came from — typed, or from a file
    'opening_from_file' => 'Opening stock brought in from the old books',

    /* Recipes. */
    'recipe_subtitle' => 'What each dish is made of — a sale takes these off the shelf',
    'recipe_count' => 'One recipe|:count recipes',
    'recipe_saved' => 'The recipe was saved.',
    'recipe_deactivated' => 'The recipe is off — past sales keep their history.',
    'recipe_activated' => 'The recipe is active again.',
    'recipe_has_no_lines' => 'No ingredients — selling this dish takes nothing off the shelf.',
    'recipe_lines_note' => 'Amounts are for the yield above, not for one plate. With waste, more leaves the store than is used, and the right-hand column shows how much.',
    'no_recipes' => 'No recipes yet',
    'no_recipes_note' => 'Without a recipe a cooked dish takes no ingredients when sold, and the store figures drift.',
];
