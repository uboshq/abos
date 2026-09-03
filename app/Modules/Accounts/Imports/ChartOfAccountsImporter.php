<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Imports;

use App\Core\Contracts\Importer;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use Illuminate\Validation\ValidationException;

/**
 * পুরনো খাতা থেকে হিসাবের ছক।
 *
 * নতুন কোম্পানির সবচেয়ে কষ্টের কাজ দুইটার একটা: তার নিজের ছকটা হাতে
 * বসাতে বললে go-live দিন-সপ্তাহ পিছায়। এটা Tally/Excel-এর ছক সারি ধরে
 * তোলে — নিজে কিছু সেভ করে না, `AccountService::create()` ডাকে, তাই কোড
 * অটো-বসা, অভিভাবক-ধরন সামঞ্জস্য, system-code সুরক্ষা — সব হাতে বসানোর
 * মতোই খাটে।
 *
 * ⚠️ এখানে `opening_balance` কলাম **নেই**, ইচ্ছাকৃত: `create()` খাত তৈরির
 * সাথেই খোলার জের পোস্ট করে দেয় (AccountService line ~104)। জেরের কলাম
 * এখানে রাখলে ছক ও জের এক ইমপোর্টে মিশে যেত — খোলার জের আলাদা ইমপোর্টার
 * (`OpeningBalanceImporter`), কারণ ওটা একটা দলিল, তালিকা নয়।
 */
final class ChartOfAccountsImporter implements Importer
{
    public function __construct(private readonly AccountService $accounts) {}

    public static function label(): string
    {
        return 'accounts::import.chart_of_accounts';
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public static function columns(): array
    {
        return [
            // কোড ঐচ্ছিক: ফাঁকা রাখলে অভিভাবকের নিচে পরের খালি নম্বর বসে
            'code' => ['label' => 'accounts::field.code', 'required' => false],
            'name_en' => ['label' => 'accounts::field.name_en', 'required' => true],
            'name_bn' => ['label' => 'accounts::field.name_bn', 'required' => false],
            // অভিভাবক — কোড বা নাম; থাকলে ধরন অভিভাবক থেকেই আসে
            'parent' => ['label' => 'accounts::field.parent', 'required' => false],
            // ধরন — অভিভাবক না থাকলে লাগে (asset/liability/equity/income/expense)
            'type' => ['label' => 'accounts::field.type', 'required' => false],
            // প্রকৃতি — ফাঁকা রাখলে ধরন থেকে ডিফল্ট (debit/credit)
            'nature' => ['label' => 'accounts::field.nature', 'required' => false],
            'is_group' => ['label' => 'accounts::field.is_group', 'required' => false],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        // অভিভাবক লেখা আছে কিন্তু খুঁজে পাওয়া গেল না — কলামটা তো ভরাই,
        // তাই assertImportable-এর "parent_id null" বার্তা বিভ্রান্ত করত
        if (filled($row['parent'] ?? '') && $this->parent($row['parent']) === null) {
            $errors[] = __('core.import.unknown_value', [
                'column' => 'parent',
                'value' => $row['parent'],
            ]);
        }

        // প্রকৃতি লেখা থাকলে debit/credit ছাড়া কিছু নয়
        if (filled($row['nature'] ?? '')
            && ! in_array(strtolower(trim($row['nature'])), [Account::DEBIT, Account::CREDIT], true)) {
            $errors[] = __('core.import.unknown_value', [
                'column' => 'nature',
                'value' => $row['nature'],
            ]);
        }

        // সার্ভিসের নিজের পূর্বশর্ত — কোডের অনন্যতা, অভিভাবক-গ্রুপ, ধরন।
        // এখানে না দেখলে সারিটা check-এ সবুজ দেখাত, import-এ ভাঙত।
        if ($errors === []) {
            try {
                $this->accounts->assertImportable($this->payload($row));
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    foreach ($messages as $message) {
                        $errors[] = $message;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     */
    public function import(array $row): void
    {
        $this->accounts->create($this->payload($row));
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function payload(array $row): array
    {
        $parent = $this->parent($row['parent'] ?? '');

        return [
            'code' => filled($row['code'] ?? '') ? trim($row['code']) : null,
            'name_en' => $row['name_en'] ?? '',
            'name_bn' => filled($row['name_bn'] ?? '') ? $row['name_bn'] : null,
            'parent_id' => $parent?->id,
            'type' => filled($row['type'] ?? '') ? strtolower(trim($row['type'])) : null,
            'nature' => filled($row['nature'] ?? '') ? strtolower(trim($row['nature'])) : null,
            'is_group' => $this->truthy($row['is_group'] ?? ''),
        ];
    }

    /**
     * অভিভাবক — কোড বা নাম, দুইভাবেই। গ্রুপ খাতই অভিভাবক হতে পারে, তাই
     * শুরুতেই is_group ছেঁকে নেওয়া হয় — নাহলে একটা সাধারণ খাত মিলে গিয়ে
     * পরে assertImportable-এ "parent must be group" ভাঙত।
     */
    private function parent(string $value): ?Account
    {
        if (trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return Account::query()
            ->where('is_group', true)
            ->where(fn ($q) => $q->where('code', $value)
                ->orWhere('name_en', $value)
                ->orWhere('name_bn', $value))
            ->first();
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'yes', 'y', 'true', 'হ্যাঁ', 'হাঁ'], true);
    }
}
