<?php

declare(strict_types=1);

return [
    'title' => 'Dashboard',
    'subtitle' => 'Stock and warehouses — where things stand',

    'below_reorder' => 'Running low',
    'on_hold' => 'Stock on hold',
    'on_hold_rows' => ':n rows',
    'expiry_title' => 'Expiry control — lots',
    'expiry_hint' => ':count lots with stock, by when they expire',
    'expiry_expired' => 'Expired',
    'expiry_within_7' => 'Within 7 days',
    'expiry_within_30' => '8–30 days',
    'expiry_within_90' => '31–90 days',
    'expiry_later' => 'After 90 days',
    'total_sku' => 'Total SKUs',
    'total_sku_hint' => 'Active products — inactive ones left out',
    'negative_stock' => 'Negative stock',
    'negative_stock_hint' => 'Product-warehouse pairs with stock below zero — there should be none',
    'active_batches' => 'Active lots',
    'active_batches_hint' => 'Lots that still hold goods — on the shelf, waiting to be placed, or free',
    'by_warehouse' => 'Stock value by warehouse',
    'by_warehouse_hint' => 'Paid quantity × average purchase rate; free goods carry no cost',
    'health' => 'Stock health',
    'health_hint' => ':count active products — healthy, running low, or out of stock',
    'health_ok' => 'Healthy',
    'health_low' => 'Running low',
    'health_out' => 'Out of stock',
    'moves_title' => 'Stock movements this month — by kind',
    'moves_hint' => 'How many times goods moved; placing on the shelf or holding is not a movement',
    'moves_receive' => 'Received',
    'moves_issue' => 'Issued',
    'moves_transfer' => 'Transfers',
    'moves_adjustment' => 'Adjustments',
    // ⓘ হোমের নতুন রূপ (পরিকল্পনা ২, ৫ অক্টোবর ২০২৬)
    'kpi_stock_value_hint' => 'At cost · free goods at zero',
];
