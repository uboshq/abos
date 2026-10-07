<?php

declare(strict_types=1);

return [
    'title' => 'Outside the rules',
    'note' => 'Every row here is silent: nothing breaks, no screen turns red, and nobody finds out unless they come looking.',
    'none' => 'Nothing is outside the rules today.',

    'step' => 'Step :level',
    'waiting_since' => 'Waiting since :date',
    'signed_on' => 'Signed on :date',
    'passes_unsigned' => 'Going through with no signature at all',

    'kind' => [
        'no_escalation_target' => 'A time limit is set, but nobody is named to receive it',
        'sla_breached' => 'Past its time and still with nobody',
        'changed_after_approval' => 'The paper changed after it was signed',
        'delegation_expired' => 'A delegation has run out',
        'condition_never_matches' => 'The condition names a field this work never sends',
        'no_flow' => 'Approval is asked for, and no flow exists',
    ],
    'capped' => 'Only the most recent :count are shown here — there may be more. Clear these and the next ones appear.',
];
