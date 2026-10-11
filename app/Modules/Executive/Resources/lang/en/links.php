<?php

declare(strict_types=1);

return [
    'title' => "Owner's centre — Sister-company parties",
    'explain' => 'If a customer or supplier of one company is really another of our companies, link it here. The group total then leaves out the sales, receivables and payables between the two ("Sister companies removed"). Nothing linked, nothing removed — names are never matched by guess.',
    'company' => 'Company',
    'party' => 'Customer or supplier',
    'is_sister' => 'Is really our company',
    'type_customer' => 'Customer',
    'type_supplier' => 'Supplier',
    'add' => 'Link',
    'remove' => 'Unlink',
    'none' => 'No links — nothing is removed from the group total.',
    'saved' => 'Linked — the group total will be counted again.',
    'removed' => 'Unlinked.',
    'already' => 'This party is already linked to a sister company.',
    'no_such_party' => 'This party does not belong to that company.',
];
