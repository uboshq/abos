<?php

declare(strict_types=1);

/*
 * Delivery cost — by challan ([[DeliveryCostReport]], sample v6 tab B, 21 Nov 2026).
 */
return [
    'title' => 'Delivery cost — by challan',
    'menu' => 'Delivery cost',
    'noun' => 'challans',
    'search' => 'Search challan no, vehicle or party',
    'empty' => 'No posted challans in this period.',

    'group_label' => 'Show',
    'by_challan' => 'Challan',
    'by_vehicle' => 'Vehicle',
    'by_point' => 'Point',
    'by_month' => 'Month',

    'date' => 'Date',
    'vehicle' => 'Vehicle',
    'point' => 'Point',
    'party' => 'Party',
    'threshold' => 'Limit %',
    'threshold_chip' => 'Limit: :n%',

    'col_date' => 'Date',
    'col_challan' => 'Challan no',
    'col_place' => 'Point / party',
    'col_vehicle' => 'Vehicle',
    'col_count' => 'Challans',
    'col_value' => 'Challan value',
    'col_fare' => 'Vehicle fare',
    'col_loading' => 'Loading',
    'col_other' => 'Other',
    'col_total' => 'Total cost',
    'col_percent' => 'Cost %',
    'total_row' => 'Total (:n challans)',
    'no_vehicle' => 'No vehicle',
    'over_hint' => 'Over the limit',

    'n_challans' => ':n challans',
    'card_value' => 'Total challan value',
    'card_cost' => 'Total delivery cost',
    'card_cost_sub' => 'Fare :fare · Loading :loading · Other :other',
    'card_average' => 'Average cost %',
    'card_average_sub' => 'Total cost ÷ total value',
    'card_top' => 'Highest %',

    'chart_vehicle' => 'Cost % by vehicle',
    'chart_point' => 'Cost % by point',
    'chart_month' => 'Cost % by month',
    'chart_legend' => 'Line = :n% limit · Orange = over the limit',
    'chart_line' => ':label: cost ৳:cost on ৳:value',

    'setting_threshold' => 'Delivery cost limit (%)',
];
