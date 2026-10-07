<?php

declare(strict_types=1);

// Deposit request with a bank slip — 1 October 2026 ([[DepositSlip]])
return [
    'title' => 'Deposit request — with the bank slip',
    'hint' => 'When the shop pays into the bank, send a photo of the slip. The due drops only after accounts check and accept it.',
    'customer' => 'Shop / customer',
    'slip' => 'Bank slip (photo or PDF, up to 5 MB)',
    'required' => 'A bank deposit request needs the slip photo.',
    'sent' => 'Request sent (no. :no) — accounts will check it.',
    'by' => '[sent by: :name]',
    'new' => 'New request with slip',
    'view' => 'View slip',
    'none' => 'No slip',
];
