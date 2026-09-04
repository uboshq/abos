<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * জমার দাবি — তোলা, গ্রহণ, প্রত্যাখ্যান।
 *
 * ── দাবি আর আদায়ের মাঝখানে একটা মানুষ থাকে, ইচ্ছাকৃতভাবে ────────────
 * গ্রাহক দাবি তোলেন; ডিপো ব্যাংকের কাগজে খুঁজে দেখে গ্রহণ করে। গ্রহণের
 * মুহূর্তে আদায়টা তৈরি হয়, আর তখনই খাতায় টাকা বসে।
 *
 * মাঝখানের মানুষটা না থাকলে যে কেউ বসে বসে নিজের বকেয়া শূন্য করে
 * ফেলতে পারতেন — আর ধরা পড়ত মাস শেষে, ব্যাংক মিলকরণে, যদি কেউ
 * মিলকরণটা করত।
 */
final class DepositClaimService
{
    public function __construct(private readonly CollectionService $collections) {}

    /**
     * গ্রাহক একটা দাবি তুলছেন।
     *
     * @param  array<string, mixed>  $data
     */
    public function raise(Customer $customer, array $data): DepositClaim
    {
        $amount = Money::of((string) ($data['amount'] ?? '0'));

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('sales::portal.amount_must_be_positive'),
            ]);
        }

        $claimedOn = Carbon::parse((string) ($data['claimed_on'] ?? now()))->startOfDay();

        /*
         * আগামীকালের জমা বলে কিছু নেই।
         *
         * না আটকালে কেউ ভবিষ্যতের তারিখ দিয়ে দাবি তুলতেন, আর ডিপোর
         * তালিকায় ওটা সবার উপরে বসে থাকত — অথচ ব্যাংকের কাগজে ওটা
         * কোনোদিন আসত না।
         */
        if ($claimedOn->greaterThan(Carbon::today())) {
            throw ValidationException::withMessages([
                'claimed_on' => __('sales::portal.not_in_the_future'),
            ]);
        }

        return DepositClaim::create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'claimed_on' => $claimedOn->toDateString(),
            'amount' => $amount,
            'method' => $data['method'] ?? DepositClaim::BANK,
            'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => DepositClaim::PENDING,
        ]);
    }

    /**
     * ডিপো দাবিটা যাচাই করে গ্রহণ করছে — আর তখনই আদায়টা তৈরি হয়।
     *
     * @param  array<string, mixed>  $overrides  ডিপো যা সংশোধন করেছে
     */
    public function accept(DepositClaim $claim, int $accountId, array $overrides = []): DepositClaim
    {
        $this->assertPending($claim);

        return DB::transaction(function () use ($claim, $accountId, $overrides) {
            /*
             * ডিপো অঙ্ক ও তারিখ সংশোধন করতে পারে।
             *
             * গ্রাহক ৫০,০০০ লিখেছেন, ব্যাংকে এসেছে ৪৯,৯৫০ (চার্জ কাটা) —
             * ওরকম হয়ই। খাতায় বসবে যা সত্যিই এসেছে, যা দাবি করা
             * হয়েছে তা নয়। দাবির সারিটা অক্ষত থাকে, তাই তফাতটাও
             * পরে দেখা যায়।
             */
            $amount = Money::of((string) ($overrides['amount'] ?? $claim->amount));
            $date = Carbon::parse((string) ($overrides['trx_date'] ?? $claim->claimed_on))->toDateString();

            $collection = $this->collections->create([
                'customer_id' => $claim->customer_id,
                'branch_id' => $claim->branch_id,
                'trx_date' => $date,
                'amount' => $amount,
                'account_id' => $accountId,
                'instrument' => $claim->method,
                'instrument_no' => $claim->reference,
                'narration' => __('sales::portal.from_claim', ['no' => $claim->public_id]),
            ], []);

            $this->collections->confirm($collection);

            $claim->update([
                'status' => DepositClaim::ACCEPTED,
                'collection_id' => $collection->id,
                'decided_by' => auth()->id(),
                'decided_at' => now(),
            ]);

            return $claim->refresh();
        });
    }

    /**
     * দাবিটা ব্যাংকে পাওয়া যায়নি।
     *
     * কারণ বাধ্যতামূলক, আর সেটা গ্রাহক দেখতে পান। কারণ ছাড়া
     * প্রত্যাখ্যান মানে গ্রাহক আবার ফোন করবেন — আর ফোনটা এড়ানোই এই
     * পুরো ব্যবস্থার উদ্দেশ্য।
     */
    public function reject(DepositClaim $claim, string $reason): DepositClaim
    {
        $this->assertPending($claim);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'decision_reason' => __('sales::portal.reason_required'),
            ]);
        }

        $claim->update([
            'status' => DepositClaim::REJECTED,
            'decision_reason' => $reason,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        return $claim->refresh();
    }

    /**
     * এই গ্রাহকের দাবিগুলো — আর কারো নয়।
     *
     * @return Collection<int, DepositClaim>
     */
    public function forCustomer(Customer $customer): Collection
    {
        return DepositClaim::query()
            ->where('customer_id', $customer->id)
            ->where('company_id', $customer->company_id)
            ->orderByDesc('claimed_on')
            ->orderByDesc('id')
            ->get();
    }

    private function assertPending(DepositClaim $claim): void
    {
        if (! $claim->isPending()) {
            throw ValidationException::withMessages([
                'status' => __('sales::portal.already_decided'),
            ]);
        }
    }
}
