<?php

declare(strict_types=1);

/*
 * Purchase cost — by purchase invoice (owner-approved sample v6, tab C; 21 Nov 2026).
 */
return [
    'menu' => 'Purchase cost',
    'title' => 'Purchase cost — by purchase invoice',
    'title_en' => '',
    'setting_threshold' => 'Purchase cost report: limit %',

    'group_label' => 'Show',
    'by' => [
        'invoice' => 'Invoice',
        'supplier' => 'Supplier',
        'month' => 'Month',
        'product' => 'Product',
    ],

    'filter_date' => 'Date',
    'supplier' => 'Supplier',
    'product' => 'Product',
    'threshold' => 'Limit',
    'threshold_percent' => 'Limit %',
    'all' => 'All',
    'all_branches' => 'All branches',
    'chip_date' => 'Date: :range',
    'chip_branch' => 'Branch: :name',
    'chip_supplier' => 'Supplier: :name',
    'chip_product' => 'Product: :name',
    'chip_threshold' => 'Limit: :n%',

    'print' => 'Print',
    'pdf' => 'PDF',
    'excel' => 'Excel',

    'card_value' => 'Total purchase value',
    'card_value_sub' => ':n purchases · :cartons cartons',
    'card_cost' => 'Total bringing-in cost',
    'card_cost_sub' => 'Labour :labour · Freight :fare · Other :other',
    'card_pct' => 'Average cost %',
    'card_pct_sub' => 'Total cost ÷ total purchase value',
    'card_per_carton' => 'Cost per carton',
    'card_per_carton_sub' => 'Highest: :no · ৳:amount',

    'col' => [
        'date' => 'Date',
        'no' => 'Bill / GRN no',
        'supplier' => 'Supplier',
        'month' => 'Month',
        'product' => 'Product',
        'purchases' => 'Purchases',
        'lines' => 'Lines',
        'cartons' => 'Cartons',
        'value' => 'Purchase value',
        'labour' => 'Unloading (labour)',
        'fare' => 'Freight in',
        'other' => 'Other',
        'total' => 'Total cost',
        'pct' => 'Cost %',
        'per_carton' => 'Per carton',
    ],
    'direct' => '(direct purchase)',
    'over' => 'Above the limit',
    'total_label' => 'Total (:n purchases)',
    'empty' => 'No posted purchases in this period.',

    'chart_supplier' => 'Cost % by supplier',
    'chart_product' => 'Cost % by product',
    'chart_note' => 'Line = :n% limit · Orange = above the limit',
    'chart_bar' => ':name — cost :pct% (৳:cost on ৳:value)',
];
