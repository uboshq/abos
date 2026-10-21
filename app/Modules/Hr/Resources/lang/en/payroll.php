<?php

declare(strict_types=1);

// ⭐ বেতনের রানের কথা — আটকে থাকা স্লিপ আর ব্যাংক ফাইলের সঙ্গী-তালিকা (a6, ২১ অক্টোবর ২০২৬)
return [
    'held_no_account' => 'Paid by bank or mobile, but no signed account number — salary held.',
    'left_out_title' => 'Not in the bank file',
    'left_out_head' => 'Employee code,Name,Reason,Legal deadline',
    'held_waiting' => 'The salary bank/mobile account is waiting for a second signature — no salary goes there until it is signed; the wage stays owed and joins the next bank file as arrears once signed.',
    'held_next_file' => 'The account is signed — the wage joins the next bank file as arrears.',
    'held_paid_later' => 'Paid as arrears in the bank file of :no.',
    'held_deadline' => 'Legal deadline (Labour Act s.123 — 7 working days after the wage period, Fridays off): :date',
    'held_late' => '⛔ Past the deadline',
    'arrears_reference' => 'Wage arrears :no :month',
    'held_owed_title' => 'Held wages — not yet paid',
    'held_owed_none' => 'No held wages.',
    'held_run' => 'Run',
    'held_until' => 'Deadline',
    'held_ready_title' => 'Wage arrears ready to pay',
    'held_ready_note' => 'The account is signed — pay today; no waiting for next month\'s run (Labour Act s.123).',
    'held_ready_none' => 'Nobody is waiting to be paid this way.',
    'held_ready_on' => 'Ready to pay — :way',
    'held_channel_waiting' => 'Account waiting for its signature',
    'held_pay_bank' => 'Download the bank arrears file and draft the voucher',
    'held_pay_mfs' => 'Download the MFS list and draft the voucher',
    'held_papers' => 'Earlier arrears papers',
    'held_paper_again' => 'Download the paper again',
    'held_pay_account' => 'Choose a bank or MFS money account.',
    'held_pay_none' => 'No payslip chosen to pay.',
    'held_pay_twice' => 'This arrear of :code has already been paid — not twice.',
    'held_pay_not_ready' => 'The arrear of :code is not ready to pay this way (account not signed, or another channel).',
    'held_pay_narration' => 'Wage arrears — :count people (Labour Act s.123)',
    'held_paid_voucher' => 'Paid on the wage-arrears paper (:no).',
    'held_released' => 'Wage-arrears voucher :no was cancelled or rejected — the wage is ready to pay again.',
    'held_rejected_cancel' => 'Wage-arrears voucher rejected at signing — cancelled; the wage is ready to pay again.',
];
