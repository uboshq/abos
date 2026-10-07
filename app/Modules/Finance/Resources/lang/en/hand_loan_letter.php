<?php

declare(strict_types=1);

/*
 * ⭐ Balance confirmation letter — finance module plan 1.9, 5 Oct 2026 ([[HandLoanLetterController]]).
 */
return [
    'title' => 'Balance confirmation',
    'button' => 'Balance confirmation letter',
    'to' => 'To',
    'greeting' => 'Dear Sir/Madam,',
    'they_owe' => 'By our books, on :date you owe us :amount on hand loans.',
    'we_owe' => 'By our books, on :date we owe you :amount on hand loans.',
    'nothing' => 'By our books, on :date nothing is due either way on hand loans.',
    'in_words' => 'In words',
    'please' => 'If this agrees with your own records, please sign below and send it back. If it does not, please write the figure you hold.',
    'agree' => 'The balance above is correct.',
    'disagree' => 'Not correct — my figure is:',
    'ours' => 'For the company',
    'theirs' => 'Signature and date of the recipient',
];
