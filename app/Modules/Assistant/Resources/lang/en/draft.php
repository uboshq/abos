<?php

declare(strict_types=1);

// ⭐ কোপাইলটের খসড়া, ধাপ ৩ ([[DraftController]])
return [
    'nothing' => 'Nothing to buy right now — no draft made.',
    'reorder_purpose' => ':name suggestion — from the stock board reorder list',
    'reorder_line' => 'On hand :have · lasts :days',
    'reorder_log' => 'Purchase requisition draft from the reorder list — :n products',
    'reorder_done' => 'Purchase requisition draft :no made — check it, change it if needed, and send it for approval. Copilot sent nothing.',
    'reorder_button' => 'Draft a purchase requisition',
    'dunning_button' => 'Draft payment reminder SMS',
    'dunning_title' => 'Payment reminder SMS — drafts',
    'dunning_note' => ':name wrote these; tick the ones to send, then Send. They go through the office SMS phone.',
    'dunning_empty' => 'Nobody is overdue right now.',
    'dunning_send' => 'Send the ticked ones',
    'dunning_sent' => ':n SMS queued on the office phone.',
    'no_phone' => 'No mobile number',
    'dunning_text' => 'Dear :party, your due is Tk :amount (oldest bill :days days overdue). Kindly pay. :company',
];
