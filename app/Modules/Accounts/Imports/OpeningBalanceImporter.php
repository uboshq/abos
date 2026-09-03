<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Imports;

use App\Core\Contracts\Importer;
use App\Core\Contracts\WarnsOnPartialImport;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\OpeningBalanceService;
use Illuminate\Support\Carbon;

/**
 * পুরনো খাতা থেকে খাতের খোলার জের।
 *
 * নতুন কোম্পানির সবচেয়ে কষ্টের কাজ দুইটার দ্বিতীয়টা: ছক ওঠার পর প্রতিটা
 * খাতের শুরুর অবস্থা বসানো। কোড ধরে বিদ্যমান খাত খুঁজে
 * `OpeningBalanceService::loadFor()`-কে দেয় — নিজে কিছু সেভ করে না।
 *
 * ⚠️ খোলার জের একটা **দলিল, তালিকা নয়**। অর্ধেক বসলে খতিয়ান মেলে
 * (প্রতিটা সারি RETAINED_EARNINGS-এর বিপরীতে সেল্ফ-ব্যালেন্সড), কিন্তু
 * স্থিতিপত্র ভুল — আর সেটা কেউ চোখে দেখে না। তাই `WarnsOnPartialImport`:
 * কিছু সারি ব্যর্থ হলে ফলাফলে জোরালো বার্তা।
 *
 * idempotent — `loadFor()` `exists()` দিয়ে আগে-বসা জের দেখে থামে, তাই
 * আংশিক ইমপোর্ট আবার চালালে বাকিটা সম্পূর্ণ হয়, দ্বিগুণ নয়। এই কারণেই
 * check()-এ "আগে জের বসেছে" ভুল হিসেবে ধরা হয় না: ধরলে পুনরায় চালানোর
 * সময় হয়ে-যাওয়া সারিগুলো "ব্যর্থ" দেখাত, আর সতর্কবার্তা মিথ্যা বাজত।
 */
final class OpeningBalanceImporter implements Importer, WarnsOnPartialImport
{
    public function __construct(private readonly OpeningBalanceService $openings) {}

    public static function label(): string
    {
        return 'accounts::import.opening_balance';
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public static function columns(): array
    {
        return [
            'account_code' => ['label' => 'accounts::field.account_code', 'required' => true],
            'opening_balance' => ['label' => 'accounts::field.opening_balance', 'required' => true],
            // ফাঁকা রাখলে চলতি অর্থবছরের প্রথম দিন (loadFor→dateFor)
            'opening_date' => ['label' => 'accounts::field.opening_date', 'required' => false],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        $account = $this->account($row['account_code'] ?? '');

        if ($account === null) {
            // কোডে খাত নেই — বাকিটা যাচাইয়ের উপায় নেই, তাই এখানেই থামা
            return [__('core.import.unknown_value', [
                'column' => 'account_code',
                'value' => $row['account_code'] ?? '',
            ])];
        }

        // গ্রুপ খাতে সরাসরি জের বসে না — তার নিচের খাতগুলোতে বসে
        if ($account->is_group) {
            $errors[] = __('accounts::validation.import_group_no_opening', [
                'code' => $account->code,
            ]);
        }

        if (! is_numeric($row['opening_balance'] ?? '')) {
            $errors[] = __('core.import.not_a_number', ['column' => 'opening_balance']);
        }

        if (filled($row['opening_date'] ?? '') && $this->date($row['opening_date']) === null) {
            $errors[] = __('core.import.not_a_date', ['column' => 'opening_date']);
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     */
    public function import(array $row): void
    {
        $account = $this->account($row['account_code'] ?? '');

        // check() ইতিমধ্যেই ধরেছে; import() কখনো check ছাড়া চলে না, তবু
        // রক্ষণাত্মক — null হলে কিছু না করে ফেরা, অর্ধেক দাখিলা নয়
        if ($account === null) {
            return;
        }

        $this->openings->loadFor(
            $account,
            (string) ($row['opening_balance'] ?? '0'),
            filled($row['opening_date'] ?? '') ? $this->date($row['opening_date']) : null,
        );
    }

    public function partialWarning(): ?string
    {
        return __('accounts::import.opening_partial_warning');
    }

    private function account(string $code): ?Account
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return Account::query()->where('code', $code)->first();
    }

    /**
     * তারিখ — কয়েকটা চেনা রূপে, CustomerImporter-এর মতো একই যত্নে।
     *
     * বাংলাদেশে হাতে লেখা তারিখ দিন/মাস/বছর, অথচ Carbon::parse() ওটাকে
     * আমেরিকান মাস/দিন ধরে — ৫ মার্চ হয়ে যেত ৩ মে, নীরবে। তাই format()
     * মিলিয়ে দেখা।
     */
    private function date(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->startOfDay();
            }
        }

        return null;
    }
}
