<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\CreditHolds;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\LedgerEntry;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * একজন গ্রাহকের বাকির সীমা কতটা ব্যবহার হয়ে গেছে — আর দেয়ালটা।
 *
 * ── ⭐ মালিকের নিয়ম, ২৫–২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"limit mane limit 100%, karo khomota thakbe na limit cross korar,
 * emon ki malikero."* ⓘ কারণ অঙ্কের: মার্জিন **৩.৮২%**, আর *"ekjaygay
 * taka atkale koyek bochorer lav sesh"*।
 *
 * ── ⛔ আগে সীমা কেবল খাতার বকেয়া দেখত ────────────────────────────────
 * ৫০,০০০ সীমার গ্রাহককে ৪০,০০০ করে তিনটা ডিও দেওয়া যেত — প্রতিটা আলাদা
 * করে সীমার ভিতরে, অথচ মাল বেরোত ১,২০,০০০ টাকার। ⓘ কারণ ডিওর মাল
 * খাতায় বসে বিলের দিনে, আর ততদিন "বকেয়া" জানতই না।
 *
 * ⭐ মালিকের উত্তর: *"hya obosoi, sudu tai noy bill khosora hole product
 * zemon atkay temon customer er balanceo atkabe"*। তাই ব্যবহৃত সীমা তিন ভাগ:
 *
 *     খাতার বকেয়া            নিশ্চিত বিল − জমা           (Customer::outstanding)
 *   + বিল না হওয়া চালান       মাল বেরিয়েছে, বিল হয়নি
 *   + খসড়া বিল               কাউন্টারে ধরে রাখা বা "খসড়া রাখুন"
 *
 * ⛔ একই টাকা কখনো দুইবার নয়: চালানের যে সারি কোনো (বাতিল নয় এমন) বিলের
 * সারিতে ঢুকেছে, সে চালানের ভাগ থেকে সরে যায় — বিলটা নিজের ভাগে গোনা
 * হয়। ⓘ বিক্রয় আদেশ কিছুই আটকায় না: *"sales order astei pare"*।
 *
 * ── ⚠️ কেন এটা Sales-এ, Customer-এ নয় ────────────────────────────────
 * Customer মডিউল বিক্রয়কে চেনে না (নির্ভরতার দিক `sales → customer`)।
 * ⓘ খাতার বকেয়া আর শূন্য-সীমার মানে থাকল [[Customer::wouldExceedCreditLimit()]]
 * -এ — এই সেবা কেবল বিক্রয়ের দুইটা ভাগ যোগ করে ওখানে পাঠায়।
 *
 * ── ⛔ কোনো ওভাররাইড নেই ─────────────────────────────────────────────
 * কারো চাবি সীমা পার করায় না, সুপার অ্যাডমিনেরও না — মালিক ২৬
 * সেপ্টেম্বর ক বেছেছেন। ⓘ একমাত্র পথ গ্রাহকের সীমা বাড়ানো, আর সেই বদল
 * খাতায় ওঠে (আগে কত, পরে কত) ও অনুমোদনে যায়। কোম্পানি চাইলে পুরো সীমা
 * ⛔ বন্ধ রাখার সুইচও আর নেই (১ অক্টোবর ২০২৬, [[isOn()]]) — শূন্য সীমা মানে বাকি নয়।
 */
final class CreditExposure implements CreditHolds
{
    /**
     * ⛔ তালাসহ দেয়ালের ভিতরে হিসাবগুলো **তালাসহ পড়া**য় — ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ InnoDB (REPEATABLE READ) সাধারণ পড়ায় লেনদেনের ছবি দেখায়, আর সেই ছবি
     * অনেক সময় তালার **আগেই** উঠে যায়। মাপা হয়েছে: দ্বিতীয় কাউন্টার তালা পেয়েও
     * সাধারণ পড়ায় প্রথমজনের চালান দেখেনি (০টা), অথচ তালাসহ পড়ায় দেখেছে (১টা)
     * — আর দুইজনে মিলে সীমা পার করেছে। ⭐ তালাসহ পড়া ছবি মানে না, সর্বশেষ
     * কমিট দেখে; তাই এই পতাকা চালু থাকলে প্রতিটা হিসাব তা-ই করে।
     */
    private bool $readLatest = false;

    /**
     * [[CreditHolds]] — গ্রাহকের পাতার জন্য, একই হিসাব।
     *
     * ⚠️ আলাদা যুক্তি নয়, [[pendingFor()]]-ই — নইলে পাতা আর কাউন্টার
     * একদিন দুই রকম গুনত।
     */
    public function heldFor(int $customerId): string
    {
        return $this->pendingFor([$customerId])[$customerId] ?? '0';
    }

    /**
     * ⭐ বাকির সীমা চালু কি না — প্রতিষ্ঠানের একমাত্র সুইচ (মালিকের বিক্রয়-পরিকল্পনা, সংস্করণ ২, ৪ অক্টোবর ২০২৬):
     * *"বাকির সীমা পরম: সীমার বাইরে বাকি যাবে না। কোনো প্রতিষ্ঠান সীমার বাইরে বাকি দিতে চাইলে নিজের সেটিংস থেকে
     * 'বাকির সীমা' সুইচ বন্ধ রাখবে"*।
     *
     * ⓘ চালু (ডিফল্ট) — আজকের মতোই পরম, কারও ছাড় নেই। বন্ধ — কোনো পথে (কাউন্টার, আদেশ, DO, ফোন, পোর্টাল) সীমা
     * যাচাই হয় না; সব পথ এই একটা প্রশ্নই করে ([[assertRoom()]], [[assertRoomLocked()]], [[check()]])।
     * ⛔ সুইচ বদলাতে পারেন কেবল সুপার অ্যাডমিন, আর বদল খাতায় ওঠে (`super_admin_only`, [[SettingsService::mayChange()]])।
     * ⓘ ১ অক্টোবরের "সবসময় চালু" নিয়ম এই নির্দেশে উল্টেছে ([[TheCreditSwitchIsTheCompanysOwnChoiceTest]])।
     */
    public function isOn(): bool
    {
        return app(\App\Core\Services\SettingsService::class)->enabled('customer.credit_limit_enabled');
    }

