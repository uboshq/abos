<?php

declare(strict_types=1);

/*
 * Financial institutions — banks, finance/leasing companies, insurers, mobile banking.
 */
return [
    'title' => 'Financial institutions',
    'subtitle' => 'Banks and companies that lend to us, where the FDRs and DPSs sit, and the insurers — in one list',
    'new' => 'New institution',
    'edit' => 'Edit institution',
    'tab_all' => 'All',

    'kind' => 'Type',
    'kind_bank' => 'Bank',
    'kind_nbfi' => 'Finance / leasing company',
    'kind_insurance' => 'Insurance company',
    'kind_mfs' => 'Mobile banking',

    'name_en' => 'Name (English)',
    'name_bn' => 'Name (Bangla)',
    'short_code' => 'Short name',
    'short_code_hint' => 'e.g. IBBL, DBBL — shown next to the name in lists',
    'branch_name' => 'Branch',
    'contact_person' => 'Contact person',
    'phone' => 'Phone',
    'state' => 'State',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'deactivate' => 'Deactivate',
    'activate' => 'Activate',

    'none_yet' => 'No institution has been added yet.',
    'saved' => ':name saved.',
    'name_taken' => 'This name is already listed: :name — pick that one.',
    'pick_or_type' => 'Pick from the list or type a new name — not both.',

    'which' => 'Institution',
    'not_listed' => 'Not in the list? Add it here',
    'new_name' => 'New institution name',
    'new_name_hint' => 'Saving adds it to the list; if the name is already there, that one is used',

    // institution page
    'accounts' => 'Accounts',
    'accounts_hint' => 'Our current/savings or mobile banking accounts here — balances from the ledger',
    'account' => 'Account',
    'balance_today' => 'Balance today',
    'link_account' => 'Link account',
    'unlink' => 'Unlink',
    'linked' => 'Account linked.',
    'unlinked' => 'Account unlinked — neither the account nor its entries were touched.',
    'no_accounts' => 'No account is linked yet.',
    'nothing_to_link' => 'No account left to link.',
    'account_does_not_fit' => 'This account does not belong here — bank accounts go to a bank or finance company, MFS accounts to mobile banking.',
    'account_taken' => 'This account is already linked to :name.',
    'policies' => 'Insurance policies',
    'total' => 'Total',
    'coa_hint' => 'For a bank or MFS account — which institution. Its balance then shows on the institution page.',
];
