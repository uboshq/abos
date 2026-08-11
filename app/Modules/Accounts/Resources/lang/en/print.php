<?php

declare(strict_types=1);

/*
 * Words that only appear on paper.
 *
 * Kept apart from field.php because a printed voucher is read by
 * somebody who is not sitting at the screen — a customer holding a
 * receipt, an inspector, an auditor a year later. The wording there
 * answers to different needs than a form label does, and mixing the
 * two means changing a screen label quietly changes a legal-looking
 * document.
 */

return [
    // Signature lines. Which of these appear depends on the voucher
    // type — money coming in needs the payer's signature, money going
    // out needs the receiver's.
    'paid_by' => 'Paid by',
    'received_by' => 'Received by',
    'prepared_by' => 'Prepared by',
    'approved_by' => 'Approved by',

    /*
     * Stamped across a cancelled voucher.
     *
     * A cancelled voucher can still be printed, deliberately: somebody
     * asks "what happened to that receipt", and the answer has to be
     * showable. But the paper must say so on its face, because a
     * cancelled voucher that looks identical to a live one is a
     * receipt somebody can present for money already reversed.
     */
    'cancelled' => 'CANCELLED',
];