    /**
     * ⭐ নতুন বাকি বন্ধ কি না, আর কেন — বাকি ও আদায়, ৫ অক্টোবর ২০২৬ (SAP Credit Management-এর "oldest open item")।
     *
     * ⓘ সীমা দেখে "কত", এটা দেখে "আদৌ কি": কোম্পানি `customer.overdue_block_days` = X বসালে, কোনো বিলের টাকা মেয়াদের
     * X দিনের বেশি পরেও বাকি থাকলে সেই গ্রাহক নতুন বাকি পান না। ⛔ সীমার সুইচ বন্ধ থাকলে কিছুই নয় ([[isOn()]])।
     * ⓘ পুরো টাকা দিলে কেনা চলে — দেয়ালটা কেবল **নতুন বাকি** আটকায় ([[newCredit()]])।
     *
     * @param  iterable<Customer>  $customers
     * @return array<int, string>  গ্রাহক → "নিশ্চিত হবে না"-র কথা (যাঁর কিছু নেই, তিনি তালিকায় নেই)
     */
    public function stopsFor(iterable $customers): array
    {
        return array_map(fn (array $d) => $d['message'], $this->stopDetails($customers));
    }

    /**
     * [[stopsFor()]]-এর ভিতর — কথার সাথে কত দিলে দেয়াল ওঠে (`clears`; null = পুরো দায় শোধ ছাড়া নয়)।
     *
     * @param  iterable<Customer>  $customers
     * @return array<int, array{message: string, clears: ?string}>
     */
    private function stopDetails(iterable $customers): array
    {
        if (! $this->isOn()) {
            return [];
        }

        $out = [];
        $days = $this->overdueDays();

        $list = [];
        foreach ($customers as $customer) {
            $list[(int) $customer->id] = $customer;
        }

        // ⭐ হাতে বসানো "বাকি বন্ধ" আগে ([[Customer::isCreditBlocked()]]) — পুরো দায় শোধ ছাড়া দেয়াল ওঠে না
        foreach ($list as $id => $customer) {
            if ($customer->isCreditBlocked()) {
                $out[$id] = [
                    'message' => __('sales::credit.blocked_stop', ['reason' => (string) $customer->credit_block_reason]),
                    'clears' => null,
                ];
                unset($list[$id]);
            }
        }

        if ($days > 0) {
            foreach ($this->overdueBills($list, $days) as $id => $bills) {
                $total = array_reduce($bills, fn (string $sum, array $b) => bcadd($sum, $b['unpaid'], 4), '0');
                $names = array_column(array_slice($bills, 0, 3), 'no');
                $out[$id] = [
                    'message' => __('sales::credit.overdue_stop', [
                        'days' => $days,
                        'bills' => implode(', ', $names).(count($bills) > 3 ? ' …' : ''),
                        'amount' => Money::format($total),
                    ]),
                    'clears' => $total,
                ];
            }
        }

        return $out;
    }

    /** একজনের — [[stopsFor()]]-এর একই হিসাব */
    public function stopFor(Customer $customer): ?string
    {
        return $this->stopDetails([$customer])[(int) $customer->id]['message'] ?? null;
    }

    /** কোম্পানির দেয়ালের দিন — ০ মানে বন্ধ */
    public function overdueDays(): int
    {
        return max(0, (int) app(\App\Core\Services\SettingsService::class)->get('customer.overdue_block_days', 0));
    }

    /**
     * ⭐ মেয়াদের `$days` দিনের বেশি পরেও যে বিলগুলোর টাকা বাকি — পুরনোটা আগে।
     *
     * ── ⓘ বিলের বাকি কোথা থেকে ───────────────────────────────────────────
     * আদায়ের সূচির ([[CollectionDueReport]]) "মেয়াদ পেরোনো" ঘরের হুবহু নিয়ম: বিলের বাকি = মোট − আদায় − রসিদ − পাকা
     * ফেরত ([[SalesInvoice::scopeWithCollected()]]); মেয়াদ বিলের `due_on` (কাউন্টার বসায় গ্রাহকের `credit_days` থেকে),
     * না লেখা থাকলে বিলের দিন। ⚠️ বয়সের রিপোর্ট ([[PartyReports::ageing()]]) বিল ধরে নয়, খাতার সারি ধরে গোনে — তাই বিল
     * ধরে যে হিসাবটা আগে থেকেই আছে, সেটাই।
     *
     * ── ⓘ কোনো বিলে না বসা জমা ─────────────────────────────────────────
     * অগ্রিম বা খাতার জমা কোনো বিলে বাঁধা না থাকলে বিলের হিসাব তাকে দেখে না, অথচ খাতায় বকেয়া কমেছে। ⭐ সেই
     * টাকাটা (খোলা বিলের মোট − খাতার বকেয়া) **সবচেয়ে পুরনো বিল থেকে** কাটা হয় — বয়সের রিপোর্টের নিয়ম ("আদায় সবচেয়ে
     * পুরনো ধাপ থেকে কাটা হয়")। ⛔ নইলে যিনি পুরো টাকা অগ্রিমে দিয়েছেন তিনিও আটকাতেন।
     *
     * @param  array<int, Customer>  $customers  id → গ্রাহক
     * @return array<int, list<array{no: string, unpaid: string, due_on: string}>>
     */
    public function overdueBills(array $customers, int $days): array
    {
        if ($customers === [] || $days <= 0) {
            return [];
        }

        $cutoff = Carbon::today()->subDays($days)->toDateString();
        $due = '(i.total - i.collected_total - i.voucher_total - i.returned_total)';
        $on = 'COALESCE(i.due_on, i.trx_date)';

        $bills = SalesInvoice::query()->posted()->withCollected()->toBase()
            ->where('sal_invoices.company_id', CompanyContext::id())
            ->whereIn('sal_invoices.customer_id', array_keys($customers));

        $rows = DB::query()->fromSub($bills, 'i')
            ->whereRaw("{$due} > 0.0001")
            ->orderBy('i.customer_id')
            ->orderByRaw($on)
            ->orderBy('i.id')
            ->selectRaw("i.customer_id, i.document_no, {$on} as due_date, {$due} as unpaid")
            ->get()
            ->groupBy('customer_id');

        $out = [];

        foreach ($rows as $customerId => $open) {
            $customer = $customers[(int) $customerId] ?? null;

            // ⓘ কেবল যাঁর অন্তত একটা পুরনো খোলা বিল আছে — বাকিদের খাতা পড়ার দরকারই নেই
            if ($customer === null || ! $open->contains(fn ($r) => (string) $r->due_date < $cutoff)) {
                continue;
            }

            $sum = $open->reduce(fn (string $s, $r) => bcadd($s, (string) $r->unpaid, 4), '0');
            $loose = bcsub($sum, $customer->outstanding(), 4);
            $loose = bccomp($loose, '0', 4) > 0 ? $loose : '0';

            foreach ($open as $row) {
                $left = (string) $row->unpaid;
                $take = bccomp($loose, $left, 4) < 0 ? $loose : $left;
                $left = bcsub($left, $take, 4);
                $loose = bcsub($loose, $take, 4);

                if ((string) $row->due_date < $cutoff && bccomp($left, '0.0001', 4) > 0) {
                    $out[(int) $customerId][] = ['no' => (string) $row->document_no, 'unpaid' => $left, 'due_on' => (string) $row->due_date];
                }
            }
        }

        return $out;
    }

