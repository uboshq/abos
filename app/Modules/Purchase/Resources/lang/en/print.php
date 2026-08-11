<?php

declare(strict_types=1);

/*
 * Signature lines on the purchase papers.
 *
 * Kept apart from field.php for the same reason Accounts does: a
 * printed document is read by somebody who is not at the screen — a
 * storekeeper counting cartons, a supplier's driver, an auditor a year
 * later. Changing a form label should not quietly reword a document
 * somebody has signed.
 */

return [
    // Goods receipt: one person counts what came off the truck,
    // another checks it against the paperwork. Two signatures, because
    // one person doing both is how a short delivery becomes nobody's
    // mistake.
    'received_by' => 'Received by',
    'checked_by' => 'Checked by',

    /*
     * On the purchase return only.
     *
     * This is the one purchase document that leaves the building with
     * the goods, and this signature is what later proves they actually
     * went back — without it the argument is "we sent it" against "we
     * never got it", with the money sitting in between.
     */
    'supplier_signature' => "Supplier's representative",
];
