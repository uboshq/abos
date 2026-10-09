<?php

declare(strict_types=1);

namespace App\Modules\Customer\Imports;

use App\Core\Contracts\Importer;
use App\Core\Contracts\ImportNeedsKeys;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;

/**
 * ⭐ গ্রাহকের বাকির সীমা — একসাথে অনেকের, ২ অক্টোবর ২০২৬ (মালিক: "ok doro", "parle ektane koro")।
 *
 * ⓘ দুইটা কলাম: গ্রাহকের কোড আর নতুন সীমা। গ্রাহকের তালিকা থেকে রপ্তানি করে সীমার কলামটা ভরে তুললেই চলে।
 * নিয়ম সম্পাদনারই ([[CustomerService::proposeLimit()]]): কমানো সাথে সাথে; বাড়ানো ঠিক সেই অঙ্কে সইয়ের অনুরোধ, আর
 * অনুমোদন কেন্দ্রে সই পড়লে নিজে বসে ([[ApplyTheLimitOnTheLastSignature]])। ⛔ কোনো পথে সই এড়ানো যায় না।
 */
final class CustomerLimitImporter implements Importer, ImportNeedsKeys
{
    /** ⛔ এই ইমপোর্টের চাবি — পর্দার একই কাজের (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, SystemAdmin ⛔৫; [[ImportNeedsKeys]]) */
    public static function requiredPermissions(): array
    {
        return ['customer.update'];
    }

    public function __construct(private readonly CustomerService $customers) {}

    public static function label(): string
    {
        return 'customer::import.limits';
    }

    /** @return array<string, array{label: string, required: bool}> */
    public static function columns(): array
    {
        return [
            'code' => ['label' => 'customer::field.code', 'required' => true],
            'credit_limit' => ['label' => 'customer::field.credit_limit', 'required' => true],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        if ($this->customer($row) === null) {
            $errors[] = __('customer::import.no_such_customer', ['code' => (string) ($row['code'] ?? '')]);
        }

        $limit = $this->limit($row);

        if ($limit === null) {
            $errors[] = __('core.import.not_a_number', ['column' => 'credit_limit']);
        } elseif (bccomp($limit, '0', 4) < 0) {
            $errors[] = __('customer::import.limit_not_negative');
        }

        return $errors;
    }

    /** @param  array<string, string>  $row */
    public function import(array $row): void
    {
        $customer = $this->customer($row);
        $limit = $this->limit($row);

        if ($customer === null || $limit === null) {
            return;
        }

        $this->customers->proposeLimit($customer, $limit);
    }

    /** @param  array<string, string>  $row */
    private function customer(array $row): ?Customer
    {
        $code = trim((string) ($row['code'] ?? ''));

        return $code === '' ? null : Customer::query()->where('code', $code)->first();
    }

    /**
     * কমা আর মুদ্রার চিহ্ন বাদ দিয়ে অঙ্ক — না পড়া গেলে `null`।
     *
     * @param  array<string, string>  $row
     */
    private function limit(array $row): ?string
    {
        $raw = trim((string) ($row['credit_limit'] ?? ''));

        /*
         * ⛔ কেবল কমা, ফাঁকা আর টাকার চিহ্ন বাদ — বাকি সব অক্ষর থাকলে অঙ্কটা অচেনা (পুনঃঅডিট ৯ অক্টোবর ২০২৬, গ্রাহক ১৭)।
         * ⓘ আগে সংখ্যা ছাড়া সব ছেঁটে ফেলা হত: "1e5" হয়ে যেত ১৫, "৫০ হাজার" হয়ে যেত ৫০ — যাচাইয়ের পর্দা সবুজ, আর ভুল সীমা বসত।
         */
        $clean = (string) preg_replace('/[,\s]|৳|tk\.?|taka|টাকা/iu', '', $raw);

        if ($raw === '' || preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $clean) !== 1) {
            return null;
        }

        return bcadd($clean, '0', 4);
    }
}
