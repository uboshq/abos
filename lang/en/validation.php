<?php

declare(strict_types=1);

/*
 * Form validation messages, English.
 *
 * Laravel ships its own English messages, so this file exists almost
 * entirely for the `attributes` map at the bottom. Without it the framework
 * prints the database column: a screen labelled "Name (English)" refuses
 * with "The name en field is required."
 *
 * Measured on live (3 Sep 2026): all eight master-data forms did this —
 * units, brands, categories, taxes, cost centres, departments, reason codes,
 * payment methods. Reason codes said "The context field is required";
 * payment methods said "The account id field is required".
 *
 * ⚠️ The Bangla side was worse: `lang/bn/validation.php` did not exist at
 * all, so a fully Bangla screen answered in English. See that file for the
 * reasoning; this one is its pair, and rule 9 of this repo is that both
 * languages carry the same keys.
 */
return [

    /*
     * Only the handful whose default wording reads badly beside a Bangla
     * screen or hides what the user must do. Everything else falls through
     * to Laravel's own English, which is already good.
     */
    'decimal' => 'The :attribute must have :decimal decimal places.',
    'unique' => 'That :attribute already exists.',
    'exists' => 'That :attribute could not be found.',

    'custom' => [],

    /*
     * Field names — the actual fix.
     *
     * These are the fields that recur across many forms. A module's own
     * field names live in that module's `field.php`; this covers the shared
     * ones only.
     *
     * ⚠️ A new shared field needs a line here too, or its message goes back
     * to printing the column name — and nobody notices until somebody
     * leaves that field empty and presses save.
     */
    'attributes' => [
        'name' => 'name',
        'name_bn' => 'name (Bangla)',
        'name_en' => 'name (English)',
        'code' => 'code',
        'email' => 'email',
        'password' => 'password',
        'identifier' => 'sign-in name',
        'phone' => 'mobile number',
        'address' => 'address',
        'context' => 'what it is used for',
        'account_id' => 'account',
        'company_id' => 'company',
        'branch_id' => 'branch',
        'customer_id' => 'customer',
        'supplier_id' => 'supplier',
        'product_id' => 'product',
        'warehouse_id' => 'warehouse',
        'unit_id' => 'unit',
        'brand_id' => 'brand',
        'category_id' => 'category',
        'tax_id' => 'tax',
        'user_id' => 'user',
        'role' => 'role',
        'qty' => 'quantity',
        'quantity' => 'quantity',
        'rate' => 'rate',
        'price' => 'price',
        'amount' => 'amount',
        'total' => 'total',
        'discount' => 'discount',
        'trx_date' => 'transaction date',
        'date' => 'date',
        'from_date' => 'from date',
        'to_date' => 'to date',
        'due_on' => 'due date',
        'narration' => 'narration',
        'note' => 'note',
        'reason' => 'reason',
        'status' => 'status',
        'type' => 'type',
        'file' => 'file',
        'sort' => 'order',
        'is_active' => 'active',
    ],
];
