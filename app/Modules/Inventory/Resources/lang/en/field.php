<?php

declare(strict_types=1);

return [
    'free' => 'Free',
    'free_available' => 'Free sellable',
    'code' => 'Code',
    'name' => 'Name',

    'product_name_en' => 'Product name (English)',
    'product_name_bn' => 'Product name (Bangla)',

    'warehouse_name_en' => 'Warehouse name (English)',
    'warehouse_name_bn' => 'Warehouse name (Bangla)',

    'place_name_en' => 'Place name (English)',
    'place_name_bn' => 'Place name (Bangla)',
    'name_en' => 'Name (English)',
    'name_bn' => 'Name (Bangla)',
    'barcode' => 'Barcode',
    'brand' => 'Brand',
    'category' => 'Category',
    'unit' => 'Unit',
    'tax' => 'VAT',
    'purchase_price' => 'Purchase price',
    'sale_price' => 'Sale price',
    'margin' => 'Margin',
    'markup' => 'Markup',
    'track_batch' => 'Track lots (batches)',
    'reorder_level' => 'Reorder level',
    'product' => 'Product',
    'warehouse' => 'Warehouse',
    'branch' => 'Branch',
    'address' => 'Address',
    'address_en' => 'Address (English)',
    'address_bn' => 'Address (Bangla)',
    'floor' => 'On floor',
    'reserved' => 'Reserved',
    'hold' => 'Held',
    'available' => 'Available',

    /* "Not placed", not "waiting" — the word names the job to be done. */
    'unplaced' => 'Not placed',
    'unplaced_free' => 'Not placed (free)',
    'reason' => 'Reason',
    'changed_by' => 'Changed by',
    'counted' => 'Counted',
    'difference' => 'Difference',
    'state' => 'Status',
    'is_default' => 'Main warehouse',
    'quantity' => 'Quantity',
    'date' => 'Date',
    'narration' => 'Narration',

    // Transfer fields
    'from_warehouse' => 'From warehouse',
    'to_warehouse' => 'To warehouse',
    'from_to' => 'From → to',
    'items' => 'Items',
    'line_no' => 'No',
    'dispatched_at' => 'Dispatched',
    'received_at' => 'Arrived',
    'surplus_rate' => 'Rate for surplus',
    'opening_rate' => 'Rate per unit',
    'opening_value' => 'Value',
    'issued_qty' => 'Quantity issued',
    'expiry_date' => 'Expiry',
    'days_left' => 'Days left',
    'batch_no' => 'Lot no.',
    'mrp' => 'Printed price',
    'phone' => 'Phone',

    /* Recipes — what a dish is made of. */
    'dish' => 'Dish',
    'ingredient' => 'Ingredient',
    'ingredients' => 'Ingredients',
    'recipe_kind' => 'Cooked',
    'recipe_kind_hint' => 'Made to order takes the ingredients at the sale; cooked in a batch takes them when the batch is made.',
    'recipe_to_order' => 'Made to order',
    'recipe_batch' => 'Cooked in a batch',
    'yield' => 'Yield',
    'yield_hint' => 'How many one cooking makes. A 50-plate pot is 50 — then write the ingredient amounts for 50 plates.',
    'qty_used' => 'Used in cooking',
    'waste_pct' => 'Waste %',
    'qty_from_store' => 'Taken from store',
    'recipe_search' => 'Search by dish name or code',
    'recipe_active_hint' => 'Active — this recipe is the one a sale will use',
    'any_kind' => 'Any kind',

    /* Batch cooking. */
    'made' => 'How many made',
    'made_hint' => 'How many were actually made today — not the recipe yield. A 50-plate pot can give 47.',
    'production_recipe_hint' => 'Only recipes cooked in a batch — made-to-order ingredients come off at the sale.',
    'production_search' => 'Paper number or dish name',
    'cost_total' => 'Total cost',
    'cost_per_unit' => 'Cost each',
    'cost' => 'Cost',

    /* Food cost report. */
    'sold' => 'Sold',
    'revenue' => 'Revenue',
    'food_cost' => 'Ingredient cost',
    'food_cost_pct' => 'Food cost %',
    /* The paper's number — first column on transfer and cooking lists. */
    'document_no' => 'Number',
    'portions_possible' => 'Portions possible',
    'limiting' => 'What runs out first',
    'minutes' => 'm',

    /*
     * Three steps inside a warehouse — Block, Rack, Shelf.
     *
     * The words live here, not in code: a pharmacy calls these room,
     * cabinet and tray; a cold store calls them chamber, row and tier.
     */
    'depth_1' => 'Block',
    'depth_2' => 'Rack',
    'depth_3' => 'Shelf',
    'sort' => 'Order',
    'path' => 'Path',
    'parent_place' => 'Inside',
    'party' => 'With whom',
    'paper_no' => 'Paper',
    'paper_id' => 'ID',
    'processed_by' => 'Processed by',

    /* ⓘ Columns of the valued stock report — 21 September 2026. */
    'qty_opening' => 'Opening qty',
    'amount_opening' => 'Opening value',
    'qty_in' => 'In qty',
    'amount_in' => 'In value',
    'qty_out' => 'Out qty',
    'amount_out' => 'Out value',
    'qty_closing' => 'Closing qty',
    'free_opening' => 'Opening free',
    'free_in' => 'In free',
    'free_out' => 'Out free',
    'free_closing' => 'Closing free',
    'qty_total_with_free' => 'Total qty (with free)',
    'amount_closing' => 'Closing value',
];
