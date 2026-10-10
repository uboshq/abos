<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Services\MoneyAccountRule;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Services\MethodFitsAccount;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Models\SalesInvoice;
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

        $bills = $this->billsOf($customer, (array) ($data['bills'] ?? []), $amount);

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
            'bills' => $bills === [] ? null : $bills,
            'status' => DepositClaim::PENDING,
        ]);
    }

    /**
     * এই গ্রাহকের খোলা বিল — পুরনো আগে, বকেয়াসহ; বিজ্ঞপ্তির "কোন বিলের বিপরীতে" তালিকা (ফোন, ওয়েব, পোর্টাল একই উৎসে)।
     * ⓘ বকেয়া [[SalesInvoice::dueAmount()]]-এর হুবহু — আদায়, রসিদ ভাউচার আর পাকা ফেরত বাদ দিয়ে; আদায়ের পর্দার একই অঙ্ক।
     *
     * @return \Illuminate\Support\Collection<int, SalesInvoice>
     */
    public function openBills(Customer $customer, int $limit = 100): \Illuminate\Support\Collection
    {
        return SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->where('status', DocumentStatus::CONFIRMED)
            ->withCollected()
            ->orderBy('trx_date')->orderBy('id')
            ->get()
            ->filter(fn (SalesInvoice $invoice) => bccomp($invoice->dueAmount(), '0', 4) > 0)
            ->take($limit)
            ->values();
    }

    /**
     * ⭐ বাছা বিল যাচাই — নিজের, পাকা, বকেয়ার বেশি নয়, একই বিল দুইবার নয়, আর মোট জমার অঙ্কের বেশি নয় (টাকার পরিকল্পনা ২,
     * ৭ অক্টোবর ২০২৬)। শূন্য বা ফাঁকা অঙ্কের সারি বাদ। ⓘ আদায়ের নিজের যাচাইয়ের ([[CollectionService]] `replaceLines`)
     * একই কথা, আগেভাগে — ভুল বাছাই দাবি তোলার মুহূর্তেই ফেরে, ডিপোর টেবিলে গিয়ে নয়।
     *
     * @param  list<array<string, mixed>>  $rows  `[{sales_invoice_id, amount}]`
     * @return list<array{sales_invoice_id: int, amount: string}>
     */
    private function billsOf(Customer $customer, array $rows, string $amount): array
    {
        $bills = [];
        $sum = '0';

        foreach ($rows as $row) {
            $share = Money::of((string) ($row['amount'] ?? '0'));
            if (bccomp($share, '0', 4) <= 0) {
                continue;
            }

            $invoice = SalesInvoice::query()->whereKey((int) ($row['sales_invoice_id'] ?? 0))->first();
            if ($invoice === null || (int) $invoice->customer_id !== (int) $customer->id) {
                throw ValidationException::withMessages(['bills' => __('sales::slip.bill_not_theirs')]);
            }
            if ($invoice->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages(['bills' => __('sales::slip.bill_not_open', ['no' => $invoice->document_no])]);
            }
            if (isset($bills[$invoice->id])) {
                throw ValidationException::withMessages(['bills' => __('sales::slip.bill_twice', ['no' => $invoice->document_no])]);
            }

            $due = $invoice->dueAmount();
            if (bccomp($share, $due, 4) > 0) {
                throw ValidationException::withMessages(['bills' => __('sales::slip.bill_over_due', [
                    'no' => $invoice->document_no, 'due' => Money::format($due),
                ])]);
            }

            $bills[$invoice->id] = ['sales_invoice_id' => (int) $invoice->id, 'amount' => bcadd($share, '0', 4)];
            $sum = bcadd($sum, $share, 4);
        }

        if (bccomp($sum, $amount, 4) > 0) {
            throw ValidationException::withMessages(['bills' => __('sales::slip.bills_over_amount', [
                'sum' => Money::format($sum), 'amount' => Money::format($amount),
            ])]);
        }

        return array_values($bills);
    }

    /**
     * ⭐ গ্রহণের মুহূর্তে বিলের ভাগ — দাবির ক্রমে, প্রতিটা বিলে তিনের ছোটটা: বাছা অঙ্ক, এখনকার বকেয়া, আর গৃহীত টাকার বাকি।
     *
     * ⓘ দাবি আর গ্রহণের মাঝে সময় যায়: বিলটা ততক্ষণে অন্য আদায়ে শোধ হতে পারে, ফেরত আসতে পারে, বাতিলও হতে পারে; আর ডিপো
     * ব্যাংকের চার্জ কেটে অঙ্ক কমাতে পারে। ⛔ তখন আদায় আটকে দিলে ডিপো দাবিটা গ্রহণই করতে পারতেন না — তাই যা মেলে তা মেলে,
     * বাকি টাকা আগের মতো গ্রাহকের খাতায় (বিলে না বসা আদায়)। আদায়ের নিজের পাহারা ([[CollectionService]]) তারপরও চলে।
     *
     * @return list<array{sales_invoice_id: int, amount: string}>
     */
    private function sharesAt(DepositClaim $claim, string $amount): array
    {
        $left = $amount;
        $lines = [];

        foreach ((array) $claim->bills as $bill) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            $invoice = SalesInvoice::query()->whereKey((int) ($bill['sales_invoice_id'] ?? 0))->first();
            if ($invoice === null || (int) $invoice->customer_id !== (int) $claim->customer_id || $invoice->status !== DocumentStatus::CONFIRMED) {
                continue;
            }

            $share = self::least(Money::of((string) ($bill['amount'] ?? '0')), $invoice->dueAmount(), $left);
            if (bccomp($share, '0', 4) <= 0) {
                continue;
            }

            $lines[] = ['sales_invoice_id' => (int) $invoice->id, 'amount' => $share];
            $left = bcsub($left, $share, 4);
        }

        return $lines;
    }

    private static function least(string ...$amounts): string
    {
        return array_reduce($amounts, fn (?string $min, string $a) => $min === null || bccomp($a, $min, 4) < 0 ? $a : $min);
    }

    /**
     * ডিপো দাবিটা যাচাই করে গ্রহণ করছে — আর তখনই আদায়টা তৈরি হয়।
     *
     * @param  array<string, mixed>  $overrides  ডিপো যা সংশোধন করেছে
     */
    public function accept(DepositClaim $claim, int $accountId, array $overrides = []): DepositClaim
    {
        return DB::transaction(function () use ($claim, $accountId, $overrides) {
            // ⛔ অডিট §১.৪ — সারি আটকে আবার পড়া; দেখুন lockPending()
            $this->lockPending($claim);

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

            /*
             * ⛔ গ্রাহক যে পথে পাঠালেন, টাকা সেই ধরনের খাতেই — ২৮ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ অনুমোদনের পর্দা যেকোনো টাকার খাত বাছতে দেয়। "বিকাশে পাঠালাম"
             * দাবি ব্যাংকের খাতে মঞ্জুর হলে ব্যাংক-বিবরণী আর খাতা কোনোদিন
             * মিলত না, আর বিকাশের জের কম দেখাত। নিয়ম এক জায়গায়
             * ([[MethodFitsAccount]]) — কাউন্টার আর সেটিংসও ওটাই ডাকে।
             */
            // ⓘ আগে: টাকার খাত তো? (খরচের খাতে মঞ্জুর হলে বকেয়া মুছত, টাকা কোথাও আসত না)
            $account = app(MoneyAccountRule::class)->assert($accountId);

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'account_id' => __('accounts::validation.inactive_account', ['name' => $account->label()]),
                ]);
            }

            $methods = app(MethodFitsAccount::class);

            /*
             * ⚠️ "নগদ" দাবি ব্যাংকেও বসতে পারে — ডিলাররা কোম্পানির ব্যাংকের
             * শাখায় নগদ জমা দেন, পোর্টালে লেখেন "নগদ"। ⛔ কিন্তু বিকাশে নয়:
             * ওটা নগদ জমার কোনো পথ নয়।
             */
            $fits = $methods->fitsKind($account, $claim->method)
                || ($claim->method === DepositClaim::CASH && $account->isBank());

            if (! $fits) {
                throw ValidationException::withMessages([
                    'account_id' => $methods->message(
                        $account,
                        __('master_data::payment_kind.'.$claim->method),
                        (string) $claim->method,
                    ),
                ]);
            }

            $collection = $this->collections->create([
                'customer_id' => $claim->customer_id,
                'branch_id' => $claim->branch_id,
                'trx_date' => $date,
                'amount' => $amount,
                'account_id' => $accountId,
                'instrument' => $claim->method,
                'instrument_no' => $claim->reference,
                'narration' => __('sales::portal.from_claim', ['no' => $claim->public_id]),
            // ⭐ বাছা বিলে মেলে — না বাছলে আগের মতো খালি, গ্রাহকের খাতায় মোট টাকা ([[sharesAt()]])
            ], $this->sharesAt($claim, $amount));

            $this->collections->confirm($collection);

            $claim->update([
                'status' => DepositClaim::ACCEPTED,
                'collection_id' => $collection->id,
                'decided_by' => \App\Core\Support\Actor::userId(),
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
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'decision_reason' => __('sales::portal.reason_required'),
            ]);
        }

        return DB::transaction(function () use ($claim, $reason) {
            // ⛔ গ্রহণের সাথে একই তালা — নইলে গৃহীত দাবি পুরনো পাতা থেকে "নাকচ" হত
            $this->lockPending($claim);

            $claim->update([
                'status' => DepositClaim::REJECTED,
                'decision_reason' => $reason,
                'decided_by' => \App\Core\Support\Actor::userId(),
                'decided_at' => now(),
            ]);

            return $claim->refresh();
        });
    }

    /**
     * ⭐ যাচাই শুরু — পাঠানো বিজ্ঞপ্তি "যাচাই চলছে" হয় (টাকার পরিকল্পনা ১, ৭ অক্টোবর ২০২৬; Submitted → Under Verification)।
     *
     * ⓘ দোকানি আর SR পোর্টালে বা ফোনে দেখেন কেউ ধরেছেন — "দেখছে কেউ?" ফোনটা লাগে না। ⛔ কেবল পাঠানো অবস্থা থেকে, তালা দিয়ে:
     * এইমাত্র গৃহীত বা প্রত্যাখ্যাত বিজ্ঞপ্তি পুরনো পাতা থেকে আবার "যাচাই চলছে" হয় না। খাতায় কিছু ওঠে না।
     */
    public function startVerifying(DepositClaim $claim): DepositClaim
    {
        return DB::transaction(function () use ($claim) {
            $fresh = DepositClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== DepositClaim::PENDING) {
                throw ValidationException::withMessages([
                    'status' => __($fresh->isOpen() ? 'sales::portal.already_verifying' : 'sales::portal.already_decided'),
                ]);
            }

            $fresh->update(['status' => DepositClaim::VERIFYING]);

            return $claim->setRawAttributes($fresh->refresh()->getAttributes(), true);
        });
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

    /**
     * দাবির সারিটা আটকে **ডাটাবেজ থেকে আবার পড়া**, তারপর অপেক্ষমাণ কি না দেখা।
     *
     * ── ⛔ অডিট §১.৪, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * আগে অবস্থাটা দেখা হত লেনদেনের বাইরে, হাতে থাকা মডেল থেকে। ⚠️ দুইবার
     * ক্লিক করলে দুইটা অনুরোধই দাবিটা "অপেক্ষমাণ" পড়ত, আর প্রতিটা একটা
     * **নতুন** আদায় বানাত — পোস্টিং ইঞ্জিনের "এক কাগজ একবার" পাহারা তাই
     * ধরত না। ৳২,৫০,০০০-এর দাবিতে বকেয়া কমত ৳৫,০০,০০০।
     *
     * ⭐ এখন `lockForUpdate()` দ্বিতীয় অনুরোধকে প্রথমটার লেনদেন শেষ হওয়া
     * পর্যন্ত দাঁড় করায়, আর সে পড়ে প্রথমটার ফল — "সিদ্ধান্ত হয়ে গেছে"।
     * ⓘ হাতের মডেলটাও তাজা সারিতে বদলে দেওয়া হয়, যাতে বাকি কাজ পুরনো
     * ছবির উপর না চলে। অবশ্যই `DB::transaction`-এর ভিতরে ডাকতে হবে।
     */
    private function lockPending(DepositClaim $claim): void
    {
        $fresh = DepositClaim::query()
            ->whereKey($claim->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $claim->setRawAttributes($fresh->getAttributes(), true);

        $this->assertPending($claim);
    }

    private function assertPending(DepositClaim $claim): void
    {
        // ⓘ যাচাই চলছে এমন বিজ্ঞপ্তিও এখনো খোলা — সিদ্ধান্ত সেখান থেকেও (টাকার পরিকল্পনা ১, ৭ অক্টোবর ২০২৬)
        if (! $claim->isOpen()) {
            throw ValidationException::withMessages([
                'status' => __('sales::portal.already_decided'),
            ]);
        }
    }
}
