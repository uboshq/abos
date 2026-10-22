<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\OpenPeriod;
use App\Core\Services\SettingsService;
use App\Core\Services\SlipIsNotUsedTwice;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\MoneyMethod;
use App\Core\Support\Settlement;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Events\CollectionConfirmed;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionApplication;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * আদায় — টাকা এসেছে।
 *
 *     Dr  নগদ / ব্যাংক (যেখানে টাকাটা ঢুকল)
 *     Cr  প্রাপ্য হিসাব (1110, গ্রাহকের নামে)
 *
 * ── কেন লাইনগুলো বিলের, পণ্যের নয় ────────────────────────────────────
 * গ্রাহক এক লাখ টাকা দিলেন। তাতে তিনটা পুরনো বিল পুরো শোধ হলো আর চতুর্থটা
 * আংশিক। ভাগটা না রাখলে "কোন বিলটা এখনো বাকি" প্রশ্নের উত্তর থাকত না —
 * শুধু মোট বকেয়া জানা যেত, আর তাগাদা দিতে গিয়ে কোন বিলের কথা বলতে হবে
 * তা কেউ বলতে পারত না।
 */
final class CollectionService
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly CashTillService $tills,
        private readonly ChequeService $cheques,

        /* ⓘ নিয়মটা কোরে — ক্রয়ের পরিশোধও ঠিক এই একই যন্ত্র ডাকে। */
        private readonly SlipIsNotUsedTwice $slips,
        private readonly DocumentApproval $approvals,

        /* ⓘ টাকা নেওয়ার নকশা ১০০ (২১ অক্টোবর ২০২৬): ছাড়ের সই, রাউন্ডিংয়ের সীমা, খোলা ক্রেডিট আর মাস খোলা কি না */
        private readonly ApprovalEngine $engine,
        private readonly SettingsService $settings,
        private readonly CollectionOpenItems $openItems,
        private readonly OpenPeriod $periods,
    ) {}

    /** ⓘ রাউন্ডিংয়ের ডিফল্ট সীমা — নিয়ম এখন এক জায়গায়, পরিশোধের সাথে ভাগ করা ([[Settlement::LIMIT]]) */
    public const ROUNDING_LIMIT = Settlement::LIMIT;

    /** ⓘ সই ছাড়া রাউন্ডিং — ৳০.৫০ পর্যন্ত ([[Settlement::UNSIGNED]]; মালিক, ১ অক্টোবর ২০২৬) */
    public const ROUNDING_UNSIGNED = Settlement::UNSIGNED;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): Collection
    {
        $this->assertNoCheque($data['instrument'] ?? null);

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $amount = $this->money($data['amount'] ?? null);

            if (bccomp($amount, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('sales::validation.collection_must_be_positive'),
                ]);
            }

            /*
             * ⭐ ব্যাংক চার্জ নিজের খাতে — টাকার পরিকল্পনা ৫ (মালিক, ৭ অক্টোবর ২০২৬; [[TheBankChargeGoesToItsOwnHeadTest]])।
             * ⓘ `amount` ডিলারের পাওনা থেকে যা কমে; চার্জ তার যে অংশ ব্যাংক রেখে দিয়েছে। টাকার খাতে আসে বাকিটা — তাই চার্জ
             * শূন্যের নিচে বা পুরো অঙ্কের সমান-বেশি হতে পারে না (তখন খাতে কিছুই আসত না)।
             */
            $charge = $this->money($data['bank_charge'] ?? '0');

            if (bccomp($charge, '0', 4) < 0 || bccomp($charge, $amount, 4) >= 0) {
                throw ValidationException::withMessages([
                    'bank_charge' => __('sales::validation.bank_charge_out_of_range', ['amount' => Money::format($amount)]),
                ]);
            }

            /*
             * ⛔ একই স্লিপ দুইবার নয় — মালিকের নিয়ম, ৫ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ আদায়ে এটা পরিশোধের চেয়েও দামি: একই বিকাশ TrxID দুইবার
             * বসালে গ্রাহকের পাওনা **দুইবার কমে**, অথচ টাকা এসেছে একবার।
             * ⓘ ধরা পড়ে মাস শেষে মেলাতে গিয়ে, আর তখন কোনটা আসল তা বলা
             * কঠিন।
             *
             * ⓘ নম্বর নেওয়ার আগে — আটকালে সিরিজে ফাঁক পড়ত।
             */
            $this->holdTheCustomersSlips((int) $data['customer_id']);

            $this->slips->check(
                table: 'sal_collections',
                slipNo: $data['instrument_no'] ?? null,
                partyId: (int) $data['customer_id'],
                party: 'customer_id',
                message: __('sales::validation.slip_used_twice', [
                    'no' => trim((string) ($data['instrument_no'] ?? '')),
                ]),
            );

            /*
             * ⛔ গ্রাহকের টাকা গ্রাহকের শাখায় — মালিক, ১৯ অক্টোবর ২০২৬: *"kono vabei zate ek saka r party r ek sakay na zay"*।
             * ⓘ আগে আদায়কারীর উপরের শাখা: সব শাখা দেখা SR-এর ফোনে অন্য শাখার গ্রাহকের আদায় তাঁর নিজের শাখায় বসত — এখন
             * খাতার দরজা ([[PostingEngine]]) সেটা থামায়, তাই আদায় শুরু থেকেই গ্রাহকের শাখায়। গ্রাহকের শাখা না থাকলে আগের মতো।
             */
            $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : $this->customerBranch((int) $data['customer_id']);

            // ⛔ নম্বরও সেই শাখার সিরিজে, উপরের শাখার নয় (RCV-0143, ec, ১৯ অক্টোবর ২০২৬)
            $documentNo = $this->numbers->next('COL', $branchId);

            $collection = Collection::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $branchId,
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'customer_id' => $data['customer_id'],
                'account_id' => $this->resolveMoneyAccount(
                    $data['account_id'] ?? null,
                    (bool) ($data['allows_holding'] ?? false),
                )->id,
                'trx_date' => $trxDate->toDateString(),
                'amount' => $amount,
                'bank_charge' => $charge,
                'instrument' => $data['instrument'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'instrument_date' => $data['instrument_date'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            /*
             * ⭐ বিল বাছা না থাকলে সবচেয়ে পুরনো বিল আগে (FIFO) — টাকার পরিকল্পনা ৬ (মালিক, ৭ অক্টোবর ২০২৬;
             * [[AnUnpointedCollectionPaysTheOldestBillFirstTest]])। ⓘ আগে বিল না বাছা আদায় খালি বসত: পাওনা কমত, অথচ প্রতিটা বিল
             * "বাকি" দেখাত — তাগাদার তালিকা আর বিলের বয়স দুটোই ভুল। ⓘ বাছা থাকলে আগের মতোই; বাড়তি টাকা আগের মতো গ্রাহকের
             * খাতায় Cr। কেবল তৈরিতে — সম্পাদনায় খালি রাখা মানে ইচ্ছা করে খালি।
             */
            if ($lines === []) {
                $lines = $this->oldestFirst($collection);
            }

            $this->replaceLines($collection, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => Collection::drillSourceType(),
                    'source_id' => $collection->id,
                ]);

            return $collection->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Collection $collection, array $data, array $lines): Collection
    {
        $this->assertEditable($collection);
        $this->assertNoCheque($data['instrument'] ?? null);

        return DB::transaction(function () use ($collection, $data, $lines) {
            /*
             * ⛔ সারিতে তালা, অবস্থা তাজা — নিশ্চিত করার মতোই ([[confirm()]]; ১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️১৪)। ⓘ উপরের
             * "খসড়া কি না" হাতের কপি থেকে: এক ট্যাবে নিশ্চিত আর আরেক ট্যাবে সম্পাদনা একসাথে হলে সম্পাদনাটা সদ্য খাতায় ওঠা
             * আদায়ের অঙ্ক আর সারি বদলে দিত — খাতা এক কথা, আদায় আরেক।
             */
            $this->lockFresh($collection);
            $this->assertEditable($collection);
            $this->holdTheCustomersSlips((int) $collection->customer_id);

            $trxDate = Carbon::parse($data['trx_date'] ?? $collection->trx_date);

            /*
             * ⛔ সম্পাদনাতেও একই স্লিপের পাহারা, নিজেকে বাদ দিয়ে — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (বিক্রয় ৪;
             * [[AnEditedCollectionCannotReuseASlipTest]])। ⓘ পাহারা ছিল কেবল নতুন আদায়ে ([[create()]]); খসড়া খুলে আরেকটা আদায়ের
             * বিকাশ TrxID বসালে কিছুই আটকাত না, আর পাকা করলে গ্রাহকের পাওনা দুইবার কমত।
             */
            $this->slips->check(
                table: 'sal_collections',
                slipNo: $data['instrument_no'] ?? null,
                partyId: (int) $collection->customer_id,
                party: 'customer_id',
                message: __('sales::validation.slip_used_twice', [
                    'no' => trim((string) ($data['instrument_no'] ?? '')),
                ]),
                exceptId: (int) $collection->id,
            );

            /*
             * ⛔ নতুন বানানোর একই পাহারা ([[create()]]) — Sales লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬। ⚠️ আগে সম্পাদনায় অঙ্ক শূন্য
             * বা ব্যাংক চার্জের সমান-কম করা যেত; পাকা করার সময় টাকার খাতের ডেবিট (অঙ্ক − চার্জ) ঋণাত্মক হয়ে খাতার ইঞ্জিন ভাঙত।
             */
            $amount = $this->money($data['amount'] ?? $collection->amount);

            if (bccomp($amount, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('sales::validation.collection_must_be_positive'),
                ]);
            }

            if (bccomp((string) ($collection->bank_charge ?? '0'), $amount, 4) >= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('sales::validation.bank_charge_out_of_range', ['amount' => Money::format($amount)]),
                ]);
            }

            $collection->update([
                'account_id' => $this->resolveMoneyAccount(
                    $data['account_id'] ?? $collection->account_id,
                    (bool) ($data['allows_holding'] ?? false),
                )->id,
                'trx_date' => $trxDate->toDateString(),
                'amount' => $amount,
                'instrument' => $data['instrument'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'instrument_date' => $data['instrument_date'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($collection, $lines);

            return $collection->fresh(['lines']);
        });
    }

    /**
     * ⭐ টাকা নেওয়া — নকশা ১০০-এর আবেদন থেকে খসড়া (মালিকের নকশা, ২১ অক্টোবর ২০২৬; a5-র চুক্তি)।
     *
     * ⓘ পুরনো [[create()]]-এর সব পাহারা (চেক নয়, স্লিপ দুবার নয়, গ্রাহকের শাখা, টাকার খাত, শাখার সিরিজে নম্বর) — সাথে:
     *   · গ্রাহক যা কেটে রাখলেন: VDS (১১৩৮) আর AIT (১১৩৫) — দফা ৫;
     *   · কম পড়া অংশ ছাড় (৪১২০, মালিকের সই) বা রাউন্ডিং (৫৩০৫, সীমার ভিতরে) — দফা ৪;
     *   · খোলা ক্রেডিট (ক্রেডিট নোট, আগের অপ্রয়োগকৃত) বিলে বসানো, ডেবিট নোট শোধ — দফা ৩;
     *   · কোনো বিলে না বসা অংশ `unapplied`, গ্রাহকের নিজের খাতায় Cr — অগ্রিমের আলাদা খাত নয় (মালিকের নিয়ম);
     *   · একই খাতে একই রেফারেন্স দ্বিতীয়বার নয় — দফা ৭।
     * ⛔ সারির হিসাব আর খাতার লাইন — দুটোই এক জায়গা থেকে ([[plan()]], [[ledgerLines()]]), খাতার প্রিভিউও ঠিক এগুলোই ডাকে।
     */
    public function createFrom(CollectionInput $in): Collection
    {
        $this->assertNoCheque($in->method);

        return DB::transaction(function () use ($in) {
            $trxDate = Carbon::parse($in->trxDate);
            $year = $this->resolveFinancialYear($trxDate);

            $this->holdTheCustomersSlips($in->customerId);
            $this->slips->check(
                table: 'sal_collections',
                slipNo: $in->paperFields()['instrument_no'] ?? null,
                partyId: $in->customerId,
                party: 'customer_id',
                message: __('sales::validation.slip_used_twice', ['no' => trim((string) $in->reference)]),
            );

            $account = $this->resolveMoneyAccount($in->accountId);
            $this->assertReferenceFreshOnAccount($in, (int) $account->id);

            $branchId = $this->customerBranch($in->customerId);
            $this->assertMoneyInTheCustomersBranch($account, $branchId);

            // ⓘ নম্বর নেওয়ার আগে — আটকালে সিরিজে ফাঁক পড়ত
            $plan = $this->plan($in, $branchId);

            $documentNo = $this->numbers->next('COL', $branchId);

            $collection = Collection::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $branchId,
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'customer_id' => $in->customerId,
                'account_id' => $account->id,
                'trx_date' => $trxDate->toDateString(),
                'amount' => $in->amount,
                ...$in->paperFields(),
                ...$this->planFields($in, $plan),
                'narration' => $in->narration,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->writePlan($collection, $plan);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => Collection::drillSourceType(),
                    'source_id' => $collection->id,
                ]);

            return $collection->fresh(['lines', 'applications']);
        });
    }

    /** ⭐ খসড়া বদলানো — [[createFrom()]]-এর একই হিসাব; পাকা কাগজ বদলায় না (দফা ১২) */
    public function updateFrom(Collection $collection, CollectionInput $in): Collection
    {
        $this->assertEditable($collection);
        $this->assertNoCheque($in->method);

        return DB::transaction(function () use ($collection, $in) {
            $this->lockFresh($collection);
            $this->assertEditable($collection);

            // ⛔ গ্রাহক বদলায় না — নম্বর তাঁর শাখার সিরিজে, আর সারিগুলো তাঁর বিলের
            if ((int) $in->customerId !== (int) $collection->customer_id) {
                throw ValidationException::withMessages(['customer_id' => __('sales::collection_rules.customer_fixed')]);
            }

            $this->holdTheCustomersSlips((int) $collection->customer_id);
            $this->slips->check(
                table: 'sal_collections',
                slipNo: $in->paperFields()['instrument_no'] ?? null,
                partyId: (int) $collection->customer_id,
                party: 'customer_id',
                message: __('sales::validation.slip_used_twice', ['no' => trim((string) $in->reference)]),
                exceptId: (int) $collection->id,
            );

            $trxDate = Carbon::parse($in->trxDate);
            $account = $this->resolveMoneyAccount($in->accountId ?? $collection->account_id);
            $this->assertReferenceFreshOnAccount($in, (int) $account->id, (int) $collection->id);

            $branchId = $collection->branch_id === null ? null : (int) $collection->branch_id;
            $this->assertMoneyInTheCustomersBranch($account, $branchId);
            $plan = $this->plan($in, $branchId);

            $collection->update([
                'account_id' => $account->id,
                'trx_date' => $trxDate->toDateString(),
                'amount' => $in->amount,
                ...$in->paperFields(),
                ...$this->planFields($in, $plan),
                'narration' => $in->narration,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->writePlan($collection, $plan);

            return $collection->fresh(['lines', 'applications']);
        });
    }

    /**
     * ⭐ খাতার প্রিভিউ — পোস্টের আগে Dr/Cr, খাত আর শাখা (দফা ১০; SAP-এর "Simulate")। কিছুই লেখে না।
     *
     * ⛔ লাইনগুলো [[ledgerLines()]] থেকে — [[confirm()]] যেটা খাতায় পাঠায় ঠিক সেটাই, তাই প্রিভিউ আর খাতা আলাদা হতে পারে না।
     * ⓘ যে কারণে পোস্ট থামবে সেগুলো `stops`-এ, ছুঁড়ে না দিয়ে — পর্দা লিখে দেখায়।
     *
     * @return array{lines: list<array{account: string, branch: string, dr: string, cr: string}>, total_dr: string, total_cr: string,
     *     applied: list<array{kind: string, no: string, amount: string}>, unapplied: string,
     *     summary: array{received: string, vds: string, ait: string, difference: string, applied: string, unapplied: string, balanced: bool},
     *     needs_signature: bool, reference_free: bool, stops: list<string>}
     */
    public function preview(CollectionInput $in): array
    {
        $stops = [];
        $catch = function (callable $check) use (&$stops) {
            try {
                return $check();
            } catch (ValidationException $e) {
                foreach ($e->validator->errors()->all() as $message) {
                    $stops[] = (string) $message;
                }

                return null;
            }
        };

        $catch(fn () => $this->assertNoCheque($in->method));
        $catch(fn () => $this->periods->assertOpen($in->trxDate));
        $catch(fn () => MoneyMethod::assertReadyToPost($in->paperFields()));
        $account = $catch(fn () => $this->resolveMoneyAccount($in->accountId));

        $before = count($stops);
        if ($account !== null) {
            $catch(fn () => $this->assertReferenceFreshOnAccount($in, (int) $account->id));
        }
        $referenceFree = count($stops) === $before;

        $branchId = $this->customerBranch($in->customerId);
        if ($account !== null) {
            $catch(fn () => $this->assertMoneyInTheCustomersBranch($account, $branchId));
        }
        $plan = $catch(fn () => $this->plan($in, $branchId));

        $lines = [];
        $totalDr = '0.0000';
        $totalCr = '0.0000';

        if ($account !== null && $plan !== null) {
            $built = $catch(fn () => $this->ledgerLines([
                'account' => $account,
                'amount' => $in->amount,
                'charge' => $in->paperFields()['bank_charge'],
                'vds' => $in->vds,
                'ait' => $in->ait,
                'difference_kind' => $in->differenceKind(),
                'difference_amount' => $in->differenceAmount(),
                'customer_id' => $in->customerId,
                'no' => '',
            ]));

            $branch = $branchId === null ? '' : (string) (Branch::query()->whereKey($branchId)->first()?->name() ?? '');
            $accounts = Account::query()->whereKey(array_column($built ?? [], 'account_id'))->get()->keyBy('id'); // ⓘ কেবল নাম — খাত আগেই বাছা (resolveMoneyAccount postable, বাকি চার্টের স্থির পাতা)
            $customer = Customer::query()->withoutGlobalScopes()->whereKey($in->customerId)->first();

            foreach ($built ?? [] as $line) {
                $head = $accounts->get($line['account_id']);
                // ⓘ নকশার লেখা হুবহু — "1102 ডাচ্-বাংলা ব্যাংক" (কোডের পরে ফাঁকা, বিন্দু নয়), গ্রাহকের সারিতে " · নাম"
                $label = trim(($head?->code ?? '').' '.($head?->name() ?? ''));
                $dr = bcadd((string) ($line['debit'] ?? '0'), '0', 4);
                $cr = bcadd((string) ($line['credit'] ?? '0'), '0', 4);

                $lines[] = [
                    // ⓘ গ্রাহকের সারিতে তাঁর নামও — নকশার "1130 পাওনা · করিম স্টোর"
                    'account' => isset($line['party_id']) && $customer !== null ? $label.' · '.$customer->name() : $label,
                    'branch' => $branch,
                    'dr' => $dr,
                    'cr' => $cr,
                ];
                $totalDr = bcadd($totalDr, $dr, 4);
                $totalCr = bcadd($totalCr, $cr, 4);
            }
        }

        $applied = $plan['applied'] ?? '0.0000';
        $unapplied = $plan['unapplied'] ?? '0.0000';

        return [
            'lines' => $lines,
            'total_dr' => $totalDr,
            'total_cr' => $totalCr,
            'applied' => $plan['shown'] ?? [],
            'unapplied' => $unapplied,
            'summary' => [
                'received' => $in->amount,
                'vds' => $in->vds,
                'ait' => $in->ait,
                'difference' => $in->differenceAmount(),
                'applied' => $applied,
                'unapplied' => $unapplied,
                // ⓘ রসিদের হুবহু কথায় টাকা — পাওয়া টাকা (দফা ১৭; a4-এর ক্লায়েন্ট `summary.amount_words` পড়ে)
                // ⓘ যিনি চাইছেন তাঁর ভাষায় — ফোনে আর ওয়েবে দুই দরজাতেই app()-এর ভাষা মানুষের `users.locale` ([[ResolveCompanyContext]])
                'amount_words' => \App\Core\Support\AmountInWords::of($in->amount, app()->getLocale()),
                // ⓘ "মিলেছে" কেবল যখন হিসাব বসে আর খাতার দুই দিক সমান: পাওয়া + কাটা + ছাড় = প্রয়োগ + অপ্রয়োগকৃত
                'balanced' => $plan !== null && $lines !== [] && bccomp($totalDr, $totalCr, 4) === 0
                    && bccomp(bcadd($applied, $unapplied, 4), $plan['gross'], 4) === 0,
            ],
            // ⓘ পোস্টের একই নিয়ম — ছাড় সবসময়, রাউন্ডিং ৳০.৫০-এর বেশি হলে ([[differenceNeedsOwner()]])
            'needs_signature' => self::differenceNeedsOwner($in->differenceKind(), $in->differenceAmount()),
            'reference_free' => $referenceFree,
            'stops' => array_values(array_unique($stops)),
        ];
    }

    /**
     * ⭐ সংরক্ষিত আদায় থেকে আবেদন — খসড়ার পাতা আর ফোনের উত্তরে প্রিভিউ দেখাতে (a5-র প্রশ্ন ২)।
     */
    public function inputOf(Collection $collection): CollectionInput
    {
        $collection->loadMissing(['lines', 'applications']);

        $lines = [];
        foreach ($collection->lines as $line) {
            $lines[] = ['kind' => 'bill', 'id' => (int) $line->sales_invoice_id, 'amount' => bcadd((string) $line->amount, '0', 4)];
        }
        foreach ($collection->applications as $app) {
            if ($app->kind === CollectionApplication::DEBIT_NOTE) {
                $lines[] = ['kind' => 'debit_note', 'id' => (int) $app->source_id, 'amount' => bcadd((string) $app->amount, '0', 4)];

                continue;
            }

            // ⓘ ক্রেডিট সারিতে বিলের অংশটাও ফেরে — বিলের পুরো অঙ্ক = টাকার অংশ + ক্রেডিটের অংশ
            $lines[] = ['kind' => $app->kind, 'id' => (int) $app->source_id, 'amount' => bcadd((string) $app->amount, '0', 4)];
            $lines[] = ['kind' => 'bill', 'id' => (int) $app->target_invoice_id, 'amount' => bcadd((string) $app->amount, '0', 4)];
        }

        $kind = $collection->difference_kind;

        return new CollectionInput(
            customerId: (int) $collection->customer_id,
            accountId: $collection->account_id === null ? null : (int) $collection->account_id,
            trxDate: (string) $collection->trx_date?->toDateString(),
            amount: bcadd((string) $collection->amount, '0', 4),
            method: $collection->instrument,
            reference: $collection->instrument === 'deposit' ? $collection->deposit_slip_no : $collection->instrument_no,
            valueDate: $collection->value_date?->toDateString(),
            bankCharge: bcadd((string) ($collection->bank_charge ?? '0'), '0', 4),
            vds: bcadd((string) ($collection->vds ?? '0'), '0', 4),
            ait: bcadd((string) ($collection->ait ?? '0'), '0', 4),
            difference: filled($kind) && bccomp((string) ($collection->difference_amount ?? '0'), '0', 4) > 0
                ? ['kind' => (string) $kind, 'amount' => bcadd((string) $collection->difference_amount, '0', 4)] : null,
            lines: $this->mergeBillLines($lines),
            narration: $collection->narration,
            transferModeId: $collection->transfer_mode_id === null ? null : (int) $collection->transfer_mode_id,
            depositSlipNo: $collection->deposit_slip_no,
            fromBank: $collection->from_bank,
            instrumentDate: $collection->instrument_date?->toDateString(),
        );
    }

    /**
     * ⭐ সারির হিসাব — কোন বিলে টাকার কত, কোন ক্রেডিট কোন বিলে, ডেবিট নোটে কত, আর কত অপ্রয়োগকৃত (দফা ৩, ৪, ৫)।
     *
     * ⓘ ভারসাম্য: পাওয়া টাকা + VDS + AIT + ছাড়/রাউন্ডিং = গ্রাহকের পাওনা থেকে যা কমে (G) = প্রয়োগ + অপ্রয়োগকৃত।
     *   ব্যাংক চার্জ টাকার ভিতরেই (গ্রাহক পুরো টাকা দিয়েছেন, ব্যাংক রেখেছে চার্জ — দফা ৯)।
     * ⓘ ক্রেডিট (নোট বা আগের অপ্রয়োগকৃত) বিলের পাশে কাটাকাটি — নতুন টাকা নয়, খাতায় নতুন দাখিলাও নয়; বিলের যে অংশ ক্রেডিট
     *   ঢাকল, বাকিটা টাকায়।
     * ⛔ কিছু লেখে না — প্রিভিউও এটা ডাকে।
     *
     * @return array{gross: string, applied: string, unapplied: string, difference_account_id: int|null,
     *     money: list<array{sales_invoice_id: int, amount: string}>,
     *     apps: list<array{kind: string, source_id: int, target_invoice_id: int|null, amount: string}>,
     *     shown: list<array{kind: string, no: string, amount: string}>}
     */
    private function plan(CollectionInput $in, ?int $branchId): array
    {
        if (bccomp($in->amount, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('sales::validation.collection_must_be_positive')]);
        }

        $charge = $in->paperFields()['bank_charge'];

        if (bccomp($charge, '0', 4) < 0 || bccomp($charge, $in->amount, 4) >= 0) {
            throw ValidationException::withMessages([
                'bank_charge' => __('sales::validation.bank_charge_out_of_range', ['amount' => Money::format($in->amount)]),
            ]);
        }

        $differenceAccount = null;

        if ($in->difference !== null) {
            if ($in->differenceKind() === 'rounding') {
                /*
                 * ⛔ রাউন্ডিং কেবল সীমার ভিতরে — ডিফল্ট ৳১০, কোম্পানির সেটিং `sales.collection.rounding_limit`; উপরে গেলে
                 * বাংলায় ফেরত (তখন সেটা ছাড়, "ছাড়" বেছে পাঠাতে হয়)। ⓘ সীমার ভিতরে ৳০.৫০-এর বেশি হলে মালিকের সই
                 * ([[differenceNeedsOwner()]], পোস্টের সময়)।
                 */
                $limit = $this->roundingLimit();

                if (bccomp($in->differenceAmount(), $limit, 4) > 0) {
                    throw ValidationException::withMessages([
                        'difference' => __('sales::collection_rules.rounding_over_limit', [
                            'amount' => Money::format($in->differenceAmount()),
                            'limit' => Money::format($limit),
                        ]),
                    ]);
                }
            }

            $differenceAccount = (int) $this->account($in->differenceKind() === 'discount'
                ? StandardChart::COLLECTION_DISCOUNT
                : StandardChart::ROUNDING_DIFFERENCE)->id;
        }

        $gross = $in->grossToCustomer();
        $wanted = $in->lines;

        // ⭐ সারির ঘরটাই না এলে পুরনো বিল আগে, গ্রাহকের শাখার — পাওনা থেকে যা কমে (G) তত পর্যন্ত (টাকার পরিকল্পনা ৬); `lines: []` মানে কোনো বিলে নয়
        if ($wanted === [] && $in->autoLines) {
            $wanted = $this->oldestBillsFor($in->customerId, $branchId, $gross);
        }

        $bills = [];     // [invoice, amount]
        $debits = [];    // [note, amount]
        $credits = [];   // [kind, source, no, amount]
        $perBill = [];

        foreach ($wanted as $line) {
            $amount = bcadd((string) $line['amount'], '0', 4);

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            switch ($line['kind']) {
                case 'bill':
                    $invoice = SalesInvoice::acrossBranches()->whereKey((int) $line['id'])->first();

                    if ($invoice === null) {
                        throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_invoice')]);
                    }
                    if ((int) $invoice->customer_id !== $in->customerId) {
                        throw ValidationException::withMessages(['lines' => __('sales::validation.invoice_other_customer')]);
                    }
                    if ($invoice->status !== DocumentStatus::CONFIRMED) {
                        throw ValidationException::withMessages([
                            'lines' => __('sales::validation.invoice_not_confirmed', ['no' => $invoice->document_no]),
                        ]);
                    }

                    $onThisBill = bcadd($perBill[$invoice->id] ?? '0', $amount, 4);
                    $due = $invoice->dueAmount();

                    if (bccomp($onThisBill, $due, 4) > 0) {
                        throw ValidationException::withMessages([
                            'lines' => __('sales::validation.over_allocated', ['no' => $invoice->document_no, 'due' => Money::format($due)]),
                        ]);
                    }

                    $perBill[$invoice->id] = $onThisBill;
                    $bills[] = [$invoice, $amount];
                    break;

                case 'debit_note':
                case 'credit_note':
                    $note = Note::acrossBranches()->whereKey((int) $line['id'])
                        ->where('party_type', 'customer')->where('party_id', $in->customerId)
                        ->where('status', DocumentStatus::CONFIRMED)->first();

                    if ($note === null || $note->isCredit() !== ($line['kind'] === 'credit_note')) {
                        throw ValidationException::withMessages(['lines' => __('sales::collection_rules.unknown_item')]);
                    }

                    $open = $this->openItems->openOfNote($note);

                    if (bccomp($amount, $open, 4) > 0) {
                        throw ValidationException::withMessages([
                            'lines' => __('sales::collection_rules.over_open', ['no' => $note->document_no, 'open' => Money::format($open)]),
                        ]);
                    }

                    if ($line['kind'] === 'debit_note') {
                        $debits[] = [$note, $amount];
                    } else {
                        $credits[] = [CollectionApplication::CREDIT_NOTE, (int) $note->id, (string) $note->document_no, $amount];
                    }
                    break;

                case 'credit':
                    $prior = Collection::acrossBranches()->whereKey((int) $line['id'])->where('customer_id', $in->customerId)->first();

                    if ($prior === null) {
                        throw ValidationException::withMessages(['lines' => __('sales::collection_rules.unknown_item')]);
                    }

                    $open = $this->openItems->openOfCredit($prior);

                    if (bccomp($amount, $open, 4) > 0) {
                        throw ValidationException::withMessages([
                            'lines' => __('sales::collection_rules.over_open', ['no' => $prior->document_no, 'open' => Money::format($open)]),
                        ]);
                    }

                    $credits[] = [CollectionApplication::CREDIT, (int) $prior->id, (string) $prior->document_no, $amount];
                    break;

                default:
                    throw ValidationException::withMessages(['lines' => __('sales::collection_rules.unknown_kind', ['kind' => (string) $line['kind']])]);
            }
        }

        $billTotal = array_reduce($bills, fn (string $s, array $b) => bcadd($s, $b[1], 4), '0.0000');
        $creditTotal = array_reduce($credits, fn (string $s, array $c) => bcadd($s, $c[3], 4), '0.0000');

        // ⛔ ক্রেডিট কেবল এই কাগজের বিলে বসে — বিলের চেয়ে বেশি ক্রেডিট কোথাও বসত না, অথচ "প্রয়োগ" দেখাত
        if (bccomp($creditTotal, $billTotal, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::collection_rules.credit_over_bills', ['credit' => Money::format($creditTotal), 'bills' => Money::format($billTotal)]),
            ]);
        }

        $money = [];
        $apps = [];
        $shown = [];
        $c = 0;
        $creditLeft = $credits === [] ? '0' : $credits[0][3];

        foreach ($bills as [$invoice, $amount]) {
            $billLeft = $amount;

            // ⓘ ক্রেডিট আগে বিল ঢাকে (পুরনো ক্রেডিট পুরনো বিলে), বাকিটা টাকায়
            while ($c < count($credits) && bccomp($billLeft, '0', 4) > 0) {
                $take = bccomp($creditLeft, $billLeft, 4) < 0 ? $creditLeft : $billLeft;

                if (bccomp($take, '0', 4) > 0) {
                    $apps[] = ['kind' => $credits[$c][0], 'source_id' => $credits[$c][1], 'target_invoice_id' => (int) $invoice->id, 'amount' => $take];
                    $billLeft = bcsub($billLeft, $take, 4);
                    $creditLeft = bcsub($creditLeft, $take, 4);
                }

                if (bccomp($creditLeft, '0', 4) <= 0) {
                    $c++;
                    $creditLeft = $c < count($credits) ? $credits[$c][3] : '0';
                }
            }

            if (bccomp($billLeft, '0', 4) > 0) {
                $money[] = ['sales_invoice_id' => (int) $invoice->id, 'amount' => $billLeft];
            }

            $shown[] = ['kind' => 'bill', 'no' => (string) $invoice->document_no, 'amount' => $amount];
        }

        foreach ($debits as [$note, $amount]) {
            $apps[] = ['kind' => CollectionApplication::DEBIT_NOTE, 'source_id' => (int) $note->id, 'target_invoice_id' => null, 'amount' => $amount];
            $shown[] = ['kind' => 'debit_note', 'no' => (string) $note->document_no, 'amount' => $amount];
        }

        foreach ($credits as [$kind, , $no, $amount]) {
            $shown[] = ['kind' => $kind, 'no' => $no, 'amount' => '-'.$amount];
        }

        // ⓘ টাকা থেকে যা গেল = বিলের টাকার অংশ + ডেবিট নোট; ক্রেডিটের অংশ টাকা নয়
        $applied = '0.0000';
        foreach ($money as $m) {
            $applied = bcadd($applied, $m['amount'], 4);
        }
        foreach ($debits as [, $amount]) {
            $applied = bcadd($applied, $amount, 4);
        }

        if (bccomp($applied, $gross, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.allocation_over_amount', [
                    'allocated' => Money::format($applied),
                    'amount' => Money::format($gross),
                ]),
            ]);
        }

        return [
            'gross' => $gross,
            'applied' => $applied,
            'unapplied' => bcsub($gross, $applied, 4),
            'difference_account_id' => $differenceAccount,
            'money' => $money,
            'apps' => $apps,
            'shown' => $shown,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planFields(CollectionInput $in, array $plan): array
    {
        return [
            'vds' => $in->vds,
            'ait' => $in->ait,
            'difference_kind' => $in->differenceKind(),
            'difference_amount' => $in->differenceAmount(),
            'difference_account_id' => $plan['difference_account_id'],
            'unapplied' => $plan['unapplied'],
        ];
    }

    /** @param  array<string, mixed>  $plan */
    private function writePlan(Collection $collection, array $plan): void
    {
        $collection->lines()->delete();
        $collection->applications()->delete();

        $lineNo = 0;
        foreach ($plan['money'] as $line) {
            CollectionLine::create([
                'collection_id' => $collection->id,
                'sales_invoice_id' => $line['sales_invoice_id'],
                'amount' => $line['amount'],
                'line_no' => ++$lineNo,
            ]);
        }

        foreach ($plan['apps'] as $app) {
            CollectionApplication::create(['collection_id' => $collection->id, ...$app]);
        }
    }

    /**
     * @return list<array{kind: string, id: int, amount: string}>
     */
    private function oldestBillsFor(int $customerId, ?int $branchId, string $left): array
    {
        $lines = [];

        $bills = SalesInvoice::acrossBranches()
            ->where('sal_invoices.company_id', CompanyContext::id())
            ->where('sal_invoices.customer_id', $customerId)
            ->where('sal_invoices.status', DocumentStatus::CONFIRMED)
            ->when($branchId !== null, fn ($q) => $q->where('sal_invoices.branch_id', $branchId))
            ->withCollected()
            ->orderBy('sal_invoices.trx_date')->orderBy('sal_invoices.id')
            ->get();

        foreach ($bills as $bill) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            $due = $bill->dueAmount();
            $share = bccomp($due, $left, 4) < 0 ? $due : $left;

            if (bccomp($share, '0', 4) > 0) {
                $lines[] = ['kind' => 'bill', 'id' => (int) $bill->id, 'amount' => $share];
                $left = bcsub($left, $share, 4);
            }
        }

        return $lines;
    }

    /**
     * একই বিলের একাধিক সারি এক সারিতে — [[inputOf()]]-এ টাকার অংশ আর ক্রেডিটের অংশ আলাদা জমা থাকে।
     *
     * @param  list<array{kind: string, id: int, amount: string}>  $lines
     * @return list<array{kind: string, id: int, amount: string}>
     */
    private function mergeBillLines(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            $key = $line['kind'].':'.$line['id'];
            $out[$key] = isset($out[$key])
                ? ['kind' => $line['kind'], 'id' => $line['id'], 'amount' => bcadd($out[$key]['amount'], $line['amount'], 4)]
                : $line;
        }

        return array_values($out);
    }

    /**
     * ⛔ একই খাতে একই রেফারেন্স দ্বিতীয়বার নয় — দফা ৭ (মালিকের নকশা ১০০; D365-এর duplicate check)।
     *
     * ⓘ গ্রাহক যে-ই হোন: একটা বিকাশ TrxID বা ব্যাংকের রেফারেন্স একটা খাতে একবারই টাকা আনে। বাতিল কাগজ বাদ; বড়-ছোট হাতের
     * অক্ষর আর দুই পাশের ফাঁকা এক ধরা হয়। ⓘ উত্তরে আগের কাগজের নম্বর আর ঠিকানা — কোনটা আসল, মানুষ এক টিপে দেখেন।
     */
    private function assertReferenceFreshOnAccount(CollectionInput $in, int $accountId, ?int $exceptId = null): void
    {
        $reference = trim((string) $in->referenceValue());

        if ($reference === '') {
            return;
        }

        $column = $in->referenceColumn();

        $earlier = Collection::acrossBranches()
            ->where('account_id', $accountId)
            ->whereRaw('LOWER(TRIM('.$column.')) = ?', [mb_strtolower($reference)])
            ->where('status', '!=', DocumentStatus::CANCELLED)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();

        if ($earlier !== null) {
            throw ValidationException::withMessages([
                $column => __('sales::collection_rules.reference_used', [
                    'reference' => $reference,
                    'no' => $earlier->document_no,
                    'url' => route('sales.collection.show', $earlier->id),
                ]),
            ]);
        }
    }

    /**
     * ⛔ অন্য শাখার টিলে আদায় নয় — দফা ২২ এখনো বানানো হয়নি (fe-র D4, ২১ অক্টোবর ২০২৬: মালিকের সিদ্ধান্তের অপেক্ষা)।
     *
     * ⓘ আদায় বসে গ্রাহকের শাখায় (দফা ১৪)। টাকার খাতটা অন্য শাখার টিল হলে দুই শাখার খাতা মেলাতে আন্তঃশাখার হিসাব লাগত — সেটা
     * চালু না হওয়া পর্যন্ত বাংলায় কারণসহ ফেরত। ⓘ ব্যাংক আর MFS খাত কোম্পানির, কোনো শাখার নয়; শাখাহীন টিলও সবার।
     * ⛔ শাখাহীন আদায়ও (গ্রাহক শাখাহীন, আর যিনি নিচ্ছেন তাঁর কাজের শাখাও নেই — `users.current_branch_id` null) শাখার টিলে নয় — আগে `$branchId === null` হলে নিয়মটাই বাদ পড়ত, আর
     * আদায় শাখাহীন খাতায় বসে টাকা ঢুকত এক শাখার টিলে: সেই শাখার খাতায় টিলের টাকা দেখা যেত না (Mac, ২২ অক্টোবর ২০২৬, দফা ২২)।
     */
    private function assertMoneyInTheCustomersBranch(Account $account, ?int $branchId): void
    {
        $tillBranch = \App\Modules\Accounts\Models\CashTill::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->where('account_id', $account->id)->whereNotNull('branch_id')->value('branch_id');

        if ($tillBranch !== null && (int) $tillBranch !== $branchId) {
            throw ValidationException::withMessages(['account_id' => __('sales::collection_rules.other_branch_account')]);
        }
    }

    private function roundingLimit(): string
    {
        return Settlement::limit($this->settings);
    }

    /** গ্রাহকের শাখা — আদায় সেখানেই বসে (দফা ১৪); গ্রাহকের শাখা না থাকলে উপরের বাছা শাখা */
    private function customerBranch(int $customerId): ?int
    {
        $branchId = app(\App\Core\Services\PartyRegistry::class)->branchesOf([['customer', $customerId]])['customer:'.$customerId] ?? null;
        $branchId ??= CompanyContext::branchId();

        return $branchId === null ? null : (int) $branchId;
    }

    /**
     * ⭐ খাতার লাইন — একটাই জায়গা, পোস্ট ([[confirm()]]) আর প্রিভিউ ([[preview()]]) দুজনেই এটা ডাকে (দফা ১০)।
     *
     *     Dr  টাকার খাত          পাওয়া টাকা − ব্যাংক চার্জ
     *     Dr  ব্যাংক/MFS চার্জ    চার্জ (cb-র লাইন, অপরিবর্তিত)
     *     Dr  ১১৩৮ উৎসে কাটা ভ্যাট  গ্রাহক যা VDS কেটে রাখলেন
     *     Dr  ১১৩৫ অগ্রিম আয়কর     গ্রাহক যা AIT কেটে রাখলেন
     *     Dr  ৪১২০ আদায়ে ছাড়  বা  ৫৩০৫ রাউন্ডিং
     *     Cr  ১১১০ গ্রাহক          সবগুলোর যোগ — অপ্রয়োগকৃত অংশও এখানেই, গ্রাহকের নিজের খাতায় (অগ্রিমের আলাদা খাত নয়)
     *
     * @param  array{account: Account, amount: string, charge: string, vds: string, ait: string, difference_kind: string|null,
     *     difference_amount: string, customer_id: int, no: string}  $f
     * @return list<array<string, mixed>>
     */
    private function ledgerLines(array $f): array
    {
        $charge = bcadd($f['charge'], '0', 4);
        $vds = bcadd($f['vds'], '0', 4);
        $ait = bcadd($f['ait'], '0', 4);
        $difference = bcadd($f['difference_amount'], '0', 4);
        $gross = bcadd(bcadd(bcadd($f['amount'], $vds, 4), $ait, 4), $difference, 4);

        $lines = [[
            'account_id' => $f['account']->id,
            'debit' => bcsub($f['amount'], $charge, 4),
            'narration' => __('sales::message.money_in', ['no' => $f['no']]),
        ]];

        if (bccomp($charge, '0', 4) > 0) {
            /*
             * ⛔ MFS-এর চার্জ MFS-এর খাতে (৫২১১), ব্যাংকের নয় — নকশা #৯ (fe, ২১ অক্টোবর ২০২৬)। আগে সব চার্জ ৫২১০-এ যেত,
             * তাই বিকাশের খরচ "ব্যাংক চার্জ"-এ বসত আর দুই খরচের রিপোর্ট ([[BankCharges]]) ভুল ভাগ দেখাত। হিসাবের
             * ভাউচারের নিয়মটাই ([[VoucherService::chargeAccountFor()]]); পুরনো পোস্ট হওয়া সারি বদলায় না।
             */
            $lines[] = [
                'account_id' => $this->account($f['account']->isMfs() === true
                    ? StandardChart::MFS_CHARGES
                    : StandardChart::BANK_CHARGES)->id,
                'debit' => $charge,
                'narration' => __('sales::message.bank_charge_on', ['no' => $f['no']]),
            ];
        }

        // ⭐ দফা ৫ — গ্রাহক কেটে রাখলেন: VDS সনদের অপেক্ষায় ১১৩৮-এ, AIT অগ্রিম আয়কর ১১৩৫-এ (fe-র D2)
        if (bccomp($vds, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::VDS_RECEIVABLE)->id,
                'debit' => $vds,
                'narration' => __('sales::collection_rules.vds_on', ['no' => $f['no']]),
            ];
        }

        if (bccomp($ait, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::ADVANCE_INCOME_TAX)->id,
                'debit' => $ait,
                'narration' => __('sales::collection_rules.ait_on', ['no' => $f['no']]),
            ];
        }

        // ⭐ দফা ৪ — ছাড় বিক্রয়ের বিপরীতে (৪১২০, IFRS 15), রাউন্ডিং অন্য খরচে (৫৩০৫)
        if (bccomp($difference, '0', 4) > 0) {
            $discount = $f['difference_kind'] === 'discount';
            $lines[] = [
                'account_id' => $this->account($discount ? StandardChart::COLLECTION_DISCOUNT : StandardChart::ROUNDING_DIFFERENCE)->id,
                'debit' => $difference,
                'narration' => __($discount ? 'sales::collection_rules.discount_on' : 'sales::collection_rules.rounding_on', ['no' => $f['no']]),
            ];
        }

        $lines[] = [
            'account_id' => $this->account(StandardChart::RECEIVABLE)->id,
            'credit' => $gross,
            'party_type' => 'customer',
            'party_id' => $f['customer_id'],
            'narration' => __('sales::message.against_receivable', ['no' => $f['no']]),
        ];

        return $lines;
    }

    /**
     * ⛔ আদায়ের ছাড়ে মালিকের সই — মালিকের নিয়ম (১ অক্টোবর ২০২৬: *"kono char maliker onumoti chara dite parbe na"*), নকশা দফা ৪।
     *
     * ⓘ বিলের ছাড়ের হুবহু পথ ([[SalesInvoiceService::assertDiscountApproved()]]): `sales.discount` ছক, একটাই ধাপ — মালিক
     * ([[OwnerSignsDiscounts]]); সই সেই অঙ্ক আর সেই কাগজের ছাপের ([[Approval::stillCovers()]]); ছক বন্ধ বা নেই মানে ছাড় যায় না
     * (fail-closed — অনুমোদন বন্ধ কোম্পানিতেও, বিলের মতোই)। যিনি বানালেন তিনি নিজে সই দিতে পারেন না ([[ApprovalEngine::canDecide()]])।
     * ⭐ রাউন্ডিংও, ৳০.৫০-এর বেশি হলে — মালিকের ১–২ অক্টোবরের নিয়ম ([[differenceNeedsOwner()]]; fe, ২২ অক্টোবর ২০২৬: D3-এর
     * "রাউন্ডিংয়ে সই নেই" fe-র ভুল ছিল)। একই পথ, একই ছক, ঠিক সেই অঙ্কের সই; ছক বন্ধ বা নেই মানে ফেরত।
     */
    private function assertDiscountSigned(Collection $collection): void
    {
        $amount = bcadd((string) ($collection->difference_amount ?? '0'), '0', 4);
        $rounding = $collection->difference_kind === 'rounding';

        if (! self::differenceNeedsOwner($collection->difference_kind, $amount)) {
            return;
        }

        $hash = $this->discountFingerprint($collection);
        $decided = $this->engine->latestFor($collection, 'discount');

        if ($decided?->status === Approval::APPROVED && $decided->stillCovers($amount, $hash)) {
            return;
        }

        if ($decided?->status === Approval::PENDING && $decided->state_hash !== null && ! hash_equals((string) $decided->state_hash, $hash)) {
            $this->engine->withdrawStale($decided);
        }

        if ($decided?->status === Approval::REJECTED && $decided->covers($amount)) {
            throw ValidationException::withMessages(['difference' => __('sales::collection_rules.discount_rejected')]);
        }

        $approval = $this->engine->request(
            document: $collection,
            module: 'sales',
            action: 'discount',
            amount: $amount,
            stateHash: $hash,
            matchOn: [],
            reason: $collection->narration,
        );

        if ($approval === null) {
            throw ValidationException::withMessages(['difference' => $rounding
                ? __('sales::collection_rules.rounding_no_signer', ['amount' => Money::format($amount), 'free' => Money::format(self::ROUNDING_UNSIGNED)])
                : __('sales::validation.discount_no_signer')]);
        }

        throw HeldForApproval::on($collection, 'difference', $rounding
            ? __('sales::collection_rules.rounding_awaiting', ['amount' => Money::format($amount), 'free' => Money::format(self::ROUNDING_UNSIGNED)])
            : __('sales::collection_rules.discount_awaiting'));
    }

    /**
     * ⭐ পার্থক্যে মালিকের সই লাগবে কি না — ছাড় হলে সবসময়; রাউন্ডিং হলে কেবল ৳০.৫০-এর বেশি হলে (মালিক, ১ অক্টোবর ২০২৬:
     * *"রাউন্ডিং 0.5 mane 50 poisa porzonto accept"* — বিলের একই নিয়ম, [[SalesInvoiceService::discountAwaitingSignature()]])।
     * ⓘ পোস্ট ([[assertDiscountSigned()]]) আর প্রিভিউ (`needs_signature`) দুজনেই এটা ডাকে — দুই জায়গা আলাদা কথা বলতে পারে না।
     * ⓘ সীমার (`sales.collection.rounding_limit`, ডিফল্ট ৳১০) উপরের রাউন্ডিং এখানে পৌঁছায় না — আগেই ফেরত ([[plan()]])।
     */
    public static function differenceNeedsOwner(?string $kind, string $amount): bool
    {
        // ⓘ নিয়ম Core-এ, পরিশোধের সাথে ভাগ করা — দুই দিক আলাদা কথা বলতে পারে না ([[Settlement::differenceNeedsOwner()]])
        return Settlement::differenceNeedsOwner($kind, $amount);
    }

    /** ছাড়ের সইয়ের ছাপ — গ্রাহক, খাত, টাকা, কাটা, ছাড় আর প্রতিটা প্রয়োগ; বিবরণ বাদ */
    public function discountFingerprint(Collection $collection): string
    {
        $collection->loadMissing(['lines', 'applications']);

        return hash('sha256', json_encode([
            (int) $collection->customer_id,
            (int) $collection->account_id,
            bcadd((string) $collection->amount, '0', 4),
            bcadd((string) ($collection->vds ?? '0'), '0', 4),
            bcadd((string) ($collection->ait ?? '0'), '0', 4),
            (string) $collection->difference_kind,
            bcadd((string) ($collection->difference_amount ?? '0'), '0', 4),
            $collection->lines->map(fn ($l) => [(int) $l->sales_invoice_id, bcadd((string) $l->amount, '0', 4)])->values()->all(),
            $collection->applications->map(fn ($a) => [$a->kind, (int) $a->source_id, (int) $a->target_invoice_id, bcadd((string) $a->amount, '0', 4)])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function confirm(Collection $collection): Collection
    {
        if ($collection->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_confirms', ['no' => $collection->document_no]),
            ]);
        }

        // ⚠️ নিয়মের আগে লেখা খসড়াও — টাকা খাতায় ওঠে ঠিক এই মুহূর্তে
        $this->assertNoCheque($collection->instrument);

        $this->assertStillFits($collection);

        /*
         * ⭐ অনুমোদন — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ কেন আদায়েও সই লাগতে পারে ──────────────────────────────
         * মাঠকর্মী গ্রাহকের কাছ থেকে টাকা তোলেন, আর খাতায় বসান নিজে।
         * ⚠️ কম বসালে পার্থক্যটা গ্রাহকের খতিয়ানে থেকে যায়, আর গ্রাহক
         * অভিযোগ না করা পর্যন্ত কেউ জানে না।
         *
         * ⓘ বড় অঙ্কে সই চাইলে সেটা ধরা পড়ে সাথে সাথেই। ⚠️ ছোট আদায়ে
         * সই চাওয়ার মানে নেই — তাই অঙ্কটা পাঠানো হয়, আর সীমাটা
         * কোম্পানি নিজে বসায়।
         */
        $this->approvals->assertClear(
            document: $collection,
            module: 'sales',
            action: 'collection',
            field: 'status',
            amount: (string) $collection->amount,
            reason: $collection->narration,
        );

        // ⭐ টাকা নেওয়ার নকশা ১০০ — পদ্ধতির রেফারেন্স, রাউন্ডিংয়ের সীমা, স্লিপ বাধ্যতামূলক হলে স্লিপ, আর ছাড়ে মালিকের সই
        $this->assertReadyForTheBooks($collection);
        $this->assertDiscountSigned($collection);

        return DB::transaction(function () use ($collection) {
            /*
             * ⛔ আদায়ের সারিতেও তালা, অবস্থা তাজা — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬
             * ([[ACollectionWasConfirmedOrCancelledTwiceTest]])। ⓘ উপরের "খসড়া কি না" হাতের কপি থেকে: দুই ট্যাব থেকে
             * একই আদায় নিশ্চিত হলে দ্বিতীয়টাও খাতায় বসতে যেত।
             */
            $this->lockFresh($collection);

            if ($collection->status !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.only_draft_confirms', ['no' => $collection->document_no]),
                ]);
            }

            /*
             * ⛔ তালা দিয়ে আবার — ২৯ সেপ্টেম্বর ২০২৬ (অডিটে প্রমাণিত)। ⓘ উপরের যাচাই
             * সইয়ের আগের; খসড়া থাকতে বিল বাতিল হলে বা অন্য আদায় বসলে সেই ছবি
             * পুরনো। বিলের সারিতে তালা দিয়ে অবস্থা আর বাকি আবার দেখা হয়, খাতায়
             * বসানোর ঠিক আগে।
             */
            $this->assertStillFits($collection, lock: true);
            $this->assertCreditsStillOpen($collection);

            /*
             * ⭐ খাতার লাইন একটাই জায়গা থেকে ([[ledgerLines()]]) — প্রিভিউ যা দেখায় ঠিক তা-ই (দফা ১০)। চার্জ থাকলে টাকার খাতে কম
             * আসে, তফাতটা চার্জের খাতে; VDS, AIT, ছাড় বা রাউন্ডিং নিজের খাতে; গ্রাহকের পাওনা কমে সবগুলোর যোগে।
             */
            $this->posting->post(
                sourceType: Collection::drillSourceType(),
                sourceId: $collection->id,
                trxDate: $collection->trx_date,
                lines: $this->ledgerLines([
                    'account' => $collection->account ?? Account::query()->findOrFail($collection->account_id),
                    'amount' => bcadd((string) $collection->amount, '0', 4),
                    'charge' => bcadd((string) ($collection->bank_charge ?? '0'), '0', 4),
                    'vds' => bcadd((string) ($collection->vds ?? '0'), '0', 4),
                    'ait' => bcadd((string) ($collection->ait ?? '0'), '0', 4),
                    'difference_kind' => $collection->difference_kind,
                    'difference_amount' => bcadd((string) ($collection->difference_amount ?? '0'), '0', 4),
                    'customer_id' => (int) $collection->customer_id,
                    'no' => (string) $collection->document_no,
                ]),
                documentNo: $collection->document_no,
                branchId: $collection->branch_id,
            );

            $collection->update(['status' => DocumentStatus::CONFIRMED]);

            // ⓘ টাকা এল — টাকার জন্য আটকে থাকা DO আবার যাচাই, লেনদেন পাকা হওয়ার পরে ([[CollectionConfirmed]])
            DB::afterCommit(fn () => event(CollectionConfirmed::from($collection->fresh())));

            return $collection->fresh(['lines']);
        });
    }

    public function cancel(Collection $collection, string $reason, Carbon|string|null $onDate = null): Collection
    {
        if ($collection->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $collection->document_no]),
            ]);
        }

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($collection, $reason, $date) {
            /*
             * ⛔ তালা দিয়ে অবস্থা তাজা — ২ অক্টোবর ২০২৬ ([[ACollectionWasConfirmedOrCancelledTwiceTest]])। ⓘ পুরনো
             * পাতা থেকে দ্বিতীয় বাতিল উপরের যাচাই পার হত আর উল্টো দাখিলা আবার বসাতে যেত; আর খসড়া অবস্থায় খোলা পাতা
             * থেকে বাতিল হলে মাঝে নিশ্চিত হওয়া দাখিলাটা উল্টাতই না।
             */
            $this->lockFresh($collection);

            if ($collection->status === DocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => __('sales::validation.already_cancelled', ['no' => $collection->document_no]),
                ]);
            }

            if ($collection->status === DocumentStatus::CONFIRMED) {
                $this->posting->reverse(
                    sourceType: Collection::drillSourceType(),
                    sourceId: $collection->id,
                    reversalDate: $date,
                    reason: $reason,
                );
            }

            $collection->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $collection->fresh(['lines']);
        });
    }

    /**
     * আদায়ের কাগজে নেওয়া একটা চেক ফেরত এসেছে।
     *
     * ── কেন এটা এখানে, ChequeService-এ নয় ──────────────────────────────
     * টাকাটা পোস্ট করেছিল আদায়ের কাগজ (Dr ১১০৪ / Cr গ্রাহক + CollectionLine),
     * চেক নিজে নয়। তাই ফেরানোও সেখান থেকেই — কাগজটা বাতিল হলে দাখিলা উল্টে
     * যায় (গ্রাহক আবার দেনাদার), আর তার CollectionLine আর posted() না থাকায়
     * বিলের বকেয়া **নিজে থেকেই** ফিরে আসে ([[SalesInvoice::collectedAmount()]]
     * কেবল posted আদায় গোনে)। উদ্বৃত্ত থাকলে সেই অগ্রিমও একসাথে ফেরে।
     *
     * ChequeService নিচের স্তর (Accounts), সে Sales-এর আদায় জানে না — তাই
     * এই জোড়া লাগানোর কাজটা এখানে, আর চেকের সারিতে কেবল অবস্থাটা বসে।
     */
    public function bounceReceivedCheque(Cheque $cheque, string $reason): Cheque
    {
        if (! $cheque->postedByCollection()) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.cheque_not_from_receipt'),
            ]);
        }

        return DB::transaction(function () use ($cheque, $reason) {
            $collection = Collection::query()->findOrFail($cheque->collection_id);
            $this->cancel($collection, $reason);

            return $this->cheques->markBounced($cheque, $reason);
        });
    }

    /**
     * নিশ্চিতের দরজা কোন কারণে থামাবে — কিছু না লিখে (৪ অক্টোবর ২০২৬)।
     *
     * ⓘ "নিশ্চিত করুন"-এর আগের সারাংশ ([[SalesPaperOverview::collection()]]) এটাই দেখায়। ⓘ ডাকে দরজার নিজের দুই
     * পাহারা — [[assertNoCheque()]] আর [[assertStillFits()]] (তালা ছাড়া) — দুটোই কেবল পড়ে; সইয়ের পাহারা নয়, কারণ সেটা
     * অনুরোধ লেখে।
     *
     * @return list<string> থামার কারণগুলো; খালি মানে কিছুই থামাবে না
     */
    public function whatWouldStopTheConfirm(Collection $collection): array
    {
        try {
            $this->assertNoCheque($collection->instrument);
            $this->assertStillFits($collection);
            $this->assertReadyForTheBooks($collection);
            $this->assertCreditsStillOpen($collection);
        } catch (ValidationException $e) {
            return array_values($e->validator->errors()->all());
        }

        return [];
    }

    /**
     * ⭐ খাতায় তোলার আগের তিন পাহারা — নকশা ১০০ (২১ অক্টোবর ২০২৬)।
     *   · পদ্ধতির রেফারেন্স (TrxID, ব্যাংকের রেফারেন্স, জমার স্লিপ) — cb-র [[MoneyMethod::assertReadyToPost()]] (দফা ২, ৭)।
     *   · রাউন্ডিং সীমার ভিতরে — খসড়া লেখার পরে সীমা কমলেও পোস্টের দিন আবার মাপা (দফা ৪)।
     *   · কোম্পানি চাইলে স্লিপ/স্ক্রিনশট বাধ্যতামূলক — `sales.collection.attachment_required` (দফা ১৫)।
     */
    private function assertReadyForTheBooks(Collection $collection): void
    {
        MoneyMethod::assertReadyToPost($collection);

        if ($collection->difference_kind === 'rounding') {
            $limit = $this->roundingLimit();

            if (bccomp((string) ($collection->difference_amount ?? '0'), $limit, 4) > 0) {
                throw ValidationException::withMessages([
                    'difference' => __('sales::collection_rules.rounding_over_limit', [
                        'amount' => Money::format((string) $collection->difference_amount),
                        'limit' => Money::format($limit),
                    ]),
                ]);
            }
        }

        if ((bool) $this->settings->get('sales.collection.attachment_required', false)) {
            $papers = \App\Models\Attachment::query()
                ->where('source_module', 'sales')
                ->where('source_entity', Collection::drillSourceType())
                ->where('source_entity_id', $collection->id)
                ->exists();

            if (! $papers) {
                throw ValidationException::withMessages(['slip' => __('sales::collection_rules.attachment_required')]);
            }
        }
    }

    /**
     * ⛔ ক্রেডিটের খোলা অংশ এখনো আছে কি না — পোস্টের মুহূর্তে (দফা ৩)। ⓘ একই ক্রেডিট নোট দুই খসড়ায় বসানো যায়; প্রথমটা পোস্ট
     * হলে দ্বিতীয়টার জন্য আর কিছু খোলা নেই — না দেখলে একই ক্রেডিট দুই বিলে বসত।
     */
    private function assertCreditsStillOpen(Collection $collection): void
    {
        $wanted = [];

        foreach ($collection->applications()->get() as $app) {
            $key = $app->kind.':'.$app->source_id;
            $wanted[$key] = bcadd($wanted[$key] ?? '0', (string) $app->amount, 4);
        }

        foreach ($wanted as $key => $amount) {
            [$kind, $id] = explode(':', $key);
            $source = $kind === CollectionApplication::CREDIT
                ? Collection::acrossBranches()->whereKey((int) $id)->first()
                : Note::acrossBranches()->whereKey((int) $id)->first();

            $open = match (true) {
                $source instanceof Collection => $this->openItems->openOfCredit($source),
                $source instanceof Note => $this->openItems->openOfNote($source),
                default => '0.0000',
            };

            if (bccomp($amount, $open, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::collection_rules.over_open', [
                        'no' => (string) ($source?->document_no ?? ''),
                        'open' => Money::format($open),
                    ]),
                ]);
            }
        }
    }

    /**
     * আদায়ের কাগজে চেক নয় — মালিকের নিয়ম, ২৬ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ এই কাগজ নিশ্চিত হলেই Dr নগদ/ব্যাংক / Cr গ্রাহক বসে — চেকে সেটা
     * মানে পাশের আগেই বকেয়া কমা আর সীমা খোলা। ⭐ গৃহীত চেক ঢোকে কেবল
     * চেকের খাতা দিয়ে, আর খাতায় বসে পাশের দিন ([[ChequeService]])।
     *
     * ⚠️ ঘরটা হাতে লেখা, তাই বানান যা-ই হোক ধরা হয় — আর চেক-ধরনের
     * পেমেন্ট-পদ্ধতির কোড **ও নাম** দুইটাই (ডেমোতে কোড `CHQ`)।
     *
     * ⛔ নাম কেন: POS এই ঘরে পদ্ধতির **নাম** পাঠায় ([[PosService]])। কোনো
     * কোম্পানি চেক-ধরনের পদ্ধতির নাম "Post-dated cheque" রাখলে কেবল কোড
     * মেলালে কাউন্টারের চেক পাওয়ার দিনেই খাতায় বসত।
     */
    private function assertNoCheque(mixed $instrument): void
    {
        $said = mb_strtolower(trim((string) $instrument));

        if ($said === '') {
            return;
        }

        /*
         * ⛔ লেখার ভিতরে শব্দ খোঁজা, হুবহু মিল নয় — Accounts-Finance অডিট ম১১, ৪ অক্টোবর ২০২৬।
         * ⚠️ আগে কেবল ঘরটা হুবহু "cheque" হলে থামত; "Cheque 4471", "PDC", "চেক নং ১২" লিখলে পাশের আগেই খাতায় নগদ ধরা হত,
         * আর গ্রাহকের বাকির সীমা খুলে যেত। ⓘ শব্দের সীমা মেনে — "checkout"-এর মতো শব্দ ধরা পড়ে না।
         */
        $isCheque = preg_match('/(?<![\p{L}\p{N}])(cheques?|checks?|chq|pdc|post[\s\-]?dated)(?![\p{L}])/u', $said) === 1
            || str_contains($said, 'চেক')
            || PaymentMethod::query()
                ->where('kind', 'cheque')
                ->where(fn ($q) => $q
                    ->whereRaw('LOWER(code) = ?', [$said])
                    ->orWhereRaw('LOWER(name_en) = ?', [$said])
                    ->orWhereRaw('LOWER(name_bn) = ?', [$said]))
                ->exists();

        if ($isCheque) {
            throw ValidationException::withMessages([
                'instrument' => __('accounts::validation.cheque_only_through_register'),
            ]);
        }
    }

    /**
     * খাতায় বসানোর মুহূর্তে ভাগগুলো এখনো খাটে কি না।
     *
     * একই বিলের বিপরীতে দুইটা খসড়া আদায় লেখা যায় — তৈরির সময় দুইটাই
     * বৈধ ছিল, কারণ তখন কোনোটাই খাতায় বসেনি। দুইটাই নিশ্চিত হলে বিলে
     * তার মোটের চেয়ে বেশি টাকা বসত।
     */
    /**
     * আদায়ের ভাগ এখনো খাটে কি না — বিল ধরে, সারি ধরে নয় (২৯ সেপ্টেম্বর ২০২৬)।
     *
     * ⛔ আগে প্রতিটা সারি আলাদা করে বাকির সাথে মেলানো হত: একই বিল দুই সারিতে
     * (২,০০০ + ২,০০০, বাকি ৩,৫৫০) পার হত। আর বিলের অবস্থা দেখা হত না — খসড়া
     * থাকতে বাতিল হওয়া বিলেও আদায় পাকা হত ([[ACollectionPaidOneBillTwiceTest]])।
     */
    private function assertStillFits(Collection $collection, bool $lock = false): void
    {
        $collection->loadMissing('lines');

        $wanted = [];

        foreach ($collection->lines as $line) {
            $id = (int) $line->sales_invoice_id;
            $wanted[$id] = bcadd($wanted[$id] ?? '0', (string) $line->amount, 4);
        }

        if ($wanted === []) {
            return;
        }

        $invoices = SalesInvoice::query()
            ->whereKey(array_keys($wanted))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get()
            ->keyBy('id');

        foreach ($wanted as $id => $amount) {
            $invoice = $invoices->get($id);

            if ($invoice === null) {
                continue;
            }

            if ($invoice->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.invoice_not_confirmed', ['no' => $invoice->document_no]),
                ]);
            }

            $due = $invoice->dueAmount();

            if (bccomp($amount, $due, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.over_allocated', [
                        'no' => $invoice->document_no,
                        'due' => Money::format($due),
                    ]),
                ]);
            }
        }
    }

    /**
     * বিলভিত্তিক ভাগ।
     *
     * ভাগের যোগফল আদায়ের চেয়ে বেশি হতে পারে না — হলে গ্রাহকের নামে এমন
     * টাকা জমা দেখাত যা তিনি দেননি।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    /**
     * আদায়ের টাকা গ্রাহকের খোলা বিলে, পুরনো আগে — প্রতিটায় তার বকেয়া পর্যন্ত, টাকা ফুরোলে থামা (টাকার পরিকল্পনা ৬)।
     *
     * ⓘ কেবল আদায়ের নিজের শাখার বিল — অন্য শাখার বিলে ভাগ দিলে দুই শাখার পাওনা ভুল হত ([[DepositClaimService::sharesAt()]]-এর একই নিয়ম)।
     *
     * @return list<array{sales_invoice_id: int, amount: string}>
     */
    private function oldestFirst(Collection $collection): array
    {
        $left = (string) $collection->amount;
        $lines = [];

        $bills = SalesInvoice::acrossBranches()
            ->where('sal_invoices.company_id', $collection->company_id)
            ->where('sal_invoices.customer_id', $collection->customer_id)
            ->where('sal_invoices.status', DocumentStatus::CONFIRMED)
            ->when($collection->branch_id !== null, fn ($q) => $q->where('sal_invoices.branch_id', $collection->branch_id))
            ->withCollected()
            ->orderBy('sal_invoices.trx_date')->orderBy('sal_invoices.id')
            ->get();

        foreach ($bills as $bill) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            $due = $bill->dueAmount();
            $share = bccomp($due, $left, 4) < 0 ? $due : $left;

            if (bccomp($share, '0', 4) <= 0) {
                continue;
            }

            $lines[] = ['sales_invoice_id' => (int) $bill->id, 'amount' => $share];
            $left = bcsub($left, $share, 4);
        }

        return $lines;
    }

    private function replaceLines(Collection $collection, array $lines): void
    {
        $collection->lines()->delete();

        $allocated = '0';
        $lineNo = 0;

        // ⛔ বিল ধরে চলতি যোগ — একই বিল দুই সারিতে দিলেও মোট তার বাকির বেশি নয় (২৯ সেপ্টেম্বর ২০২৬)
        $perInvoice = [];

        foreach ($lines as $line) {
            $invoiceId = (int) ($line['sales_invoice_id'] ?? 0);
            $amount = $this->money($line['amount'] ?? null);

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $invoice = SalesInvoice::query()->whereKey($invoiceId)->first();

            if ($invoice === null) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_invoice')]);
            }

            if ((int) $invoice->customer_id !== (int) $collection->customer_id) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.invoice_other_customer')]);
            }

            if ($invoice->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.invoice_not_confirmed', ['no' => $invoice->document_no]),
                ]);
            }

            /*
             * একটা বিলে তার বকেয়ার চেয়ে বেশি বসানো যায় না।
             *
             * বসালে ওই বিলটা "অতিরিক্ত শোধ" দেখাত আর অন্যটা বাকি থেকে
             * যেত, অথচ মোট মিলে যেত — ভুলটা মোটে ধরা পড়ত না, শুধু
             * তাগাদার তালিকায় ভুল বিল উঠত।
             */
            $due = $invoice->dueAmount();
            $onThisBill = bcadd($perInvoice[$invoice->id] ?? '0', $amount, 4);

            if (bccomp($onThisBill, $due, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.over_allocated', [
                        'no' => $invoice->document_no,
                        'due' => Money::format($due),
                    ]),
                ]);
            }

            $perInvoice[$invoice->id] = $onThisBill;

            CollectionLine::create([
                'collection_id' => $collection->id,
                'sales_invoice_id' => $invoice->id,
                'amount' => $amount,
                'line_no' => ++$lineNo,
            ]);

            $allocated = bcadd($allocated, $amount, 4);
        }

        if (bccomp($allocated, (string) $collection->amount, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.allocation_over_amount', [
                    'allocated' => Money::format($allocated),
                    'amount' => Money::format($collection->amount),
                ]),
            ]);
        }
    }

    /**
     * টাকাটা কোথায় ঢুকল — নগদ বা ব্যাংক জাতীয় খাতেই কেবল।
     *
     * যেকোনো খাত নিতে দিলে কেউ ভুল করে "বিক্রয়" খাতে আদায় বসাত, আর
     * তখন আয় দুইবার গোনা হত।
     */
    /**
     * খাত না বললে টাকা কোথায় যাবে — প্রধান নগদ কাউন্টারে।
     *
     * ── কেন টিল, কেন "হাতে নগদ" মাথাটা নয় ───────────────────────────
     * মাথাটা গ্রুপ, আর গ্রুপে বসানো টাকা কোনো ব্যালেন্সে দেখায় না।
     * তার চেয়ে বড় কথা: নগদ একটা খাত নয়, **কার হাতে** সেই প্রশ্ন।
     * প্রধান কাউন্টারে বসালে টাকাটা একজনের হেফাজতে যায়, আর দিনশেষের
     * গণনায় সেটা মেলে।
     *
     * ── একটাও কাউন্টার না থাকলে থামানো হয় না, বানানো হয় ─────────────
     * প্রথমে থামানো হচ্ছিল ("আগে একটা কাউন্টার খুলুন")। কিন্তু নগদ
     * নেওয়া কোনো ঐচ্ছিক কাজ নয় — যে কোম্পানি নগদ নেয় তার একটা
     * কাউন্টার লাগবেই, আর সেটা প্রথম আদায়ের মুহূর্তে চাইতে বসা মানে
     * ক্রেতাকে দাঁড় করিয়ে রেখে সেটিংসে যাওয়া।
     *
     * `ensurePrimaryTill()` এই কাজটার জন্যই আছে, আর সে থাকলে আবার
     * বানায় না। তাই টাকা কখনো অভিভাবকহীন খাতে পড়ে না।
     */
    private function defaultCashAccount(): ?Account
    {
        return $this->tills->ensurePrimaryTill()->account;
    }

    /**
     * @param  bool  $allowHolding  চেক আদায়ের পথ ছাড়া সবসময় false — তখন কেবল
     *                              মায়ের সন্তান খাত। true হলে holding-পাতাও
     *                              (হাতে চেক ১১০৪) মেনে নেওয়া হয়। ⚠️ default
     *                              false-ই সাধারণ আদায়কে ১১০৪ থেকে ঠেকায়:
     *                              নাহলে কেউ চেক ছাড়াই ১১০৪-এ টাকা বসাতে পারতেন।
     */
    private function resolveMoneyAccount(mixed $accountId, bool $allowHolding = false): Account
    {
        $account = blank($accountId)
            ? $this->defaultCashAccount()
            : Account::query()->postable()->whereKey((int) $accountId)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_id' => __('sales::validation.unknown_account'),
            ]);
        }

        /*
         * গ্রুপ খাতে টাকা বসে না।
         *
         * ── কী ঘটছিল ────────────────────────────────────────────────
         * খাত না বললে ডিফল্ট ছিল ১১০১ "হাতে নগদ" — যেটা একটা **মাথা**,
         * খাত নয়। ওখানে বসানো সারি খতিয়ানে থাকত ঠিকই, কিন্তু কোনো
         * ব্যালেন্সে দেখাত না: `Account::balanceOn()` গ্রুপের নিজের
         * সারি গোনে না, কেবল সন্তানদের যোগ করে (আর সেটাই ঠিক, কারণ
         * গ্রুপে সরাসরি এন্ট্রি বসার কথাই নয়)।
         *
         * ফলে কাউন্টারের নগদ বিক্রয়ের টাকা এমন জায়গায় জমা হত যেখানে
         * সেটা কোথাও গোনা হত না — না টিলের ব্যালেন্সে, না ড্যাশবোর্ডের
         * "হাতে নগদ"-এ, না নগদ গণনার তুলনায়।
         *
         * ভাউচারে এই নিয়মটা আগে থেকেই ছিল (`assertLinesArePostable`);
         * আদায় ওই পথে না গিয়ে সরাসরি খাত বেছে নিত, তাই পাহারাটা এখানে
         * পৌঁছায়নি।
         */
        if ($account->is_group) {
            throw ValidationException::withMessages([
                'account_id' => __('sales::validation.group_takes_no_money', ['name' => $account->name()]),
            ]);
        }

        // মায়ের সন্তান কোনো খাত — মাথা নিজে নয় (উপরের নিয়ম)
        $isChildOfMother = Account::query()
            ->whereKey($account->parent_id)
            ->whereIn('code', StandardChart::MONEY_PARENTS)
            ->exists();

        /*
         * টাকা ধরে এমন holding-পাতা (হাতে চেক ১১০৪) — কেবল যখন কলকারী
         * স্পষ্টভাবে অনুমতি দেয় (চেক আদায়ের পথ)। সাধারণ আদায়ে $allowHolding
         * false, তাই ১১০৪ তখনো "টাকার খাত নয়" — চেক ছাড়া ১১০৪-এ টাকা বসে না।
         */
        $isHoldingLeaf = $allowHolding && in_array($account->code, StandardChart::MONEY_HOLDING, true);

        if (! $isChildOfMother && ! $isHoldingLeaf) {
            throw ValidationException::withMessages([
                'account_id' => __('sales::validation.not_a_money_account', ['name' => $account->name()]),
            ]);
        }

        return $account;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages(['amount' => __('sales::validation.not_a_number')]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['amount' => __('sales::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    /**
     * ⛔ একই গ্রাহকের স্লিপ-পাহারা একবারে একজন — গ্রাহকের সারিতে তালা (১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️১৪)।
     *
     * ⓘ [[SlipIsNotUsedTwice]] তালা ছাড়া `exists()` দেখে, আর পিছনে কোনো অনন্য সূচি নেই (খালি স্লিপ বৈধ, বাতিলটা বাদ)। একই মুহূর্তে
     * একই বিকাশ TrxID-এ দুই খসড়া সংরক্ষণ হলে দুটোই "নেই" দেখে বসে যেত, আর পাকা হলে গ্রাহকের পাওনা দুইবার কমত। ⭐ খোঁজার আগে
     * গ্রাহকের সারি আটকানো — দ্বিতীয়জন প্রথমজনের লেখা শেষ হওয়া পর্যন্ত দাঁড়ায়, তারপর সেটা দেখে থামে। তৈরি আর সম্পাদনা দুই দরজাই।
     */
    private function holdTheCustomersSlips(int $customerId): void
    {
        \App\Modules\Customer\Models\Customer::query()->withoutGlobalScopes()->whereKey($customerId)->lockForUpdate()->value('id');
    }

    private function assertEditable(Collection $collection): void
    {
        if ($collection->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.only_draft_edits', ['no' => $collection->document_no]),
            ]);
        }
    }

    private function account(string $code): Account
    {
        $account = Account::query()->postable()->where('code', $code)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.missing_account', ['code' => $code]),
            ]);
        }

        return $account;
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('sales::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
