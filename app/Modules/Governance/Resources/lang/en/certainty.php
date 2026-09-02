<?php

declare(strict_types=1);

/*
 * The time machine's three levels of certainty.
 *
 * All three are named apart, because "no value" and "value unknown" are
 * not the same thing - and in an audit that difference is everything.
 */
return [
    'known' => 'Known from the audit',
    'empty' => 'Empty when created',
    'untracked' => 'Not known - this field is never audited',
];