    /**
     * এই কাগজে কত **নতুন** বাকি — অগ্রিম যতটা ঢাকে, ততটা বাকি নয়।
     *
     * ⓘ = min(এই কাগজের বাকি, কাগজের পরে মোট দায়)। অগ্রিম ৫০,০০০-এর গ্রাহক ১০,০০০-এর মাল নিলে নতুন বাকি ০।
     */
    private function newCredit(string $unpaid, string $exposureAfter): string
    {
        $new = bccomp($unpaid, $exposureAfter, 4) < 0 ? $unpaid : $exposureAfter;

        return bccomp($new, '0', 4) > 0 ? $new : '0';
    }

    /**
     * ⓘ বিলটা কেবল আগেই বেরোনো মালের — তার প্রতিটা সারি পাকা চালানে বাঁধা।
     *
     * ⛔ মাল গেট পেরিয়ে গেলে বিল আটকানো মানে মাল বাইরে অথচ খাতায় নেই — তাতে নতুন বাকি জন্মায় না, চালানের দিনেই
     * জন্মেছিল (তখন দেয়াল চালানেই ছিল)। তাই বাকি বন্ধের দেয়াল এমন বিল আটকায় না; সীমার দেয়াল আগের মতোই।
     */
    private function billsGoodsAlreadyGone(?int $invoiceId): bool
    {
        if ($invoiceId === null) {
            return false;
        }

        $lines = DB::table('sal_invoice_lines')->where('sales_invoice_id', $invoiceId)->count();

        if ($lines === 0) {
            return false;
        }

        $gone = DB::table('sal_invoice_lines as il')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->where('il.sales_invoice_id', $invoiceId)
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', DocumentStatus::POSTED)
            ->count();

        return $gone === $lines;
    }

    /**
     * ⭐ ডেলিভারি অর্ডারের হিসাবের যাচাই — সফটওয়্যার নিজে অনুমোদন দেয়, ২ অক্টোবর ২০২৬ (বিক্রয়ের কাজের ধারা, ধাপ গ)।
     *
     * ⓘ দেয়ালের হুবহু হিসাব ([[assertRoom()]]): খাতার বকেয়া + আটকে থাকা ([[pending()]]) + এই কাগজ ≤ সীমা — ছুড়ে না
     * দিয়ে ফল ফেরত দেয়, কারণ এখানে "না" মানে বিক্রি বন্ধ নয়, DO "টাকার জন্য আটকে" থাকে আর টাকা এলে আবার যাচাই।
     * ⭐ সীমা ০ হলেও অগ্রিম কুলোলে চলে — বকেয়া ঋণাত্মক (জমা) হলে যোগফল ০-এর নিচে থাকে।
     * ⛔ চেক কেবল ক্লিয়ার হলে (২৬ সেপ্টেম্বর): পুরনো পথে হাতে আসার দিনই জমায় বসা চেক ক্লিয়ার না হলে টাকাটা ফেরত
     * যোগ হয় ([[unclearedCheques()]])।
     *
     * @return array{fits: bool, short: string, used_percent: ?string, reason: ?string}  ⓘ `used_percent` সীমা ০ হলে null; `reason` বাকি বন্ধের কথা ([[stopsFor()]])
     */
    public function check(Customer $customer, string $adding, ?int $exceptDeliveryOrderId = null): array
    {
        // ⓘ সুইচ বন্ধ — সীমা যাচাই হয় না, DO-র হিসাবের "সীমা" সতর্কতাও চুপ ([[isOn()]])
        if (! $this->isOn()) {
            return ['fits' => true, 'short' => '0', 'used_percent' => null, 'reason' => null];
        }

        $limit = bcadd((string) ($customer->credit_limit ?? '0'), '0', 4);

        $exposure = bcadd(
            bcadd($customer->outstanding(), $this->pending($customer, exceptDeliveryOrderId: $exceptDeliveryOrderId), 4),
            bcadd($this->unclearedCheques($customer), $adding, 4),
            4,
        );

        $short = bcsub($exposure, $limit, 4);
        $short = bccomp($short, '0', 4) > 0 ? $short : '0.0000';
        $reason = null;

        /*
         * ⭐ বাকি বন্ধ ([[stopsFor()]]) — এই কাগজে নতুন বাকি জন্মালে আটকায়, সীমায় জায়গা থাকলেও (৫ অক্টোবর ২০২৬)।
         * ⓘ "কম" = কত দিলে দেয়াল ওঠে: পুরনো বাকির ক্ষেত্রে সেই বিলগুলোর টাকা (অগ্রিম সবচেয়ে পুরনো বিলে কাটে), নইলে পুরো দায়।
         */
        $stop = $this->stopDetails([$customer])[(int) $customer->id] ?? null;

        if ($stop !== null && bccomp($this->newCredit(bcadd($adding, '0', 4), $exposure), '0', 4) > 0) {
            $reason = $stop['message'];
            $clears = $stop['clears'] === null || bccomp($stop['clears'], $exposure, 4) > 0 ? $exposure : $stop['clears'];
            $short = bccomp($clears, $short, 4) > 0 ? bcadd($clears, '0', 4) : $short;
        }

        return [
            'fits' => $reason === null && bccomp($short, '0', 4) <= 0,
            'short' => $short,
            'used_percent' => bccomp($limit, '0', 4) > 0 ? bcdiv(bcmul($exposure, '100', 4), $limit, 2) : null,
            'reason' => $reason,
        ];
    }

