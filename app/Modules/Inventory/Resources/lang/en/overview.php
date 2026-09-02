<?php

declare(strict_types=1);

return [
    'title' => 'Stock overview',
    'subtitle' => 'Stock and warehouses',
    'all_warehouses' => 'All warehouses',

    'available' => 'Available',
    'available_hint' => 'On the floor, less reserved and held',

    'below_reorder' => 'Running low',
    'below_reorder_hint' => 'Below the reorder level',

    'out_of_stock' => 'Out of stock',
    'out_of_stock_hint' => 'Cannot be supplied today',

    'stock_value' => 'Stock value',
    'stock_value_hint' => 'Remaining layers at what they cost',
    'value_hidden' => 'Not yours to see',

    'flow' => 'Moved in and out, by month',
    'moved_in' => 'In',
    'moved_out' => 'Out',

    'states' => 'Where the stock stands',
    'floor' => 'On the floor',
    'reserved' => 'Reserved',
    'hold' => 'On hold',

    // ⚠️ Used by InventoryWidgets on the home screen. Dropped by accident
    //    when this file was rewritten; without it the tile showed a raw key.
    'on_hold' => 'Stock on hold',
    'states_hint' => 'The floor less what is reserved and held is what can be sold',

    'recent' => 'Just moved',
    'today_count' => ':count today',
    'change' => 'Change',
    'reorder_level' => 'Reorder level',

    'nothing_low' => 'Nothing is below its reorder level.',
    'nothing_moved' => 'Nothing has moved yet.',

    'slow_moving' => 'Slow moving',
    'slow_moving_hint' => 'Holding stock, moved, but not selling',
    'non_moving' => 'Non moving',
    'non_moving_hint' => 'Holding stock, nobody has touched it',
    'stagnant' => 'Not going out',
    'nothing_stagnant' => 'Everything is moving.',
    'touches' => 'Movements',
    'window' => 'in :days days',
];
