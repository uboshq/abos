<?php

// Balance confirmation letter (ISA 505; ERP 100% accounts gap #19, 20 Oct 2026)
return [
    'title' => 'Balance confirmation',
    'button' => 'Balance confirmation letter',
    'to' => 'To',
    'code' => 'Code',
    'address' => 'Address and phone',
    'as_of' => 'Balance as at',
    'greeting' => 'Dear Sir or Madam,',
    'body' => 'For reconciliation and audit we write to tell you that on :date our books show the balance of your account as',
    'side_dr' => 'owed by you to us',
    'side_cr' => 'owed by us to you',
    'side_nil' => 'nothing owed either way',
    'ask' => 'Please compare it with your books, fill in the reply below, and return it to us signed and stamped within :days days. If it does not agree, write the balance in your books and the reason for the difference.',
    'not_a_demand' => 'This is not a request for payment, only a request to confirm the balance.',
    'prepared_by' => 'Prepared by (accounts)',
    'approved_by' => 'Approved by',
    'reply_title' => 'Reply: cut here and return',
    'agree' => 'The balance above, :amount (:side), agrees with our books.',
    'disagree' => 'It does not agree. The balance in our books is:',
    'reason' => 'Reason for the difference:',
    'reply_sign' => 'Signature and stamp',
    'reply_name' => 'Name and position',
    'reply_date' => 'Date',
];