    /**
     * ক্লিয়ার না হওয়া চেক, যা আগের নিয়মে হাতে আসার দিনই গ্রাহকের জমায় উঠেছিল।
     *
     * ⓘ কোনটা আগের নিয়মে উঠেছিল, তা ডেটাই বলে ([[ChequeService::receivedIntoTheBooks()]]-এর তিন পথ): আদায়ের কাগজ,
     * রসিদ ভাউচার, বা চেকের নিজের দাখিলা। ⚠️ নতুন নিয়মের চেক ক্লিয়ারের আগে খাতায়ই ওঠে না — তাকে আবার যোগ করলে
     * দুইবার গোনা হত।
     */
    public function unclearedCheques(Customer $customer): string
    {
        $sum = DB::table('acc_cheques as ch')
            ->where('ch.company_id', $customer->company_id)
            ->whereNull('ch.deleted_at')
            ->where('ch.direction', 'received')
            ->where('ch.party_type', 'customer')
            ->where('ch.party_id', $customer->id)
            ->whereIn('ch.status', ['pending', 'deposited'])
            ->where(fn ($q) => $q->whereNotNull('ch.collection_id')
                ->orWhereNotNull('ch.voucher_id')
                ->orWhereExists(fn ($e) => $e->from('ledger_entries as le')
                    ->where('le.source_type', 'cheque')
                    ->whereColumn('le.source_id', 'ch.id')))
            // ⓘ তালাসহ দেয়ালে সর্বশেষ অবস্থা — একই মুহূর্তে চেক ক্লিয়ার/ফেরত হলেও ([[assertRoomLocked()]])
            ->when($this->readLatest, fn ($q) => $q->sharedLock())
            ->sum('ch.amount');

        return bcadd((string) $sum, '0', 4);
    }

    /**
     * ⛔ চালানটা আসলে কত টাকায় বিল হবে — দেয়াল এটাই মাপে, ২ অক্টোবর ২০২৬ (abos-bb-এর ধরা)।
     *
     * ── ⛔ কী ভুল ছিল ─────────────────────────────────────────────────────
     * চালানের সারিতে ছাড় থাকে না — `amount` = পরিমাণ × দর ([[DeliveryChallanService::replaceLines()]]);
     * সারির ছাড়, বিলের ছাড়, রাউন্ডিং, ভ্যাট আর অফারের ভাগ বসে কেবল বিলে। ⚠️ তাই দেয়াল মাপত ছাড়ের **আগের**
     * দাম: ১,২০০-র মালে ১০% ছাড়, ১,০৮০ পুরো নগদে — বাকি শূন্য, অথচ "১২০ সীমা পার", আর সীমা ০-এর নগদ ক্রেতা
     * ছাড় নিলেই আটকাতেন।
     *
     * ── ⭐ এক মাপ, দুই হিসাব নয় ─────────────────────────────────────────
     * চালানের **প্রতিটা** সারি খসড়া বিলে বাঁধা থাকলে সেই বিলগুলোর মোট — বিলের নিজের হিসাবেই গোনা, এখানে
     * আবার গোনা নয়। ⓘ কাউন্টার এখন চালান নিশ্চিত করার আগে খসড়া বিল বাঁধে ([[DirectSaleService::sellNow()]]),
     * ধরে রাখা বিক্রয় আগে থেকেই বাঁধত। ⚠️ একটা সারিও বাঁধা না থাকলে চালানের নিজের মোট — আংশিক বিলের
     * বাকি অংশ নিচে গোনা পড়ত না।
     */
    public function billedAs(DeliveryChallan $challan): string
    {
        $lines = $challan->lines()->count();

        $drafts = DB::table('sal_invoices as i')
            ->where('i.company_id', $challan->company_id)
            ->whereNull('i.deleted_at')
            ->where('i.status', DocumentStatus::DRAFT)
            ->whereExists(fn ($q) => $q->from('sal_invoice_lines as il')
                ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
                ->whereColumn('il.sales_invoice_id', 'i.id')
                ->where('cl.delivery_challan_id', $challan->id));

        $bound = DB::table('sal_invoice_lines as il')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->where('cl.delivery_challan_id', $challan->id)
            ->whereNull('i.deleted_at')
            ->where('i.status', DocumentStatus::DRAFT)
            ->distinct()
            ->count('il.delivery_challan_line_id');

        if ($lines === 0 || $bound < $lines) {
            return (string) $challan->total;
        }

        return bcadd((string) $drafts->sum('i.total'), '0', 4);
    }

