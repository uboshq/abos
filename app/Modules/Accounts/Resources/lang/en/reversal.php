<?php

declare(strict_types=1);

/* উল্টো কাগজ — পাকা ভাউচার আর নোটের (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬; [[AccountsReversalService]]) */
return [
    'doc' => 'Reversal',
    'reference' => 'Reversal: :rev',
    'reversed_on' => 'on :date, reason: :reason',
    'narration' => ':rev — reversal of :no: :reason',
    'saved' => ':no reversed — reversal :rev; the books are fully reversed.',
    'reason_required' => 'Write why it is reversed — no reversal without a reason.',
    'not_posted' => ':no is not posted, or already cancelled — only a posted paper is reversed.',
    'already' => ':no is already reversed.',
    'month_closed' => 'The month :month of :no is closed — someone allowed must reopen it with a reason first.',
    'reconciled' => ':no is held by a bank reconciliation — open the reconciliation first, then reverse.',
];
