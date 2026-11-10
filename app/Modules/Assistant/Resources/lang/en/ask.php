<?php

declare(strict_types=1);

// ⭐ কোপাইলটকে প্রশ্ন, ধাপ ৪ ([[AskController]])
return [
    'title' => 'Ask :name',
    'model_on' => 'The office AI server is connected — write in any language.',
    'model_off' => 'The office AI server is not connected yet — common questions are answered from our own word list. Write like the examples below.',
    'placeholder' => 'Type… e.g. "How much did we sell today?"',
    'send' => 'Ask',
    'source' => 'Source',
    'by' => 'understood by: :model',
    'words' => 'own word list',
    'check' => 'written by :name — please check',
    'thanks' => 'Thanks — your feedback is logged.',
    'feedback_up' => 'Right answer',
    'feedback_down' => 'Wrong answer',
    'state' => [
        'off' => 'Answers are switched off for this company (Control panel → Copilot).',
        'limit' => 'Your question limit for today is used up — ask again tomorrow.',
        'not_understood' => 'I did not understand. Write it like the examples — I do not guess.',
        'blocked' => 'You do not hold the key to see this answer.',
    ],
    'example' => [
        'sales_summary' => 'How much did we sell today?',
        'sales_product_need' => 'How much sugar is needed next week?',
        'stock_on_hand' => 'How much sugar is in stock?',
        'credit_overdue' => 'Who owes the most?',
    ],
];
