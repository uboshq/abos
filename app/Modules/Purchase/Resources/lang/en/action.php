<?php

declare(strict_types=1);

return [
    'new_order' => 'New order',
    'new_receipt' => 'Receive goods',
    'new_bill' => 'New bill',
    'confirm' => 'Confirm',
    'cancel_document' => 'Cancel',
    'edit' => 'Edit',
    'add_line' => 'Add a line',
    'remove_line' => 'Remove',
    'new_payment' => 'New payment',
    'new_return' => 'New return',
    'show_cancelled' => 'Show cancelled too',
    'receive_against' => 'Receive against this order',
    'bill_against' => 'Bill this receipt',
    'confirm_direct' => 'Confirm invoice',
    'clear_all' => 'Clear all',
    'clear_line' => 'Clear',
    'add_gift' => 'Add gift',
    'bill_against_order' => 'Bill this order',

    /*
     * The unfinished purchase the screen offers to bring back.
     *
     * The wording is deliberately plain: the person reading it has a
     * lorry at the gate, not time to parse a sentence.
     */
    'draft_restore' => 'Bring it back',
    'draft_discard' => 'Throw it away',

    'add_deposit' => 'Add',

    /*
     * The counter screen's own buttons (the owner's pictures, 4 Sep 2026).
     *
     * 'gift_item' sits in the line box, with Costing, Add to Cart and
     * Clear Data beside it — the four he drew there. 'rate_chart' is
     * one of the six buttons under the totals card instead; that is
     * where his screenshot puts it, and it is a bill-wide thing, not a
     * line one.
     */
    'gift_item' => 'Gift item',
    /* ⭐ "Chart Entry", not "Rate chart" — the owner, 6 Sep 2026:
       *"Rate chart ekhane keno? ekhane Chart Entry hobe"*.

       ⓘ Sales already calls the same thing `chart_bulk_do` → **Chart
       Entry**, and it is the same act on both papers: many products
       entered at once from a chart, instead of one row at a time.
       ⚠️ Two names for one act made a reader learn the screen twice. */
    'rate_chart' => 'Chart Entry',
    'costing' => 'Costing',

    /*
     * The four words inside the line box, exactly as he wrote them.
     *
     * 'add_line' still exists and still means the same thing — the two
     * older purchase screens use it in a toolbar, where 'Add to cart'
     * would be wrong: there is no cart there, only a list of lines.
     */
    'gift_short' => 'Gift',
    'add_to_cart' => 'Add to cart',
    'clear_data' => 'Clear data',
    'receive_goods' => 'Receive goods',
    'close_panel' => 'Close',

    /*
     * The six buttons at the foot of the totals card.
     *
     * Each one opens a panel that was already on the screen, always
     * open. Behind a button the empty screen stays clean, and the day
     * freight or a deposit is needed it is one click away.
     */
    'add_deposit_panel' => 'Add payment',
    'add_note' => 'Add note',
    /* ⭐ "Transport", not "Transportation" — the owner, 6 Sep 2026:
       *"Transportation botamer nam poriborton kore Transport koro, Direct
       Sales & Pur dujaygay"*.

       ⓘ It sits on a row of six one- and two-word buttons (Gift · Costing ·
       Chart Entry · Shipment · Clear all), and "Transportation" was the only
       one that had to shrink its own text to fit. ⚠️ A label that wraps or
       shrinks reads as less important than its neighbours, and this one is
       not — it changes the amount payable.

       ⓘ Bangla was already the short form (পরিবহন); only English was long,
       so the two languages now agree in weight as well as meaning. */
    'transportation' => 'Transport',
    'shipment' => 'Shipment',
    'take_new_price' => 'Use the new price',
    'keep_old_price' => 'Keep the old price',
];
