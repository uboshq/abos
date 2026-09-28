<?php

declare(strict_types=1);

// A held counter sale finishes itself after the last signature — 28 September 2026.
return [
    'not_held' => 'Not a counter sale waiting for signatures',
    'no_signature' => 'No signature was asked for — finish it by hand',
    'waiting' => 'More signatures are still due',
    'rejected' => 'A signature was rejected — correct or cancel the sale',
    'maker_gone' => 'The person who made the sale is inactive or no longer in this company — finish it by hand',
    'audit' => 'Automatic — after the last signature (signed by: :signer; run in the name of the maker :maker)',
    'by_command' => 'one-off command',
];
