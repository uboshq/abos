<?php

declare(strict_types=1);

/*
 * Names for the fixed document lists — [[DocumentCatalog]] (8 October 2026, phase one).
 */
return [
    'folder' => [
        'company' => 'Company documents',
        'contracts' => 'Contracts',
        'hr' => 'HR',
        'finance' => 'Finance',
        'sales' => 'Sales',
        'purchase' => 'Purchase',
        'inventory' => 'Inventory',
        'legal' => 'Legal',
        'compliance' => 'Compliance',
    ],

    'type' => [
        'contract' => 'Contract',
        'agreement' => 'Agreement',
        'license' => 'Licence',
        'certificate' => 'Certificate',
        'invoice' => 'Invoice',
        'letter' => 'Letter',
        'policy' => 'Policy',
        'report' => 'Report',
        'identity' => 'Identity document',
        'form' => 'Form',
        'other' => 'Other',
    ],

    'level' => [
        'public' => 'Public',
        'internal' => 'Internal',
        'confidential' => 'Confidential',
        'highly_confidential' => 'Highly confidential',
        'restricted' => 'Restricted',
    ],

    'status' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'changes_requested' => 'Changes requested',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'published' => 'Published',
        'expired' => 'Expired',
        'archived' => 'Archived',
        'deleted' => 'Deleted',
    ],

    'expiry' => [
        'expired' => 'Expired',
        '7' => 'Expires within 7 days',
        '30' => 'Expires within 30 days',
        '90' => 'Expires within 90 days',
    ],

    'ability' => [
        'view' => 'View',
        'download' => 'Download',
        'print' => 'Print',
        'share' => 'Share',
        'edit' => 'Edit',
    ],

    'grantee' => [
        'user' => 'Person',
        'role' => 'Role',
    ],

    'field_kind' => [
        'text' => 'Text',
        'number' => 'Number',
        'date' => 'Date',
    ],
];
