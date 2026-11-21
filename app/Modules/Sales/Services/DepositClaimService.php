<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
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
 * ⭐ ২০ নভেম্বর ২০২৬ থেকে আদায় (COL) নয়, রসিদ ভাউচার (RCV) — মালিক: "টাকা ঢোকার কাগজ একটাই" ([[accept()]])।
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

        return DB::transaction(function () use ($customer, $data, $amount, $claimedOn, $bills) {
            $this->assertReferenceIsFree($data, $customer, $claimedOn);

            return $this->write($customer, $data, $amount, $claimedOn, $bills);
        });
    }

    /**
     * ⛔ একই ব্যাংক খাত আর একই রেফারেন্সে দুইটা খোলা বা গৃহীত বিজ্ঞপ্তি নয় — টাকার পরিকল্পনা ৪ (সমন্বয়ক, ৭ অক্টোবর ২০২৬)।
     *
     * ⓘ একই স্লিপ SR একবার, দোকানি পোর্টালে আবার পাঠালে দুটোই গ্রহণ হয়ে একই টাকা দুইবার জমা হত। প্রত্যাখ্যাত বাদ — ভুল
     * ধরে আবার পাঠানো যায়। রেফারেন্স মেলে বড়-ছোট হাত আর ফাঁকা জায়গা বাদ দিয়ে; অন্য দোকানের নামেও থামে, কারণ ব্যাংকের
     * একটা লেনদেন একজনেরই। ⛔ খাতের সারিতে তালা — একই মুহূর্তে দুই দরজা দিয়ে এলেও দ্বিতীয়টা প্রথমটাকে দেখে।
     * ⓘ ডিলারের দেয়াল বাদ দিয়ে খোঁজা — SR যে দোকান দেখেন না, তার বিজ্ঞপ্তিও একই স্লিপ ধরে রাখে; কোম্পানির দেয়াল থাকে।
     *
     * ⭐ জমার ভাগ — মালিক, ২১ নভেম্বর ২০২৬ (৳১,০০,০০০ জমা, নম্বর 175300556, দুই ডিলারের ৫০,০০০ করে): একটা জমা কয়েকজন ডিলারের
     * নামে ভাগ হতে পারে। তাই একই খবর কেবল একই রেফারেন্স + একই খাত + একই জমার দিন + **একই দোকান** — রসিদের পাহারার একই
     * নিয়ম ([[VoucherService::sameDeposit()]])। ⛔ আগে "অন্য দোকানের নামেও থামে" ছিল; সেটা ভাগটাকেই আটকাত। ⓘ একই দোকানের একই
     * স্লিপ SR আর দোকানি দুইবার পাঠালে আগের মতোই থামে। ⓘ জমার খবর কাগজের খসড়া নয় — খোলা খবরও দ্বিতীয়টাকে থামায়, কেবল
     * প্রত্যাখ্যাতটা বাদ।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertReferenceIsFree(array $data, Customer $customer, Carbon $claimedOn): void
    {
        $reference = self::plainReference($data['reference'] ?? null);
        $account = $data['bank_account_id'] ?? null;

        if ($reference === '') {
            return;
        }

        /*
         * ⛔ খাত না বললেও — ১০ অক্টোবর ২০২৬: ব্যাংক খাত ঐচ্ছিক ঘর, আর আগে খাত না থাকলে এই পাহারা কিছুই দেখত না; পুরনো unique
         * (দোকান + রেফারেন্স) অন্তত একই দোকানের একই রেফারেন্স আটকাত, তাই 6b1dcc50-এর পরে সেটুকুও খোলা ছিল। ⓘ খাতবিহীন
         * বিজ্ঞপ্তিগুলো নিজেদের মধ্যে মেলে; তালা তখন দোকানের সারিতে (দুইবার চাপ সাধারণত একই দোকানের)।
         */
        $account === null
            ? Customer::query()->withoutGlobalScopes()->whereKey($customer->getKey())->lockForUpdate()->first()
            : \App\Modules\Accounts\Models\Account::query()->whereKey($account)->lockForUpdate()->first();

        $taken = DepositClaim::query()->withoutGlobalScopes()
            ->where('company_id', \App\Core\Support\CompanyContext::id())
            ->whereNull('deleted_at')
            ->when($account === null, fn ($q) => $q->whereNull('bank_account_id'), fn ($q) => $q->where('bank_account_id', $account))
            ->where('status', '!=', DepositClaim::REJECTED)
            ->where('customer_id', $customer->getKey())
            ->whereDate('claimed_on', $claimedOn->toDateString())
            ->whereNotNull('reference')
            ->whereRaw("UPPER(REPLACE(reference, ' ', '')) = ?", [$reference])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['reference' => __('sales::portal.reference_taken')]);
        }
    }

    /** ⓘ মেলানোর রূপ — বড় হাত, ফাঁকা জায়গা ছাড়া */
    private static function plainReference(mixed $reference): string
    {
        return strtoupper(str_replace(' ', '', trim((string) $reference)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, mixed>  $bills
     */
    private function write(Customer $customer, array $data, string $amount, Carbon $claimedOn, array $bills): DepositClaim
    {
        return DepositClaim::create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            // ⭐ কে পাঠালেন — কর্মী হলে তিনি, পোর্টালের দোকানি হলে খালি (টাকার পরিকল্পনা ৩; [[DepositClaim::booted()]]-এর পাহারা)
            'submitted_by' => \App\Core\Support\Actor::userId(),
            'claimed_on' => $claimedOn->toDateString(),
            'amount' => $amount,
            'method' => $data['method'] ?? DepositClaim::BANK,
            'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'note' => $data['note'] ?? null,
            'depositor' => filled($data['depositor'] ?? null) ? trim((string) $data['depositor']) : null,
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
            // ⓘ গ্রাহকের নিজের শাখার বিলই — দাবির আদায় সেই শাখায় বসে, অন্যগুলো বাছা যায় না ([[billsOf()]], PR #17 রিভিউ ⚠️১২)
            ->when($customer->branch_id !== null, fn ($q) => $q->where('sal_invoices.branch_id', $customer->branch_id))
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
            /*
             * ⛔ অন্য শাখার বিল এই দাবিতে নয় — আগেভাগেই বলা (১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️১২)। ⓘ আদায় বসে দাবির শাখায়
             * (গ্রাহকের শাখা) আর গ্রহণের সময় বিল খোঁজা হয় সেই শাখাতেই ([[sharesAt()]]) — অন্য শাখার বিল তখন চুপচাপ বাদ পড়ত,
             * টাকা খাতায় অ-প্রযুক্ত জমা হয়ে বসত, কেউ জানত না কেন। এখন দাবি তোলার মুহূর্তেই ফেরে, বিলের নম্বরসহ।
             */
            if ($customer->branch_id !== null && (int) $invoice->branch_id !== (int) $customer->branch_id) {
                throw ValidationException::withMessages(['bills' => __('sales::slip.bill_other_branch', ['no' => $invoice->document_no])]);
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
     * @return list<array{sales_invoice_id: int, public_id: string, amount: string}>
     */
    private function sharesAt(DepositClaim $claim, string $amount): array
    {
        $left = $amount;
        $lines = [];

        foreach ((array) $claim->bills as $bill) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            /*
             * ⛔ দাবির কোম্পানি, গ্রাহক আর শাখা ধরে — গ্রহণকারীর হেডারের শাখায় নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, বিক্রয় ৩;
             * [[AClaimedBillIsFoundWhateverTheAcceptersHeaderTest]])। ⓘ আগে শাখার দেয়াল গ্রহণকারীর হেডার দেখত: অন্য শাখায় বসে
             * মঞ্জুর করলে বাছা বিল "নেই", চুপচাপ বাদ, আর টাকা খাতায় খালি জমা হয়ে বসত। ⚠️ বিল দাবির শাখারই হতে হয় — আদায় সেই
             * শাখায় বসে, অন্য শাখার বিলে ভাগ দিলে দুই শাখার পাওনা ভুল হত।
             */
            $invoice = SalesInvoice::acrossBranches()
                ->where('sal_invoices.company_id', $claim->company_id)
                ->when($claim->branch_id !== null, fn ($q) => $q->where('sal_invoices.branch_id', $claim->branch_id))
                ->whereKey((int) ($bill['sales_invoice_id'] ?? 0))->first();
            if ($invoice === null || (int) $invoice->customer_id !== (int) $claim->customer_id || $invoice->status !== DocumentStatus::CONFIRMED) {
                continue;
            }

            $share = self::least(Money::of((string) ($bill['amount'] ?? '0')), $invoice->dueAmount(), $left);
            if (bccomp($share, '0', 4) <= 0) {
                continue;
            }

            $lines[] = ['sales_invoice_id' => (int) $invoice->id, 'public_id' => (string) $invoice->public_id, 'amount' => $share];
            $left = bcsub($left, $share, 4);
        }

        return $lines;
    }

    /**
     * ⭐ বিল না বাছা খবরের টাকা গ্রাহকের খোলা বিলে, পুরনো আগে — প্রতিটায় তার বকেয়া পর্যন্ত (টাকার পরিকল্পনা ৬; আদায়ের
     * [[CollectionService::oldestFirst()]]-এর একই নিয়ম, ২০ নভেম্বর ২০২৬ থেকে রসিদে)। ⓘ কেবল খবরের শাখার বিল; বাড়তি টাকা গ্রাহকের খাতায়।
     *
     * @return list<array{sales_invoice_id: int, public_id: string, amount: string}>
     */
    private function oldestFirst(DepositClaim $claim, string $amount): array
    {
        $left = $amount;
        $lines = [];

        $bills = SalesInvoice::acrossBranches()
            ->where('sal_invoices.company_id', $claim->company_id)
            ->where('sal_invoices.customer_id', $claim->customer_id)
            ->where('sal_invoices.status', DocumentStatus::CONFIRMED)
            ->when($claim->branch_id !== null, fn ($q) => $q->where('sal_invoices.branch_id', $claim->branch_id))
            ->withCollected()
            ->orderBy('sal_invoices.trx_date')->orderBy('sal_invoices.id')
            ->get();

        foreach ($bills as $bill) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            $share = self::least($bill->dueAmount(), $left);

            if (bccomp($share, '0', 4) <= 0) {
                continue;
            }

            $lines[] = ['sales_invoice_id' => (int) $bill->id, 'public_id' => (string) $bill->public_id, 'amount' => $share];
            $left = bcsub($left, $share, 4);
        }

        return $lines;
    }

    private static function least(string ...$amounts): string
    {
        return array_reduce($amounts, fn (?string $min, string $a) => $min === null || bccomp($a, $min, 4) < 0 ? $a : $min);
    }

    /**
     * ডিপো দাবিটা যাচাই করে গ্রহণ করছে — আর তখনই রসিদ ভাউচারটা তৈরি হয় (২০ নভেম্বর ২০২৬-এর আগে আদায় হত)।
     *
     * @param  array<string, mixed>  $overrides  ডিপো যা সংশোধন করেছে
     */
    public function accept(DepositClaim $claim, ?int $accountId, array $overrides = []): DepositClaim
    {
        return DB::transaction(function () use ($claim, $accountId, $overrides) {
            // ⛔ অডিট §১.৪ — সারি আটকে আবার পড়া; দেখুন lockPending()
            $this->lockPending($claim);

            /*
             * ⭐ সইয়ের অপেক্ষার খসড়া আগেই আছে — নতুন কাগজ নয়, সেটাই আবার পাকা করার চেষ্টা (ধাপ গ, ১৮ নভেম্বর ২০২৬)।
             * ⛔ নইলে "অনুমোদন দিন" দুইবার চাপলে দুইটা খসড়া, আর দুই সইয়ে দুইবার খাতায়।
             * ⓘ নতুন খবরে রসিদ ভাউচার (২০ নভেম্বর ২০২৬); ২০ নভেম্বরের আগে বসা আদায়ের খসড়া (COL) নিজের পুরনো পথে শেষ হয়।
             */
            if ($claim->voucher_id !== null) {
                return $this->postTheWaitingReceipt($claim);
            }

            if ($claim->collection_id !== null) {
                return $this->confirmTheWaitingDraft($claim);
            }

            /*
             * ⭐ শেষ কথা ব্যাংক স্টেটমেন্ট — মালিক, ১৬ নভেম্বর ২০২৬ (ধাপ গ; [[DepositMatchService]])।
             * ⓘ মেলানো থাকলে খাত, অঙ্ক আর তারিখ ব্যাংকের সারি থেকে — হাতের লেখা নয়; চার্জ কেবল অফিস বলে। ⛔ ব্যাংক বা MFS খাতে
             * নিতে চাইলে আর মেলানো না থাকলে থামে (সুইচ [[DepositClaim::BANK_LINE_FIRST]], ডিফল্ট চালু)। নগদের খাতে আগের মতো।
             */
            $line = $claim->statement_line_id === null ? null
                : \App\Modules\Accounts\Models\BankStatementLine::query()->whereKey($claim->statement_line_id)->first();

            if ($line !== null) {
                if ($accountId !== null && $accountId !== (int) $line->bank_account_id) {
                    throw ValidationException::withMessages(['account_id' => __('sales::deposit.other_bank_account')]);
                }
                $accountId = (int) $line->bank_account_id;
                $overrides['amount'] = (string) $line->credit;
                $overrides['trx_date'] = $line->trx_date->toDateString();
            }

            if ($accountId === null) {
                throw ValidationException::withMessages(['account_id' => __('sales::deposit.account_needed')]);
            }

            if ($line === null) {
                $target = \App\Modules\Accounts\Models\Account::query()->find($accountId);
                if ($target !== null && ($target->isBank() || $target->isMfs())
                    && (bool) app(\App\Core\Services\SettingsService::class)->get(DepositClaim::BANK_LINE_FIRST, true)) {
                    throw ValidationException::withMessages(['account_id' => __('sales::deposit.not_in_the_bank_yet')]);
                }
            }

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
             * ⭐ ব্যাংক চার্জ নিজের খাতে — টাকার পরিকল্পনা ৫ (মালিক, ৭ অক্টোবর ২০২৬; [[TheBankChargeGoesToItsOwnHeadTest]])।
             * ⓘ উপরের অঙ্ক যা সত্যিই ব্যাংকে এসেছে (৪৯,৯৫০); চার্জ (৫০) ব্যাংক রেখে দিয়েছে। ⚠️ আগে চার্জের ঘর ছিল না: অঙ্ক
             * কমালে ডিলারের পাওনাও কম কমত, ৫০ টাকা তাঁর নামে বকেয়া থেকে যেত — অথচ তিনি পুরোটা পাঠিয়েছেন। এখন আদায়টা
             * ৫০,০০০-এর (পাওনা ততটাই কমে, বিলেও ততটাই মেলে), আর খাতায় ব্যাংক ৪৯,৯৫০ + ব্যাংক চার্জ ৫০ (২০ নভেম্বর ২০২৬ থেকে রসিদের চার্জের ঘরে — [[VoucherService::twoLineEntry()]])।
             */
            $charge = Money::of((string) ($overrides['bank_charge'] ?? '0'));
            $credit = bcadd($amount, $charge, 4);

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

            /*
             * ⭐ টাকা ঢোকার কাগজ একটাই — রসিদ ভাউচার (RCV-…), আদায় (COL-…) নয় (মালিক, ২০ নভেম্বর ২০২৬, চূড়ান্ত)।
             * ⓘ জমার খবর কেবল খবর; হিসাবরক্ষক ব্যাংকে মিলিয়ে গ্রহণ করলে রসিদের নিজের লেখকের হাতে ([[VoucherWriter::store()]]) — রসিদের
             * পর্দার একই ধাপ: ছাঁচ, বিলের ভাগ, রসিদের নিজের অনুমোদনের ছক, লেখক ≠ পাকাকারী, তারপর খাতা। দ্বিতীয় কোনো পোস্টিং পথ নয়।
             * ⓘ রসিদ খবরের শাখায় — বিলের ভাগ যাচাইও সেই শাখায় চলে, গ্রহণকারীর হেডারে নয় ([[CompanyContext::inBranch()]])।
             */
            [$voucher, $waiting] = CompanyContext::inBranch($claim->branch_id === null ? null : (int) $claim->branch_id,
                fn () => $this->writeTheReceipt($claim, $account, $date, $credit, $charge, $line));

            $claim->forceFill(['voucher_id' => $voucher->id])->save();

            // ⓘ সই বা অন্য হাতের অপেক্ষায় — খবর খোলা থাকে "অনুমোদনের অপেক্ষায়"; রসিদ পাকা হলে খবর নিজে "খাতায়" ([[settleWithVoucher()]])
            if ($waiting !== false) {
                return $claim->refresh();
            }

            return $this->acceptNow($claim);
        });
    }

    /**
     * ⭐ খবর থেকে রসিদ ভাউচার — রসিদের পর্দা যা পাঠায়, ঠিক তাই (মালিক, ২০ নভেম্বর ২০২৬)।
     *
     *   পক্ষ        গ্রাহক (প্রাপ্য ১১১০ ক্রেডিটে, [[\App\Modules\Accounts\Http\Requests\VoucherRequest::fillAccountFromParty()]]-এর একই খাত)
     *   অঙ্ক        যা পাঠানো হলো = ব্যাংকে এসেছে + চার্জ; চার্জ নিজের খাতে (৫২১০/৫২১১), পাওনা পুরোটায় কমে (টাকার পরিকল্পনা ৫)
     *   খাত         টাকার খাত — মেলানো থাকলে ব্যাংকের সারির খাত
     *   মাধ্যম       ব্যাংকে জমা ('deposit'), MFS ('mfs'), ক্যাশে নগদ ('cash')
     *   নম্বর       খবরের রেফারেন্স → ব্যাংকের সারির রেফারেন্স → খবরের নম্বর (ব্যাংক বা MFS খাতে রসিদ নম্বর চায়, আর একই খাতে
     *               একই নম্বর দুবার নয় — অন্য কেউ একই জমায় আগেই রসিদ কেটে থাকলে এখানে থামে, টাকা দুবার ওঠে না)
     *   বিল         খবরে বাছা বিল, গ্রহণের মুহূর্তের বকেয়ায় ([[sharesAt()]]); না বাছলে গ্রাহকের খাতায় মোট টাকা
     *
     * @return array{0: \App\Modules\Accounts\Models\Voucher, 1: bool|string}
     */
    private function writeTheReceipt(DepositClaim $claim, \App\Modules\Accounts\Models\Account $account, string $date, string $credit, string $charge, ?\App\Modules\Accounts\Models\BankStatementLine $line): array
    {
        $receipt = \App\Modules\Accounts\Models\Voucher::RECEIPT;

        $instrument = match (true) {
            $account->isMfs() => 'mfs',
            $account->isBank() => 'deposit',
            default => 'cash',
        };

        $reference = collect([$claim->reference, $line?->reference])
            ->map(fn ($r) => trim((string) $r))->first(fn (string $r) => $r !== '');

        if ($reference === null && $instrument !== 'cash') {
            $reference = (string) $claim->public_id;
        }

        $data = [
            'type' => $receipt,
            'branch_id' => $claim->branch_id === null ? null : (int) $claim->branch_id,
            'trx_date' => $date,
            'party_type' => 'customer',
            'party_id' => (int) $claim->customer_id,
            'from_account_id' => (int) \App\Modules\Accounts\Services\StandardChart::accountFor(\App\Modules\Accounts\Services\StandardChart::RECEIVABLE)->id,
            'to_account_id' => (int) $account->id,
            'amount' => $credit,
            'charge_amount' => bccomp($charge, '0', 4) > 0 ? $charge : null,
            'instrument' => $instrument,
            'instrument_no' => $reference,
            'deposit_slip_no' => $instrument === 'deposit' && filled($claim->reference) ? trim((string) $claim->reference) : null,
            'narration' => (string) __('sales::portal.from_claim', ['no' => $claim->public_id]),
        ];

        /*
         * ⭐ বাছা বিলে মেলে — রসিদের "এক টাকা, বহু বিল" ঘরে (`bills[i][id]` বিলের public_id)। ⭐ কিছুই না মিললে সবচেয়ে পুরনো বিল আগে —
         * টাকার পরিকল্পনা ৬ (মালিক, ৭ অক্টোবর ২০২৬), আদায়ের দিনের একই নিয়ম; নইলে পাওনা কমত অথচ প্রতিটা বিল "বাকি" দেখাত।
         */
        $shares = $this->sharesAt($claim, $credit);

        if ($shares === []) {
            $shares = $this->oldestFirst($claim, $credit);
        }

        $bills = array_map(fn (array $share): array => ['id' => $share['public_id'], 'amount' => $share['amount']], $shares);

        if ($bills !== []) {
            $data['bills'] = $bills;
        }

        $writer = app(\App\Modules\Accounts\Services\VoucherWriter::class);

        return $writer->store($data, $writer->linesFor($receipt, $data), asDraft: false);
    }

    /**
     * ⭐ সইয়ের বা অন্য হাতের অপেক্ষার রসিদ — রসিদের নিজের পাকা করার দরজায় ([[VoucherWriter::post()]]: তালা, সই, তারপর খাতা)।
     * ⓘ সই এখনো বাকি হলে খবর খোলা থাকে; রসিদ বাতিল হয়ে গেলে কারণসহ থামে।
     */
    private function postTheWaitingReceipt(DepositClaim $claim): DepositClaim
    {
        $voucher = \App\Modules\Accounts\Models\Voucher::query()->withoutGlobalScope('user-branch')->findOrFail($claim->voucher_id);

        if ($voucher->isDraft()) {
            $stopping = CompanyContext::inBranch($claim->branch_id === null ? null : (int) $claim->branch_id,
                fn () => app(\App\Modules\Accounts\Services\VoucherWriter::class)->post($voucher));

            if ($stopping !== null) {
                return $claim->refresh();
            }

            $voucher->refresh();
        }

        if (! $voucher->isPosted()) {
            throw ValidationException::withMessages(['status' => __('sales::deposit.draft_gone', ['no' => $voucher->document_no])]);
        }

        return $this->acceptNow($claim);
    }

    private function acceptNow(DepositClaim $claim): DepositClaim
    {
        $claim->update([
            'status' => DepositClaim::ACCEPTED,
            'decided_by' => Actor::userId(),
            'decided_at' => now(),
        ]);

        return $claim->refresh();
    }

    /**
     * ⭐ আদায়ের খসড়া পাকা করা — আদায়ের নিজের দরজায় ([[CollectionService::confirm()]]): অনুমোদনের ছক, অঙ্কের সীমা, আর
     * "অনুমোদন বন্ধ" কোম্পানি সব সেখানেই — দ্বিতীয় কোনো পোস্টিং পথ নয় (ধাপ গ, ১৮ নভেম্বর ২০২৬)।
     *
     * ⓘ ছক সই চাইলে ([[HeldForApproval]]) খসড়া আর অনুরোধ দুইটাই থাকে, খবর খোলা থাকে "অনুমোদনের অপেক্ষায়"; শেষ সইয়ে আদায়
     * নিজে পাকা হয় ([[ConfirmTheCollectionOnTheLastSignature]]) আর খবর "খাতায়" ([[\App\Modules\Sales\Listeners\SettleTheClaimWithItsCollection]])।
     * ⚠️ অন্য যেকোনো থামা (বিল বাতিল, রেফারেন্স) পুরো লেনদেন ফিরিয়ে দেয় — আগের মতোই।
     * ⓘ অবশ্যই `DB::transaction`-এর ভিতরে, খবর তালায়।
     * ⓘ ২০ নভেম্বর ২০২৬ থেকে কেবল আগে বসা আদায়ের খসড়ায় — নতুন খবর রসিদ ভাউচারে ([[postTheWaitingReceipt()]])।
     */
    private function confirmTheWaitingDraft(DepositClaim $claim): DepositClaim
    {
        $collection = \App\Modules\Sales\Models\Collection::query()->withoutGlobalScope('user-branch')->findOrFail($claim->collection_id);

        if ($collection->status === DocumentStatus::DRAFT) {
            try {
                CompanyContext::inBranch($claim->branch_id === null ? null : (int) $claim->branch_id, fn () => $this->collections->confirm($collection));
            } catch (\App\Core\Engines\Approval\HeldForApproval) {
                return $claim->refresh();
            }
        } elseif ($collection->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages(['status' => __('sales::deposit.draft_gone', ['no' => $collection->document_no])]);
        }

        $claim->update([
            'status' => DepositClaim::ACCEPTED,
            'decided_by' => Actor::userId(),
            'decided_at' => now(),
        ]);

        return $claim->refresh();
    }

    /**
     * ⭐ খবরের রসিদ ভাউচার পাকা হলো — শেষ সইয়ে নিজে ([[\App\Modules\Accounts\Listeners\PostTheVoucherOnTheLastSignature]]) বা
     * হিসাবে অন্য কেউ হাতে — খবর "খাতায়" (মালিক, ২০ নভেম্বর ২০২৬)। একবারই; খোলা খবরেই।
     */
    public function settleWithVoucher(int $voucherId, ?int $by): void
    {
        DB::transaction(function () use ($voucherId, $by): void {
            $claim = DepositClaim::query()->withoutGlobalScopes()
                ->where('voucher_id', $voucherId)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($claim === null || ! $claim->isOpen()) {
                return;
            }

            $claim->update(['status' => DepositClaim::ACCEPTED, 'decided_by' => $by, 'decided_at' => now()]);
        });
    }

    /**
     * ⭐ খোলা খবরের অপেক্ষার রসিদ হিসাব থেকে বাতিল হলো — খবর আবার রসিদহীন, "অনুমোদন দিন" নতুন রসিদ লেখে (২০ নভেম্বর ২০২৬)।
     * ⓘ গৃহীত খবরে হাত দেয় না — পাকা রসিদ পরে উল্টালে খবর "খাতায়"-ই থাকে, জমার খাতা কেবল পাকা রসিদ গোনে ([[DepositRegisterReport]])।
     */
    public function releaseCancelledVoucher(int $voucherId): void
    {
        DepositClaim::query()->withoutGlobalScopes()
            ->where('voucher_id', $voucherId)->whereNull('deleted_at')->whereIn('status', DepositClaim::OPEN)
            ->get()
            ->each(fn (DepositClaim $claim) => $claim->forceFill(['voucher_id' => null])->save());
    }

    /**
     * ⭐ আদায় পাকা হলো (শেষ সইয়ে বা হাতে) — তার খবর "খাতায়"। একবারই; খোলা খবরেই।
     * ⓘ কেবল ২০ নভেম্বর ২০২৬-এর আগে বসা আদায়ের খসড়ার জন্য — নতুন খবর রসিদে ([[settleWithVoucher()]])।
     */
    public function settleWith(int $collectionId, ?int $by): void
    {
        DB::transaction(function () use ($collectionId, $by): void {
            $claim = DepositClaim::query()->withoutGlobalScopes()
                ->where('collection_id', $collectionId)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($claim === null || ! $claim->isOpen()) {
                return;
            }

            $claim->update(['status' => DepositClaim::ACCEPTED, 'decided_by' => $by, 'decided_at' => now()]);
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

            // ⭐ ফেরত খবর ব্যাংকের সারি ছেড়ে দেয়, আর সইয়ের অপেক্ষার খসড়া আদায় বাতিল (ধাপ গ, ১৮ নভেম্বর ২০২৬)
            app(DepositMatchService::class)->dropTheWaitingDraft($claim, $reason);

            $claim->update([
                'statement_line_id' => null,
                'status' => DepositClaim::REJECTED,
                'decision_reason' => $reason,
                'decided_by' => Actor::userId(),
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