    /**
     * খাতার বাইরে আটকে থাকা টাকা: বিল না হওয়া চালান + খসড়া বিল।
     *
     * @param  int|null  $exceptInvoiceId  এই বিলটা নিজেকে গোনে না — নইলে
     *                                     খসড়া বিল নিশ্চিত করার সময় নিজের
     *                                     আটকানো টাকার জন্যই আটকে যেত।
     * @param  int|null  $exceptChallanId  এই চালানের খসড়া বিলগুলোও বাদ —
     *                                     কাউন্টারে ধরে রাখা বিক্রয় শেষ করার
     *                                     সময় চালান আর তার বিল একই টাকা।
     * @param  int|null  $exceptDeliveryOrderId  এই DO নিজেকে গোনে না — যাচাইয়ের সময় সে নিজেই `adding`।
     *
     * ⭐ চতুর্থ ভাগ, ৩ অক্টোবর ২০২৬: হিসাবে অনুমোদিত অথচ বিল না হওয়া ডেলিভারি অর্ডার ([[approvedDeliveryOrders()]])।
     * ⛔ না গুনলে দুইটা DO আলাদা করে সীমার ভিতরে, মিলে বাইরে — দুটোই অনুমোদিত, মাল দুইবারের।
     */
    public function pending(Customer $customer, ?int $exceptInvoiceId = null, ?int $exceptChallanId = null, ?int $exceptDeliveryOrderId = null): string
    {
        return bcadd(
            bcadd(
                $this->unbilledChallans($customer),
                $this->draftInvoices($customer, $exceptInvoiceId, $exceptChallanId),
                4,
            ),
            bcadd(
                $this->approvedDeliveryOrders([(int) $customer->id], $exceptDeliveryOrderId)[(int) $customer->id] ?? '0',
                $this->openOrders([(int) $customer->id])[(int) $customer->id] ?? '0',
                4,
            ),
            4,
        );
    }

    /**
     * ⭐ পঞ্চম ভাগ — খোলা নিশ্চিত বিক্রয় আদেশ, যতটা এখনো যায়নি (SO+DO মেশানো, ধাপ ৪, উত্তর ৪; ৪ অক্টোবর ২০২৬)।
     *
     * ⓘ কেবল নতুন ধারার আদেশ (`hold_mode = holds`), অনুমোদিত বা নিশ্চিত — পুরনো ধারার আদেশের হয়ে DO-ই গোনা হয়
     * ([[approvedDeliveryOrders()]]); দুইটাই গুনলে একই বিক্রি দুবার।
     *
     * ⓘ অঙ্ক = সারির টাকা × (আদেশ − পাকা চালানে যাওয়া) ÷ আদেশ। ⛔ পাকা চালানে যা গেছে তা "বিল না হওয়া চালান"-এ গোনা
     * হয় ([[unbilledChallans()]]) — আবার গুনলে দুবার। ⓘ খসড়া চালানের অংশ আদেশেই থাকে, কারণ চালানের ভাগ কেবল পাকা
     * চালান গোনে; বাদ দিলে ঐটুকু কোথাও গোনা হত না।
     *
     * @param  list<int>  $customerIds
     * @return array<int, string>  ক্রেতা → এখনো না-যাওয়া আদেশের দাম
     */
    private function openOrders(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $delivered = DB::table('sal_challan_lines as cl')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->whereColumn('cl.sales_order_line_id', 'ol.id')
            ->where('c.company_id', CompanyContext::id())
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', DocumentStatus::POSTED)
            ->selectRaw('COALESCE(SUM(cl.delivered_qty), 0)');

        $query = DB::table('sal_order_lines as ol')
            ->join('sal_orders as o', 'o.id', '=', 'ol.sales_order_id')
            ->whereIn('o.customer_id', $customerIds)
            ->where('o.company_id', CompanyContext::id())
            ->whereNull('o.deleted_at')
            ->where('o.hold_mode', SalesOrderStatus::HOLD_HOLDS)
            ->whereIn('o.status', [SalesOrderStatus::APPROVED, SalesOrderStatus::CONFIRMED])
            ->where('ol.ordered_qty', '>', 0)
            ->select(['o.customer_id', 'ol.ordered_qty', 'ol.rejected_qty', 'ol.amount'])
            ->selectSub($delivered, 'delivered');

        if ($this->readLatest) {
            $query->sharedLock();
        }

        $out = [];

        foreach ($query->get() as $row) {
            // ⭐ "আর দেওয়া হবে না" অংশ আর পাওনা নয় — সীমায় গোনা হয় না (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৭)
            $left = bcsub(bcsub((string) $row->ordered_qty, (string) ($row->rejected_qty ?? '0'), 4), (string) $row->delivered, 4);

            if (bccomp($left, '0', 4) <= 0) {
                continue;
            }

            $value = bcdiv(bcmul((string) $row->amount, $left, 6), (string) $row->ordered_qty, 4);
            $out[(int) $row->customer_id] = bcadd($out[(int) $row->customer_id] ?? '0', $value, 4);
        }

        return $out;
    }

    /**
     * হিসাবে অনুমোদিত, এখনো বিল হয়নি — ডিপোর যাচাইয়ে বা তার আগে দাঁড়িয়ে থাকা DO-র টাকা।
     *
     * ⓘ বিল হলে DO-র `sales_invoice_id` বসে, আর টাকাটা তখন বিলের পথে (খসড়া বা খাতায়) গোনা হয় — এখানে আর নয়।
     *
     * @param  list<int>  $customerIds
     * @return array<int, string>
     */
    private function approvedDeliveryOrders(array $customerIds, ?int $exceptDeliveryOrderId = null): array
    {
        if ($customerIds === []) {
            return [];
        }

        $query = DB::table('sal_delivery_orders as d')
            ->whereIn('d.customer_id', $customerIds)
            ->where('d.company_id', CompanyContext::id())
            ->whereNull('d.deleted_at')
            ->whereNull('d.sales_invoice_id')
            ->whereIn('d.status', ['accounts_approved', 'depot_check'])
            ->when($exceptDeliveryOrderId !== null, fn ($q) => $q->where('d.id', '!=', $exceptDeliveryOrderId))
            ->groupBy('d.customer_id')
            ->selectRaw('d.customer_id, SUM(d.total) as held');

        if ($this->readLatest) {
            $query->sharedLock();
        }

        return $query->pluck('held', 'customer_id')->map(fn ($v) => bcadd((string) $v, '0', 4))->all();
    }

