<?php

declare(strict_types=1);

/*
 * ডকুমেন্টের খবরের লেখা — ABOS-এর নোটিফিকেশন সেবা দিয়ে যায় (§২৩; তৃতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 */
return [
    'new' => 'New document: :name',
    'new_body' => 'Number :no — you are its owner.',
    'updated' => 'New version: :name',
    'updated_body' => 'The current version is now :version.',
    'access_given' => 'You were given access to a document: :name',
    'access_removed' => 'Your access to a document was removed (:no)',
    'approval_required' => 'Approval needed: :name',
    'approval_required_body' => 'Document :no is waiting for your signature.',
    'approved' => 'Approved: :name',
    'rejected' => 'Rejected: :name',
    'changes_requested' => 'Sent back for changes: :name',
    'expiry_warning' => 'Expires in :days days: :name',
    'renewal_required' => 'Renewal needed — :days days left: :name',
    'expired' => 'Expired: :name',
    'expiry_body' => 'Expiry date :date.',
];
