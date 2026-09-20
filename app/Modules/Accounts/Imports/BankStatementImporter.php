<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Imports;

use App\Core\Contracts\Importer;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\BankStatementService;

/**
 * ব্যাংকের স্টেটমেন্ট — মানচিত্র §৯।
 *
 * ── ⚠️ এটা "পুরনো খাতা তোলা" নয়, আর পার্থক্যটা জরুরি ─────────────────
 * বাকি ইমপোর্টারগুলো আমাদের **নিজের** তথ্য বসায় (খাত, খোলার জের)। ⛔ এটা
 * বসায় **ব্যাংকের বক্তব্য**, আর তার পিছনে আমাদের কোনো দলিল নেই। ⓘ তাই
 * এখান থেকে একটাও দাখিলা বইয়ে যায় না — সারিগুলো কেবল পাশাপাশি রাখা হয়,
 * যাতে "ব্যাংক যা জানে অথচ আমরা জানি না" প্রশ্নটার উত্তর থাকে।
 *
 * ── কেন খাতের কোড প্রতিটা সারিতে ────────────────────────────────────
 * ⓘ ইমপোর্টের কাঠামো ফাইল ছাড়া আর কিছু পায় না ([[ImportRunner::run()]]),
 * তাই "কোন ব্যাংকের খাতা" প্রশ্নটার উত্তর ফাইলের ভিতরেই থাকতে হয়।
 * ⚠️ ব্যাংকের নিজের রপ্তানিতে আমাদের কোড থাকে না — মানুষ নমুনা ফাইলে
 * ব্যাংকের কলামগুলো টেনে এনে কোডটা একবার লিখে নিচে টেনে দেন। বাকি
 * ইমপোর্টারগুলোতেও ঠিক এটাই করতে হয়।
 *
 * ── ⚠️ একই ফাইল দুইবার তুললে ───────────────────────────────────────
 * কিছুই দ্বিগুণ হয় না ([[BankStatementService::add()]]-এর ছাপ), আর সেটা
 * ভুল হিসেবেও ধরা হয় না: মানুষ প্রায়ই আগের মাসসহ পুরো ফাইলটা আবার
 * নামান। ⛔ ওটাকে ভুল বললে গোটা ফাইলটা ফেরত যেত, অথচ নতুন সারিগুলোই
 * তাঁর দরকার।
 */
final class BankStatementImporter implements Importer
{
    public function __construct(private readonly BankStatementService $lines) {}

    public static function label(): string
    {
        return 'accounts::import.bank_statement';
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public static function columns(): array
    {
        return [
            'account_code' => ['label' => 'accounts::field.bank_account_code', 'required' => true],
            'trx_date' => ['label' => 'accounts::field.date', 'required' => true],
            'description' => ['label' => 'accounts::field.narration', 'required' => false],
            'reference' => ['label' => 'accounts::field.instrument_no', 'required' => false],

            /*
             * ⚠️ দুইটাই ঐচ্ছিক, কিন্তু **অন্তত একটা** লাগে — সেটা
             * [[check()]] দেখে। ⓘ `required` বসালে প্রতিটা সারিতে দুইটাই
             * চাইত, অথচ ব্যাংকের কাগজে একটা ঘর সবসময়ই ফাঁকা।
             */
            'debit' => ['label' => 'accounts::field.withdrawn', 'required' => false],
            'credit' => ['label' => 'accounts::field.deposited', 'required' => false],
            'balance' => ['label' => 'accounts::field.running_balance', 'required' => false],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        $account = $this->lines->bankAccountByCode((string) ($row['account_code'] ?? ''));

        if ($account === null) {
            $errors[] = __('accounts::import.no_such_bank_account', ['code' => (string) ($row['account_code'] ?? '')]);
        }

        if ($this->lines->dateOf((string) ($row['trx_date'] ?? '')) === null) {
            $errors[] = __('accounts::import.bad_date', ['value' => (string) ($row['trx_date'] ?? '')]);
        }

        $debit = $this->lines->amountOf($row['debit'] ?? '');
        $credit = $this->lines->amountOf($row['credit'] ?? '');

        /*
         * ⛔ দুইটা ঘরই ফাঁকা মানে সারিটা কোনো লেনদেন নয় — ব্যাংকের
         * কাগজের শিরোনাম বা যোগফলের সারি। ⓘ ওগুলো চুপচাপ বসে গেলে
         * মিলকরণের তালিকায় শূন্য টাকার ভুতুড়ে সারি জমত।
         */
        if (bccomp($debit, '0', 4) === 0 && bccomp($credit, '0', 4) === 0) {
            $errors[] = __('accounts::import.no_amount_on_line');
        }

        // ⚠️ দুই ঘরেই টাকা — ব্যাংকের কাগজে এমন হয় না, তাই কলাম মেলানোয় ভুল
        if (bccomp($debit, '0', 4) > 0 && bccomp($credit, '0', 4) > 0) {
            $errors[] = __('accounts::import.both_sides_filled');
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     */
    public function import(array $row): void
    {
        $account = $this->lines->bankAccountByCode((string) $row['account_code']);

        if (! $account instanceof Account) {
            return;
        }

        $this->lines->add(
            account: $account,
            date: (string) $this->lines->dateOf((string) $row['trx_date']),
            description: trim((string) ($row['description'] ?? '')) ?: null,
            reference: trim((string) ($row['reference'] ?? '')) ?: null,
            debit: $this->lines->amountOf($row['debit'] ?? ''),
            credit: $this->lines->amountOf($row['credit'] ?? ''),
            balance: trim((string) ($row['balance'] ?? '')) === ''
                ? null
                : $this->lines->amountOf($row['balance'] ?? ''),
        );
    }
}