    /**
     * অনেক গ্রাহকের আটকে থাকা টাকা একবারে — কাউন্টারের তালিকার জন্য।
     *
     * ⚠️ গ্রাহকপ্রতি [[pending()]] ডাকলে দুইশো গ্রাহকে চারশো কোয়েরি, আর
     * কাউন্টারের পাতা প্রতিবার খুলতে সেটা লাগত। ⓘ এখানে দুইটা, দল বেঁধে।
     * ⛔ হিসাবের নিয়ম [[pending()]]-এরই — একই দুই ভাগ, একই ছাঁকনি।
     *
     * @param  list<int>  $customerIds
     * @return array<int, string> গ্রাহক → আটকে থাকা টাকা (যাঁর কিছু নেই, তিনি তালিকায় নেই)
     */
    public function pendingFor(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        /*
         * ⚠️ কোম্পানির ছাঁকনি স্পষ্ট করে — abos-7d আর abos-67-এর ধরা, ২৬ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ গ্রাহকের আইডি এক কোম্পানিরই, তাই আজ অন্য কোম্পানির সারি আসত না।
         * ⛔ তবু কাঁচা কোয়েরি-নির্মাতা গ্লোবাল স্কোপ মানে না, আর এই ক্লাসের দেয়াল
         * ([[assertRoom()]]) কোম্পানি ধরে গোনে — দুই জায়গায় দুই ছাঁকনি থাকলে পর্দা
         * এক সংখ্যা দেখাত আর দেয়াল আরেক সংখ্যায় আটকাত।
         */

        $billed = DB::table('sal_invoice_lines as il')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->whereColumn('il.delivery_challan_line_id', 'cl.id')
            ->whereNull('i.deleted_at')
            ->where('i.status', '!=', DocumentStatus::CANCELLED);

        $challans = DB::table('sal_challan_lines as cl')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->whereIn('c.customer_id', $customerIds)
            ->where('c.company_id', CompanyContext::id())
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', DocumentStatus::POSTED)
            ->whereNotExists($billed)
            ->groupBy('c.customer_id')
            ->selectRaw('c.customer_id, SUM(cl.amount) as held')
            ->pluck('held', 'customer_id');

        $drafts = DB::table('sal_invoices as i')
            ->whereIn('i.customer_id', $customerIds)
            ->where('i.company_id', CompanyContext::id())
            ->whereNull('i.deleted_at')
            ->where('i.status', DocumentStatus::DRAFT)
            ->whereNull('i.draft_paused_at')
            ->groupBy('i.customer_id')
            ->selectRaw('i.customer_id, SUM(i.total) as held')
            ->pluck('held', 'customer_id');

        $out = [];

        // ⭐ চতুর্থ ভাগ — হিসাবে অনুমোদিত, বিল না হওয়া DO ([[pending()]]-এর একই নিয়ম)
        $orders = $this->approvedDeliveryOrders(array_map('intval', $customerIds));

        // ⭐ পঞ্চম ভাগ — খোলা নিশ্চিত বিক্রয় আদেশের না-যাওয়া অংশ ([[openOrders()]])
        $salesOrders = $this->openOrders(array_map('intval', $customerIds));

        foreach ([$challans, $drafts, $orders, $salesOrders] as $part) {
            foreach ($part as $customerId => $held) {
                $out[(int) $customerId] = bcadd($out[(int) $customerId] ?? '0', (string) $held, 4);
            }
        }

        return $out;
    }

