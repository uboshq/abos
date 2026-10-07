<?php

declare(strict_types=1);

/* বাতিল-ইনভয়েস — মালিক, ৪ অক্টোবর ২০২৬ ([[SalesInvoiceCancellationService]]) */
return [
    'doc' => 'Cancellation Invoice',
    'title' => 'Cancellation Invoice',
    'button' => 'Issue cancellation invoice',
    'reason_label' => 'Why cancel — what was wrong',
    'reason_hint' => 'The original invoice stays; books and stock are fully reversed. If the gate pass is out, make a return (SRT) instead.',
    'reference' => 'Cancellation invoice: :cxl',
    'of_invoice' => 'Invoice reversed: :no',
    'reason' => 'Reason',
    'state_awaiting' => 'Awaiting the owner’s signature',
    'state_confirmed' => 'Confirmed — books and stock reversed',
    'state_cancelled' => 'Rejected — the invoice stands',
    'advance_note' => ':amount collected on this invoice stays in the books as the customer’s advance.',
    'saved_confirmed' => ':cxl issued — the books and stock of :no are reversed.',
    'saved_awaiting' => ':cxl awaits the owner’s signature — it confirms itself once signed.',
    'narration' => ':cxl cancellation invoice — :reason',
    'reason_required' => 'Write why the invoice is cancelled — no cancellation invoice without a reason.',
    'already' => ':no already has a cancellation invoice — :cxl.',
    'awaiting_signature' => ':cxl has not been signed by the owner yet.',
    'not_confirmed' => ':no is not a confirmed invoice — a draft is simply cancelled.',
    'after_gate_pass' => 'The goods of :no left through the gate pass — make a return (SRT), not a cancellation invoice.',
    'after_return' => ':no has a return or a credit note — reverse those first.',
    'shared_challan' => 'Challan :challan of :no also carries another invoice’s goods — make a return, not a cancellation invoice.',
    'month_closed' => 'The month :month of :no is closed — someone allowed must reopen it with a reason first.',
    'approval' => 'Cancellation invoice (signature)',
    'reissue' => 'Make the correct bill',
    'reissue_hint' => 'The cancelled bill\'s lines open at the counter — fix them and save for a new bill with a new number.',
    'reissue_order' => 'Back to the order — new challan',
    'reissue_order_hint' => 'The order lines are open again — a new challan from the order gives the correct bill.',
    'reissue_notice' => 'The lines of :no are filled in after :cxl — fix them and save; it becomes a new bill with a new number.',
];
