<?php

declare(strict_types=1);

return [
    'state_draft' => 'Draft',
    'state_awaiting' => 'Awaiting signature',
    // ⓘ A draft's signing state; for the app only a fallback for values it does not know (agreed with a4, 24 Oct 2026)
    'signing_awaiting' => 'Awaiting signature',
    'signing_stale' => 'Changed after the signature was asked for; posting sends it again',
    'signing_needs' => 'Needs a signature to post',
    'signing_signed' => 'Signed; not posted yet',
    'signing_rejected' => 'Signature refused',
    'state_posted' => 'Posted',
    'state_cancelled' => 'Cancelled',
    'edit_needs_network' => 'Only new vouchers can be written offline. To change one, go online and use its page.',
    'unknown_type' => 'Unknown voucher type. Update the app and write it again.',
    'posted' => ':no is posted.',
    'saved_draft' => ':no is kept as a draft.',

    // ⭐ টাকা কার — মালিক, ৭ অক্টোবর ২০২৬ ("kake dibe kar kach theke nibe")
    'party_from' => 'Received from',
    'party_to' => 'Paid to',
    'party_any' => 'Party',
    'money_into' => 'Paid into',
    'money_out_of' => 'Paid from',
    // ⭐ কনট্রার দুই দিক — মালিক, ১৭ অক্টোবর ২০২৬ ("recived into, pay From")
    'contra_from' => 'Moved from',
    'contra_to' => 'Moved to',
];
