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
    // The handover slip — the paper both sides sign
    'handover_title' => 'Money handover receipt',
    'handover_copy_giver' => "Giver's copy",
    'handover_copy_receiver' => "Receiver's copy",
    'handover_copy_single' => 'One copy for both sides',
    'handover_cut_here' => 'Cut here',
    'handover_from' => 'From',
    'handover_to' => 'To',
    'handover_amount' => 'Amount handed over',
    'handover_given_by' => 'Given by',
    'handover_received_by' => 'Received by',
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
