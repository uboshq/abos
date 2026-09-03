<?php

declare(strict_types=1);

/*
 * Words for the first door.
 *
 * The person reading this page knows nothing about ABOS and has nobody to
 * call — they have just installed it on their own server. So every field
 * says **why**, not only what.
 */

return [
    'title' => 'Set up ABOS',
    'lead' => 'Nobody has used ABOS on this server yet. Create the first owner and the first company here — once only; after that this page is gone.',

    'you' => 'You',
    'you_note' => 'This account can do everything — open companies, add users, read the books. More owners can be added later.',

    'name' => 'Your name',
    'email' => 'Email',
    'email_note' => 'This is who you are at the login screen.',
    'password' => 'Password',
    'password_note' => 'At least 10 characters, with both letters and numbers — this one account holds the whole business.',
    'password_confirmation' => 'Password again',

    'company' => 'Your business',
    'company_note' => 'Opening a company also lays down its financial year, number series, standard chart of accounts and master lists — nothing to do separately afterwards.',
    'company_name' => 'Company name (in Latin letters)',
    'branch_name' => 'Main branch (in Latin letters)',

    /*
     * The field arrives filled in, not empty — deliberately. Most
     * businesses have no special name for their first branch, and an empty
     * box makes people think they need to know something they do not. It
     * can be renamed, and more branches added later.
     */
    'branch_default' => 'Head Office',
    'branch_note' => 'At least one branch is required — without one there is nowhere for a transaction to sit. More can be added later.',

    /*
     * The financial year is not a field, deliberately: in Bangladesh it is
     * July to June with no exception, and CompanyProvisioner works the two
     * dates out itself. A field would let someone enter the calendar year,
     * and then every report would disagree with the tax authority — a
     * mistake that only shows up at year end.
     */
    'year_starts_on' => 'Financial year starts',
    'year_ends_on' => 'Financial year ends',

    /*
     * ⚠️ This used to say "It can be changed later if needed" — which was
     * not true. Nothing edits the dates of the current year; the year-end
     * screen only creates the next one. A true warning beats a false
     * reassurance, especially where the mistake cannot be undone.
     */
    'year_note' => "Bangladesh's current financial year (July–June) is filled in. ⚠️ If your first set of books starts mid-year — February to June, say — change the start date now. It cannot be changed later, because invoice and challan numbers are issued against this year.",

    'currency' => 'Currency of the books',
    'currency_note' => 'Every figure will be kept in this currency, and other currencies will be rated against it. More can be added later.',

    /*
     * Why Latin letters are needed — said on the screen, before the
     * mistake, and it gives the **reason**, not just "wrong field":
     * the code is built from this name, and that code goes into every
     * document number and onto every printed page.
     */
    'latin_note' => 'Write the name in Latin letters — the code is built from it, and that code goes into every invoice number and every printout. A Bangla name can be added later in settings.',
    'needs_latin' => 'The name needs at least one Latin letter — the document code is built from it, and codes are written in Latin letters everywhere. A Bangla name can be added later.',

    'submit' => 'Set up',

    'done' => 'ABOS is set up. The company, branch, financial year, number series and chart of accounts are all in place — now add users or bring in your old books.',
];
