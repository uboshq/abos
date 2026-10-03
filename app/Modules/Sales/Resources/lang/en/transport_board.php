<?php

declare(strict_types=1);

/*
 * পরিবহন বরাদ্দ — তালিকার পাতা ([[TransportAssignmentController]], ৩ অক্টোবর ২০২৬)।
 * ⓘ পপআপের নিজের শব্দ (কীভাবে, গাড়িতে …) sales::transport-এ — এখানে কেবল তালিকার।
 */
return [
    'title' => 'Transport Assignment',
    'subtitle' => 'Confirmed challans — which still need transport, which have it, which have left the gate',
    'tab_unassigned' => 'Transport not set',
    'tab_assigned' => 'Transport set',
    'tab_passed' => 'Gate pass issued',
    'empty_unassigned' => 'Every confirmed challan has its transport.',
    'empty_assigned' => 'No challan is waiting for a gate pass.',
    'empty_passed' => 'No challan has a gate pass yet.',
    'not_set' => 'Not set',
    'fare' => 'Fare',
    'gate_pass' => 'Gate pass',
    'assign' => 'Set transport',
    'change' => 'Change transport',
    'any_vehicle' => 'Any vehicle',
    'any_customer' => 'Any customer',
    'driver_filter' => 'Driver name or mobile',
];
