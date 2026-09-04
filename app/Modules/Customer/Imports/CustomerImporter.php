<?php

declare(strict_types=1);

namespace App\Modules\Customer\Imports;

use App\Core\Contracts\Importer;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentTerm;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * পুরনো খাতা থেকে গ্রাহক।
 *
 * সরবরাহকারীর ইমপোর্টারের আয়না, আর কোড ভাগ করা হয়নি — কলামগুলো আলাদা
 * (গ্রাহকের BIN বা যোগাযোগের ব্যক্তি লাগে না), আর একটা শেয়ার্ড ভিত্তি
 * বানালে প্রতিটা পদ্ধতিতে "গ্রাহক না সরবরাহকারী" শর্ত বসত (সেকশন ১৯.৮)।
 *
 * নিজে কিছু সেভ করে না — CustomerService ডাকে, তাই বাংলা নামের নিয়ম,
 * কোডের অনন্যতা ও খোলা ব্যালেন্সের দাখিলা হাতে বসানোর মতোই খাটে।
 */
final class CustomerImporter implements Importer
{
    public function __construct(private readonly CustomerService $customers) {}

    public static function label(): string
    {
        return 'customer::menu.customers';
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public static function columns(): array
    {
        return [
            // কোড ঐচ্ছিক: পুরনো খাতায় নম্বর না থাকলে সিরিজ থেকে বসবে
            'code' => ['label' => 'customer::field.code', 'required' => false],
            'name_en' => ['label' => 'customer::field.name_en', 'required' => true],
            'name_bn' => ['label' => 'customer::field.name_bn', 'required' => false],
            'phone' => ['label' => 'customer::field.phone', 'required' => false],
            'email' => ['label' => 'customer::field.email', 'required' => false],
            'address' => ['label' => 'customer::field.address', 'required' => false],
            'party_type' => ['label' => 'customer::field.party_type', 'required' => false],
            'payment_term' => ['label' => 'customer::field.payment_term', 'required' => false],
            'credit_limit' => ['label' => 'customer::field.credit_limit', 'required' => false],
            'credit_days' => ['label' => 'customer::field.credit_days', 'required' => false],
            'opening_balance' => ['label' => 'customer::field.opening_balance', 'required' => false],
            'opening_date' => ['label' => 'customer::field.opening_date', 'required' => false],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        if (filled($row['code']) && Customer::query()->where('code', $row['code'])->withTrashed()->exists()) {
            $errors[] = __('customer::validation.code_taken', ['code' => $row['code']]);
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
                $this->customers->assertImportable($this->payload($row));
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
        $this->customers->create($this->payload($row));
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
            // ধরন না দিলে ডিফল্টটাই (পরিবেশক) — নাহলে ইমপোর্ট করা হাজার
            // গ্রাহক ধরনহীন বসত, আর ধরন ধরে রিপোর্ট তাঁদের কখনো গুনত না
            'party_type_id' => $this->partyType($row['party_type'])?->id
                ?? PartyType::query()->for(PartyType::CUSTOMER)->where('is_default', true)->value('id'),
            'payment_term_id' => $this->paymentTerm($row['payment_term'])?->id,
            'credit_limit' => $row['credit_limit'] !== '' ? $row['credit_limit'] : 0,
            'credit_days' => $row['credit_days'] !== '' ? (int) $row['credit_days'] : 0,
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
            ->for(PartyType::CUSTOMER)
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
