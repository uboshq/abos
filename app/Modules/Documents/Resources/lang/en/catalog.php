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
        'approved' => 'Approved',
        'archived' => 'Archived',
    ],

    'expiry' => [
        'expired' => 'Expired',
        '7' => 'Expires within 7 days',
        '30' => 'Expires within 30 days',
        '90' => 'Expires within 90 days',
    ],
];
