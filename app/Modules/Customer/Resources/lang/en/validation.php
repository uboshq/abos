<?php

declare(strict_types=1);

return [
    'code_taken' => 'Another customer already uses this code.',
    'distributor_needs_a_point' => 'A distributor must have a point — one area has only one distributor, so the rule cannot be applied without knowing the area.',
    'point_already_has_a_distributor' => 'This point already has an active distributor — :name (:code). One area has only one distributor, so deactivate that one before activating this.',
    'bn_name_required' => 'A Bangla name is required — settings make it mandatory.',
    'limit_needs_a_flow' => 'Raising a credit limit needs a signature from a person, but this company has no approval flow for "Raising a credit limit". Set one up under Approval → Approval flows, then try again.',
    'active_needs_delete_key' => 'Switching a customer on or off needs the deactivate permission; the edit permission is not enough.',
    'opening_needs_key' => 'An opening balance puts money in the books and needs its own permission (the accountant\'s). Create the customer with zero; the accountant sets the opening balance.',
    'point_in_other_branch' => 'This point belongs to another branch. Pick a point in the customer\'s own branch.',
    'limit_on_create' => 'A new customer starts with a zero credit limit. A limit needs a signature — create the customer first, then raise the limit from Edit; the approval request goes from there.',
];
