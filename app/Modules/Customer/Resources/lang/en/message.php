<?php

declare(strict_types=1);

return [
    'code_auto' => 'Leave blank and the code will be filled in.',

    'bn_name_hint' => 'Without it the English name is shown everywhere.',
    'type_hint' => 'For example: retail, wholesale, institution.',
    'zero_means_unlimited' => '0 means no limit.',

    'opening_note' => 'What was owed before this system. Can only be set now — '
        .'to change it later, post a journal voucher, or the ledger and this list would disagree.',

    'search_placeholder' => 'Search customer or mobile...',

    'none_yet' => 'No customers yet.',
    'created' => 'Customer added.',
    'updated' => 'Customer updated.',
    'deactivated' => 'Customer deactivated.',
    'activated' => 'Customer is active again.',

    'no_transactions' => 'No transactions for this customer yet.',
    'over_limit' => 'Outstanding is over the credit limit.',
    'deactivate_confirm' => 'This customer will be deactivated. Past transactions and balances stay. Continue?',

    'count' => '{0} No customers|{1} 1 customer|[2,*] :count customers',
    'confirm_deactivate' => 'Deactivate this customer? Their history and dues stay; only new billing stops.',
    'point_hint' => 'Which point the shop sits at. The area follows from it.',
    'portal_enabled' => 'The portal is open. Tell the customer their code :code and the password.',
    'portal_password_set' => 'The new password is set. Tell the customer — the old one no longer works.',
    'portal_disabled' => 'The portal is closed. They are signed out now, not when the session expires.',
    'portal_note' => 'Once open, the customer can see their own dues and bills over the internet, and raise a deposit claim. They can change nothing, and see nobody else.',
    'portal_never' => 'Never signed in',
    'portal_disable_confirm' => 'Close the portal? The customer is signed out at once. The password stays, so reopening later needs no new one.',
    'portal_password_hint' => 'At least eight characters. It is never shown again — tell the customer now.',
];