    /**
     * ⛔ দেয়াল — এই কাগজটা এলে সীমা পার হবে কি না।
     *
     * ⚠️ ডাকতে হবে **অনুমোদনের আগে**। [[DocumentApproval::assertClear()]]
     * কাগজ অনুমোদনে পাঠিয়ে ফিরে যায়, আর তখন দেয়াল কোনো প্রশ্নই পেত না —
     * ৮৯,৭২০ টাকার বিল ৫০,০০০ সীমায় ঠিক এভাবেই সইয়ের সারিতে চলে গিয়েছিল।
     *
     * @param  string  $adding  এই কাগজের মোট টাকা
     * @param  string  $payingNow  এখনই গোনা টাকা — কাউন্টারে নগদ, ব্যাংক, মোবাইল।
     *                             ⓘ চেক কাউন্টারে নেওয়াই যায় না (মালিক, ২৬
     *                             সেপ্টেম্বর), তাই এখানে চেক আসে না।
     */
    public function assertRoom(
        Customer $customer,
        string $adding,
        string $payingNow = '0',
        ?int $exceptInvoiceId = null,
        ?int $exceptChallanId = null,
    ): void {
        if (! $this->isOn()) {
            return;
        }

        /*
         * ⭐ বিক্রির **পরে** গ্রাহকের মোট বকেয়া সীমার ভিতরে — মালিকের সিদ্ধান্ত, ৩ অক্টোবর ২০২৬।
         *
         * মালিককে প্রশ্ন: সীমা ০, পুরনো বকেয়া আছে, নতুন বিলের পুরো টাকা দিলেন কিন্তু আগেরটা নয় — চলবে? উত্তর:
         * *"চলবে না — আগের বকেয়াও শোধ চাই"*। তাই মাপ: খাতার বকেয়া + আটকে থাকা + এই কাগজ − এখন গোনা টাকা ≤ সীমা।
         *   · এখনকার জমা আগের বকেয়াও কমায় — খাতায় সেটাই ঘটে (বাড়তি টাকা গ্রাহকের জমা হয়ে বসে)।
         *   · ডেমো S-0010 (Appel): বকেয়া ১২,০০০ + বিল ৩,৩১,৯৪৮ − জমা ৩,৫৬,০০০ = −১২,০৫২ ≤ ০ → চলে।
         *   · ⛔ আগে: জমা বিলের সমান হলেই সোজা ছাড় (`unpaid <= 0`), পুরনো বকেয়া যত বড়ই হোক — মালিক সেটা চাননি।
         */
        $unpaid = bcsub($adding, $payingNow, 4);

        /*
         * ⭐ ক্লিয়ার না হওয়া চেকও — [[check()]]-এর হুবহু ভাগ ([[unclearedCheques()]]), ৫ অক্টোবর ২০২৬।
         * ⛔ আগে কাউন্টার/চালান/বিলের দেয়াল এটা গুনত না, অথচ DO আর আদেশের যাচাই গুনত: হাতে আসার দিনেই জমায় বসা
         * ২,০০০-এর চেক কাউন্টারে ২,০০০ বেশি জায়গা দিত, আর একই গ্রাহকের DO আটকাত। এখন দুই পথ এক অঙ্ক বলে।
         */
        $pending = bcadd($this->pending($customer, $exceptInvoiceId, $exceptChallanId), $this->unclearedCheques($customer), 4);

        /*
         * ⛔ বাকি বন্ধ ([[stopsFor()]]) — সীমার আগে, ৫ অক্টোবর ২০২৬। এই কাগজে নতুন বাকি জন্মালে "না"; পুরো টাকা দিলে
         * (বা অগ্রিমে ঢাকলে) চলে। ⓘ আগেই গেট পেরোনো মালের বিল আটকায় না ([[billsGoodsAlreadyGone()]])।
         */
        if (bccomp($unpaid, '0', 4) > 0 && ! $this->billsGoodsAlreadyGone($exceptInvoiceId)) {
            $stop = $this->stopFor($customer);
            $after = bcadd(bcadd($customer->outstanding(), $pending, 4), $unpaid, 4);

            if ($stop !== null && bccomp($this->newCredit($unpaid, $after), '0', 4) > 0) {
                throw ValidationException::withMessages(['customer_id' => $stop]);
            }
        }

        if (! $customer->wouldExceedCreditLimit(bcadd($pending, $unpaid, 4))) {
            return;
        }

        $limit = (string) $customer->credit_limit;
        $used = bcadd($customer->outstanding(), $pending, 4);
        $left = bcsub($limit, $used, 4);
        $left = bccomp($left, '0', 4) > 0 ? $left : '0';
        // ⓘ কত বেশি = বিক্রির পরের মোট − সীমা; কম জমা দিলে ঠিক ততটা আরও দিলে চলে
        $short = bcsub(bcadd($used, $unpaid, 4), $limit === '' ? '0' : $limit, 4);

        /*
         * ⚠️ ঘরের নাম `customer_id` — বিলের পর্দাগুলো ঐ ঘরের বার্তা গ্রাহকের
         * ঘরের নিচেই দেখায়। ⛔ নতুন নামের ঘর (`credit_limit`) কোনো পর্দা
         * আঁকে না: বিল আটকাত, অথচ কারণটা কেউ দেখত না। ⓘ ধরা পড়েছে
         * [[ZeroMeansZeroOnTheDayYouSayTest]]-এ, ২৬ সেপ্টেম্বর ২০২৬।
         */
        throw ValidationException::withMessages([
            'customer_id' => __('sales::validation.over_credit_limit_hard', [
                'customer' => $customer->name(),
                'limit' => Money::format($limit),
                'left' => Money::format($left),
                'short' => Money::format($short),
            ]),
        ]);
    }

    /**
     * ⛔ একই দেয়াল, এবার গ্রাহকের সারিতে তালা দিয়ে — খাতায় লেখার লেনদেনের প্রথম কাজ।
     *
     * ── ⛔ কী খোলা ছিল, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * [[assertRoom()]] চলে লেনদেনের **বাইরে**, আর গ্রাহকের সারিতে কোনো তালা
     * ছিল না। দুই কাউন্টার একই গ্রাহককে একই মুহূর্তে বেচলে দুইজনেই পুরনো
     * ছবি দেখত — "জায়গা আছে" — আর দুইজনেই খাতায় লিখত। ⓘ প্রতিটা কাগজ একা
     * সীমার ভিতরে, মিলে বাইরে: কোথাও কিছু ভাঙত না, কেবল সীমাটা আর সীমা
     * থাকত না। ধরা পড়েছে [[TwoCountersSoldPastTheLimitTest]]-এ।
     *
     * ── ⭐ তাই দুই ধাপ ──────────────────────────────────────────────────
     *   ⓵ [[assertRoom()]] — আগে, তালা ছাড়া। ⚠️ ওটা থাকে, কারণ মালিকের
     *     ক্রম: সীমার "না" অনুমোদনের **আগে**; আর অনুমোদন অনুরোধের সারি
     *     লিখে থামে, তাই সেটা লেনদেনের বাইরে থাকতেই হয়।
     *   ⓶ এটা — লেনদেনের ভিতরে: গ্রাহকের সারিতে `FOR UPDATE`, তারপর **তাজা**
     *     সারি থেকে সীমা আর বকেয়া, আর একই হিসাব আবার। দ্বিতীয় কাউন্টার
     *     তালায় অপেক্ষা করে; প্রথমজন কমিট করলে নতুন অঙ্কটা দেখে, আর জায়গা
     *     না থাকলে ফিরে যায় — খাতায় কিছু না লিখে।
     *
     * ⚠️ হিসাবটা আলাদা করে লেখা নয় — তাজা সারি নিয়ে [[assertRoom()]]-ই ডাকা
     * হয়। ⛔ দুই জায়গায় দুই হিসাব থাকলে একদিন আগের ধাপ "হ্যাঁ" আর পরের ধাপ
     * "না" বলত একই কাগজে। ⓘ ছাড়গুলোও (`exceptInvoiceId`, `exceptChallanId`)
     * হুবহু আগের ধাপের মতো দিতে হয় — নইলে ধরে রাখা বিক্রয় দুইবার গোনা হত।
     *
     * ⚠️ তালাটা **লেনদেনের প্রথম পড়া** হওয়া চাই। InnoDB (REPEATABLE READ)
     * লেনদেনের প্রথম সাধারণ SELECT-এ একটা ছবি তোলে, আর পরের সাধারণ পড়াগুলো
     * ঐ ছবিই দেখে — তালা পাওয়ার পরেও। ⓘ তাই সুইচের প্রশ্নটাও (সেটিংস পড়ে)
     * তালার **পরে**, [[assertRoom()]]-এর ভিতরে। ⛔ বাইরের কোনো লেনদেন আগেই
     * কিছু পড়ে থাকলে (যেমন [[DirectSaleService::complete()]], [[PosService]])
     * ছবিটা পুরনো — তখন বাইরের পক্ষকেই তার লেনদেনের শুরুতে [[lockCustomer()]]
     * ডাকতে হয়।
     */
    public function assertRoomLocked(
        Customer $customer,
        string $adding,
        string $payingNow = '0',
        ?int $exceptInvoiceId = null,
        ?int $exceptChallanId = null,
    ): void {
        $fresh = $this->lockCustomer($customer);

        $this->readLatest = true;

        try {
            $this->assertRoom($fresh, $adding, $payingNow, $exceptInvoiceId, $exceptChallanId);
        } finally {
            $this->readLatest = false;
        }
    }

