<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Imports;

use App\Core\Contracts\Importer;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * পুরনো খাতা থেকে সরবরাহকারী।
 *
 * নিজে কিছু সেভ করে না — SupplierService ডাকে। ফলে বাংলা নামের নিয়ম,
 * BIN বাধ্যতামূলক কি না, কোডের অনন্যতা আর খোলা ব্যালেন্সের দাখিলা —
 * সবই হাতে বসানোর মতোই খাটে। নিজে Supplier::create() ডাকলে ইমপোর্ট করা
 * সারিগুলো আলাদা নিয়মে চলত, আর সেটা ধরা পড়ত মাস পরে।
 */
final class SupplierImporter implements Importer
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public static function label(): string
    {
        return 'supplier::menu.suppliers';
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public static function columns(): array
    {
        return [
            // কোড ঐচ্ছিক: পুরনো খাতায় নম্বর না থাকলে সিরিজ থেকে বসবে
            'code' => ['label' => 'supplier::field.code', 'required' => false],
            'name_en' => ['label' => 'supplier::field.name_en', 'required' => true],
            'name_bn' => ['label' => 'supplier::field.name_bn', 'required' => false],
            'phone' => ['label' => 'supplier::field.phone', 'required' => false],
            'email' => ['label' => 'supplier::field.email', 'required' => false],
            'address' => ['label' => 'supplier::field.address', 'required' => false],
            'contact_person' => ['label' => 'supplier::field.contact_person', 'required' => false],
            'bin' => ['label' => 'supplier::field.bin', 'required' => false],
            'tin' => ['label' => 'supplier::field.tin', 'required' => false],
            'party_type' => ['label' => 'supplier::field.party_type', 'required' => false],
            'payment_term' => ['label' => 'supplier::field.payment_term', 'required' => false],
            'credit_limit' => ['label' => 'supplier::field.credit_limit', 'required' => false],
            'opening_balance' => ['label' => 'supplier::field.opening_balance', 'required' => false],
            'opening_date' => ['label' => 'supplier::field.opening_date', 'required' => false],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        if (filled($row['code']) && Supplier::query()->where('code', $row['code'])->withTrashed()->exists()) {
            $errors[] = __('supplier::validation.code_taken', ['code' => $row['code']]);
        }

        if (filled($row['opening_balance']) && ! is_numeric($row['opening_balance'])) {
            $errors[] = __('core.import.not_a_number', ['column' => 'opening_balance']);
        }

        if (filled($row['credit_limit']) && ! is_numeric($row['credit_limit'])) {
            $errors[] = __('core.import.not_a_number', ['column' => 'credit_limit']);
        }

        if (filled($row['opening_date']) && $this->date($row['opening_date']) === null) {
            $errors[] = __('core.import.not_a_date', ['column' => 'opening_date']);
        }

        /*
         * ধরন ও শর্ত নাম বা কোড — দুইভাবেই মেলানো হয়।
         *
         * পুরনো খাতায় লেখা থাকে "পাইকারি", CSV-তে কেউ লেখেন "WHOLE"।
         * একটাই মেনে নিলে অর্ধেক সারি বাদ পড়ত, আর ব্যবহারকারী বুঝতেন না
         * কেন — কলামটা তো ভরাই আছে।
         */
        if (filled($row['party_type']) && $this->partyType($row['party_type']) === null) {
            $errors[] = __('core.import.unknown_value', [
                'column' => 'party_type',
                'value' => $row['party_type'],
            ]);
        }

        if (filled($row['payment_term']) && $this->paymentTerm($row['payment_term']) === null) {
            $errors[] = __('core.import.unknown_value', [
                'column' => 'payment_term',
                'value' => $row['payment_term'],
            ]);
        }

        // সার্ভিসের নিজের নিয়মগুলো (বাংলা নাম, BIN) — সেটিংস অনুযায়ী।
        // এখানে না দেখলে সেগুলো ভাঙত বসানোর সময়, অর্থাৎ যাচাইয়ের পর্দায়
        // সারিটা সবুজ দেখাত আর তারপর ব্যর্থ হত।
        if ($errors === []) {
            try {
                $this->suppliers->assertImportable($this->payload($row));
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
        $this->suppliers->create($this->payload($row));
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function payload(array $row): array
    {
        return [
            'code' => $row['code'] ?: null,
            'name_en' => $row['name_en'],
            'name_bn' => $row['name_bn'] ?: null,
            'phone' => $row['phone'] ?: null,
            'email' => $row['email'] ?: null,
            // পুরনো খাতায় ঠিকানা একটাই থাকে, দুই ভাষায় নয় — যেটা আছে
            // সেটা ইংরেজির ঘরে বসে, আর ব্যবহারকারী পরে বাংলাটা যোগ করেন
            'address_en' => $row['address'] ?: null,
            'contact_person' => $row['contact_person'] ?: null,
            'bin' => $row['bin'] ?: null,
            'tin' => $row['tin'] ?: null,
            'party_type_id' => $this->partyType($row['party_type'])?->id,
            'payment_term_id' => $this->paymentTerm($row['payment_term'])?->id,
            'credit_limit' => $row['credit_limit'] !== '' ? $row['credit_limit'] : 0,
            'credit_days' => 0,
            'opening_balance' => $row['opening_balance'] !== '' ? $row['opening_balance'] : 0,
            'opening_date' => $this->date($row['opening_date'])?->toDateString(),
        ];
    }

    private function partyType(string $value): ?PartyType
    {
        if ($value === '') {
            return null;
        }

        return PartyType::query()
            ->for(PartyType::SUPPLIER)
            ->where(fn ($q) => $q->where('code', $value)
                ->orWhere('name_en', $value)
                ->orWhere('name_bn', $value))
            ->first();
    }

    private function paymentTerm(string $value): ?PaymentTerm
    {
        if ($value === '') {
            return null;
        }

        return PaymentTerm::query()
            ->where(fn ($q) => $q->where('code', $value)
                ->orWhere('name_en', $value)
                ->orWhere('name_bn', $value))
            ->first();
    }

    /**
     * তারিখ — কয়েকটা চেনা রূপে।
     *
     * বাংলাদেশে হাতে লেখা তারিখ প্রায় সবসময় দিন/মাস/বছর, অথচ
     * Carbon::parse() ওটাকে আমেরিকান মাস/দিন ধরে নেয়। ৫ মার্চ হয়ে যেত
     * ৩ মে, আর সেটা কোনো ভুল দেখাত না — শুধু বকেয়া ভুল মাসে বসত।
     */
    private function date(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                // Carbon ৩ ব্যতিক্রম ছোঁড়ে, false ফেরত দেয় না — ধরা না
                // পড়লে একটা ভুল তারিখ পুরো ইমপোর্টটাই থামিয়ে দিত
                continue;
            }

            // format() মিলিয়ে দেখা হয় কারণ Carbon "32/13/2026"-ও মেনে
            // নিয়ে পরের মাসে গড়িয়ে যায়, আর তখন ভুলটা নীরবে ঢুকত
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->startOfDay();
            }
        }

        return null;
    }
}
