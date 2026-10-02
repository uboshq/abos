<?php

// "How the goods go" — after confirm, before print (owner-approved change, 1 Oct 2026; [[ChallanTransportController]])
return [
    'title' => 'How the goods go',
    'button' => 'How the goods go',
    'why' => 'Set this before the challan and gate pass can be printed.',
    'mode' => 'How',
    'mode_vehicle' => 'By vehicle',
    'mode_own' => 'Customer takes it now',
    'mode_direct' => 'Direct delivery (on foot / by hand)',
    'hint_vehicle' => 'Fleet vehicle or number, driver name and phone',
    'hint_own' => 'No vehicle needed',
    'hint_direct' => 'No vehicle needed — delivered close by',
    'driver_phone' => 'Driver phone',
    'saved' => 'How the goods go — saved. The challan and gate pass can now be printed.',
    'locked' => 'A gate pass is issued — the goods have left; transport can no longer change.',
    'cancelled' => 'A cancelled challan has no transport to set.',
    'no_challan' => 'This bill has no challan — there is no goods-out paper.',
    'needed_before_print' => 'Set how the goods go first — then it can be printed.',
];
