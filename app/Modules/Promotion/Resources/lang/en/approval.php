<?php

declare(strict_types=1);

/*
 * The multi-level approval of an offer — spec §14.
 * The levels themselves come from the company's own flow in the Approval Centre.
 */
return [
    /* The name of the action on the Approval Centre's flow screen */
    'action' => 'Approve a trade offer',

    'request_reason' => 'Offer :code — :name',

    'level' => 'Level :n',

    'sign' => 'Sign',
    'send_back' => 'Send back',
    'withdraw' => 'Withdraw',
    'reason' => 'Why is it going back?',
    'progress' => 'Approval path',
    'last_reason' => 'Sent back because',
    'waiting' => 'Waiting',

    'signed' => ':code signed.',
    'sent_back' => ':code went back to draft.',
    'withdrawn_message' => ':code was withdrawn to draft.',

    'cannot_sign_own' => 'You created this offer, so you cannot sign it at any level. '
        .'Approval means other people looked.',
    'cannot_sign_twice' => 'You already signed level :level of this offer. '
        .'Each level needs a different person — otherwise three signatures would be one.',
    'not_your_level' => 'This offer is waiting at ":step", and you are not a signer at that level.',
    'not_waiting' => 'Only an offer waiting for approval can be signed or sent back. This one is ":status".',
    'reason_required' => 'Say why it is going back — the person who made it needs to know what to change.',
    'only_creator_withdraws' => 'Only the person who made this offer can withdraw it.',
    'use_the_chain' => 'This offer goes through the company\'s approval levels. Sign it at your level instead.',

    /* Reasons kept in the offer's history when it returns to draft */
    'withdrawn' => 'Withdrawn by the person who made it.',
    'rejected_without_reason' => 'Sent back without a reason.',
    'creator_signed' => 'The person who made the offer signed it, so the approval does not count. Submit it again.',
    'signed_twice' => ':name signed two levels, so the approval does not count. Submit it again.',

    /* ⓘ মালিকের সই — গ্যাপ ৪৩ (যেকোনো ছাড়ে মালিকের সই) */
    'owner_signs_last' => 'The last signature on an offer is the owner\'s. Yours would have approved this offer, so it goes to the owner — it is approved when the owner signs.',
    'not_signed_by_owner' => 'The last signature was not the owner\'s, so the approval does not count. Submit it again — the owner gives the last signature.',
    'owner_must_have_signed' => 'This offer was approved by someone who is not the owner. An offer needs the owner\'s signature — the owner can activate it himself.',
    'no_owner_at_last_level' => 'The last level of the flow has no owner, but an offer\'s last signature is the owner\'s. So the approval does not count — submit the offer again for the owner\'s signature (put the owner on the flow\'s last level in the Approval Centre).',
    'owner_self_signed' => 'The owner signed an offer he created himself — a self-approved discount.',
];
