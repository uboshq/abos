<?php

declare(strict_types=1);

/*
 * Loading sheet - what goes on each truck, by trip ([[LoadingSheetController]]), the Delivery Processing design.
 */
return [
    'title' => 'Loading Sheet',
    'subtitle' => 'Trips not yet out - what goes on which truck',
    'empty' => 'No open trips.',
    'no_lines' => 'This trip has no challans.',

    'column' => [
        'trip' => 'Trip',
        'date' => 'Date',
        'vehicle' => 'Vehicle',
        'driver' => 'Driver',
        'challans' => 'Challans',
    ],

    'by_product' => 'By product - how much to load',
    'by_challan' => 'By challan - for whom',
    'print' => 'Print loading sheet',
    'confirm' => 'Confirm loading',
    'confirm_hint' => 'The trip\'s challans move to Packed.',
    'confirmed' => ':no - :count challan(s) moved to Packed.',
    'not_open' => 'Trip :no is no longer open - it has left or was cancelled.',
    'challan_list' => 'Challans',
];
