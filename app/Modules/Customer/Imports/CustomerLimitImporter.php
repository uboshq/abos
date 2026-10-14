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
        } elseif (($customer = $this->customer($row)) !== null) {
            $errors = [...$errors, ...$this->raiseWouldStop($customer, $limit)];
        }

        return $errors;
    }

    /**
     * ⛔ বাড়ানো সারি যাচাইয়েই থামে, যেখানে বসাতে গিয়ে থামত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
     * [[TheLimitFileWasGreenAndThenFailedTest]])। ⓘ [[CustomerService::proposeLimit()]]-এর দুই থামার জায়গা: সীমার সইয়ের ছক
     * নেই, অথবা এই গ্রাহকের অন্য অঙ্কের একটা অনুরোধ আগেই সইয়ের অপেক্ষায়। আগে পর্দা সবুজ দেখাত, তারপর সারিগুলো একে একে পড়ত।
     *
     * @return list<string>
     */
    private function raiseWouldStop(Customer $customer, string $limit): array
    {
        if (bccomp($limit, bcadd((string) ($customer->credit_limit ?? '0') ?: '0', '0', 4), 4) <= 0) {
            return [];
        }

        if (! app(\App\Core\Engines\Approval\ApprovalEngine::class)->requires('customer', 'credit_limit', null, class_basename(Customer::class))) {
            return [__('customer::validation.limit_needs_a_flow')];
        }

        $waiting = \App\Models\Approval::query()
            ->where('approvable_type', $customer->getMorphClass())
            ->where('approvable_id', $customer->getKey())
            ->where('action', 'credit_limit')
            ->where('status', \App\Models\Approval::PENDING)
            ->pluck('amount');

        $other = $waiting->first(fn ($amount) => $amount === null || bccomp((string) $amount, $limit, 4) !== 0);

        return $other !== null && ! $waiting->contains(fn ($amount) => $amount !== null && bccomp((string) $amount, $limit, 4) === 0)
            ? [__('customer::import.limit_other_request_waiting')]
            : [];
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

        $customer = $code === '' ? null : Customer::query()->where('code', $code)->first();

        // ⛔ পর্দার একই দেয়াল — শাখায় সীমিত মানুষ ফাইলে অন্য শাখার কোড লিখে তাদের সীমা কমাতেন বা বাড়ানোর অনুরোধ বসাতেন
        // (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[ALimitFileReachedAnotherBranchTest]])। ⓘ "এমন গ্রাহক নেই" — অন্য শাখার কে আছে, বলা হয় না
        $user = auth()->user();

        return $customer !== null && $user !== null && ! $user->can('update', $customer) ? null : $customer;
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
