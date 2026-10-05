<?php

declare(strict_types=1);

/*
 * ⭐ The same bill never twice — owner, 5 Oct 2026 (INV-0006 → DRF-0012; [[DirectSaleService::refuseARepeatBill()]]).
 */
return [
    'refused' => 'This bill was just confirmed as :no (:lines products, ৳:total). To make the same bill again, tick "Do it again".',
    'refused_sent' => 'This bill was just sent as :no and waits for a signature or the gate pass (:lines products, ৳:total). To make the same bill again, tick "Do it again".',
    'tick' => 'Do it again — I am making the same bill again on purpose',
    'audit_reason' => 'The same bill made again on purpose — the earlier one is :no',
    'setting' => 'Stop a repeated bill — within how many minutes (0 = off)',
];