    /**
     * গ্রাহকের সারিতে তালা — আর সেই তাজা সারিটাই ফেরত।
     *
     * ⛔ লেনদেনের বাইরে ডাকলে ব্যতিক্রম: তখন `FOR UPDATE`-এর তালা কোয়েরি
     * শেষ হতেই খুলে যায় — পাহারাটা দেখতে থাকত, অথচ কিছুই আটকাত না।
     *
     * ⓘ গ্লোবাল স্কোপ ছাড়া: সারিটা চাবি ধরে একটাই, আর হাতের গ্রাহক আগেই
     * কোম্পানির ছাঁকনি পার হয়ে এসেছে। ⚠️ ফেরত সারিতে `outstanding_net` নেই,
     * তাই বকেয়াও তালার পরে খাতা থেকে নতুন করে পড়া হয় — তালিকা থেকে আসা
     * গ্রাহকের পুরনো অঙ্ক নয়।
     */
    public function lockCustomer(Customer|int $customer): Customer
    {
        /*
         * ⓘ আইডিও চলে — বাইরের লেনদেনে `$invoice->customer` লিখলে সেটা নিজেই
         * একটা সাধারণ SELECT, আর তাতে ছবিটা তালার **আগেই** উঠে যেত।
         */
        $key = $customer instanceof Customer ? $customer->getKey() : $customer;

        if (DB::transactionLevel() === 0) {
            throw new \LogicException('CreditExposure::lockCustomer() must run inside the posting transaction.');
        }

        $fresh = Customer::query()
            ->withoutGlobalScopes()
            ->whereKey($key)
            ->lockForUpdate()
            ->first();

        if ($fresh === null) {
            throw new \LogicException("Customer {$key} vanished before its credit could be locked.");
        }

        /*
         * ⓘ বকেয়াও তালাসহ পড়ায় — [[Customer::outstanding()]]-এর একই খাতা-নিয়ম
         * (`forParty('customer', …)`), কেবল `sharedLock()`। মডেলে বসানো থাকলে
         * `outstanding()` নিজে আর গোনে না, তাই হিসাবটা এক জায়গারই থাকে।
         */
        $fresh->setAttribute('outstanding_net', LedgerEntry::query()
            ->forParty('customer', $fresh->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->sharedLock()
            ->value('net') ?? 0);

        return $fresh;
    }

    /**
     * নিশ্চিত চালানের যে সারিগুলো এখনো কোনো বিলে ঢোকেনি।
     *
     * ⓘ বাতিল বিল গোনা হয় না — বাতিল হলে মালটা আবার "বিল না হওয়া"।
     * ⓘ মোছা (soft-deleted) বিল বা চালানও বাদ।
     */
    private function unbilledChallans(Customer $customer): string
    {
        $billed = DB::table('sal_invoice_lines as il')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->whereColumn('il.delivery_challan_line_id', 'cl.id')
            ->whereNull('i.deleted_at')
            ->where('i.status', '!=', DocumentStatus::CANCELLED);

        $sum = DB::table('sal_challan_lines as cl')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->where('c.customer_id', $customer->id)
            ->where('c.company_id', $customer->company_id)
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', DocumentStatus::POSTED)
            ->whereNotExists($billed)
            ->when($this->readLatest, fn ($q) => $q->sharedLock())
            ->sum('cl.amount');

        return bcadd((string) $sum, '0', 4);
    }

    /** খসড়া বিলের মোট — নিজেকে আর নিজের চালানের বিলকে বাদ দিয়ে। */
    private function draftInvoices(Customer $customer, ?int $exceptInvoiceId, ?int $exceptChallanId): string
    {
        $query = DB::table('sal_invoices as i')
            ->where('i.customer_id', $customer->id)
            ->where('i.company_id', $customer->company_id)
            ->whereNull('i.deleted_at')
            ->where('i.status', DocumentStatus::DRAFT)
            // ⓘ নিষ্ক্রিয় খসড়া সীমা ধরে রাখে না ([[DirectSaleService::pauseDraft()]])
            ->whereNull('i.draft_paused_at');

        if ($exceptInvoiceId !== null) {
            $query->where('i.id', '!=', $exceptInvoiceId);
        }

        if ($exceptChallanId !== null) {
            $query->whereNotExists(fn ($q) => $q->from('sal_invoice_lines as il')
                ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
                ->whereColumn('il.sales_invoice_id', 'i.id')
                ->where('cl.delivery_challan_id', $exceptChallanId));
        }

        if ($this->readLatest) {
            $query->sharedLock();
        }

        return bcadd((string) $query->sum('i.total'), '0', 4);
    }
}
