<?php

declare(strict_types=1);

// ⭐ Loading cost on the challan — screen words (owner, 18 Nov 2026: "ok"; [[ChallanLoading]]).
// ⓘ The main names and modes live in `supplier::labour.*`; only the challan and counter words here.
// ⓘ `sales::loading` is the loading sheet's — hence a separate file.
return [
    'optional' => 'Optional',
    'required' => 'Required',
    'optional_hint' => 'Leave blank if not known yet — it can be written from the transport page after the challan is confirmed.',
    'required_hint' => 'The challan will not confirm without an amount or "Not needed".',
    'record_hint' => 'Loading cost is not written on this confirmed challan yet.',
    'record' => 'Save loading cost',
    'write' => 'Write loading',
    'pick_till' => 'Choose a till',
    'pick_contractor' => 'Choose a contractor',
];
