<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\ParkedStockReservation;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * কাউন্টারের বিক্রি — এক চাপে বিল, স্টক ও টাকা।
 *
 * ── এটা সমান্তরাল কোনো পথ নয় ─────────────────────────────────────────
 * POS নিজে কোনো নিয়ম জানে না। ভেতরে সে SalesInvoiceService ও
 * CollectionService-ই ডাকে — অর্থাৎ ধারের সীমা, স্টকের অঙ্ক, খরচের
 * দাখিলা, নম্বর সিরিজ, সবই একই কোড।
 *
 * আলাদা করে লিখলে দুইটা পথ হত: কাউন্টারের বিক্রিতে বিক্রীত পণ্যের ব্যয়
 * বসত না, বা স্টক নামত না — আর অমিলটা ধরা পড়ত মাস শেষে, যখন কেউ বলত
 * "দোকানে যা বেচলাম, খাতায় তার চেয়ে কম"। POS শুধু দ্রুত একটা মুখ, নতুন
 * কোনো হিসাব নয়।
 *
 * ── কেন বিল আর আদায় দুইটাই ───────────────────────────────────────────
 * নগদ বিক্রিতেও দুইটা ঘটনা ঘটে: মাল গেল (আয় ও পাওনা), আর টাকা এল (পাওনা
 * শোধ)। একটা দাখিলায় সরাসরি Dr নগদ / Cr বিক্রয় লিখলে দ্রুত হত, কিন্তু
 * তখন "এই গ্রাহক আজ কত কিনেছেন" প্রশ্নের উত্তর প্রাপ্য খাতায় থাকত না,
 * আর আংশিক নগদ-আংশিক বাকি বিক্রি লেখাই যেত না।
 */
final class PosService
{
    public function __construct(
        private readonly SalesInvoiceService $invoices,
        private readonly CollectionService $collections,
        private readonly SettingsService $settings,
        private readonly SalesReturnService $returns,
        private readonly VoucherService $vouchers,
        private readonly CashTillService $tills,
        private readonly CounterApproval $counter,
        private readonly AuditEngine $audit,
    ) {}

    /**
     * কার্টটা ধরে রাখা — ক্রেতা টাকা আনতে গেছেন।
     *
     * খসড়াই থাকে: মাল নড়ে না, টাকা ওঠে না, খাতায় কিছু বসে না। কেবল
     * `parked_at` বসে, যাতে টিলের পর্দা জানে এটা কাউন্টারে ঝুলে আছে,
     * কোনো ভুলে ফেলে রাখা খসড়া নয়।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function park(array $data, array $lines): SalesInvoice
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        $customer = $this->resolveCustomer($data['customer_id'] ?? null);

        return DB::transaction(function () use ($data, $lines, $customer) {
            $invoice = $this->invoices->create(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                    'narration' => $data['narration'] ?? null,
                ],
                $lines,
            );

            $invoice->forceFill(['parked_at' => now()])->save();

            /*
             * ⚠️ মাল আটকানো — মালিকের শর্ত, ৩ সেপ্টেম্বর ২০২৬।
             *
             * এটা না থাকলে দুইটা কাউন্টার একই শেষ কার্টনটা একই সাথে
             * বিক্রি করতে পারত: একজনের বিল ঝুলে আছে, অন্যজন বেচে
             * দিলেন, আর প্রথমজন ফিরে এসে দেখেন মাল নেই।
             *
             * ⓘ `Reserved`, `Hold` নয় — কারণ [[ParkedStockReservation]]-এ
             * লেখা। দুইটাই `available` থেকে বাদ যায়, কিন্তু `Hold`
             * একটা কারণ-কোড দাবি করে আর ধরে রাখা বিল কোনো "কারণ" নয়।
             */
            app(ParkedStockReservation::class)->reserve($invoice->fresh(['lines.product'])); 

            return $invoice->fresh(['lines']);
        });
    }

    /**
     * কাউন্টারে যা যা ঝুলে আছে — পুরনোটা আগে।
     *
     * পুরনো আগে, কারণ যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই সবচেয়ে
     * সম্ভাব্য পরিত্যক্ত — আর দিন শেষে ওটাই আগে সিদ্ধান্ত চায়।
     *
     * @return Collection<int, SalesInvoice>
     */
    public function parked()
    {
        return SalesInvoice::query()
            ->whereNotNull('parked_at')
            ->where('status', DocumentStatus::DRAFT)
            ->with('lines')
            ->orderBy('parked_at')
            ->get();
    }

    /**
     * ধরে রাখা বিলটা আবার কাউন্টারে তোলা।
     *
     * ── কেন `parked_at` মুছে দেওয়া হয় ───────────────────────────────
     * তোলার পর ওটা আর অপেক্ষা করছে না — ক্যাশিয়ারের সামনে আছে। মুছে না
     * দিলে একই বিল দুই কাউন্টারে একসাথে তোলা যেত, আর একজন নিশ্চিত করার
     * পর অন্যজন খালি পর্দা নিয়ে বসে থাকতেন।
     */
    public function resume(SalesInvoice $invoice): SalesInvoice
    {
        if ($invoice->parked_at === null) {
            throw ValidationException::withMessages([
                'invoice' => __('sales::validation.not_parked'),
            ]);
        }

        if ($invoice->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'invoice' => __('sales::validation.already_done', ['no' => $invoice->document_no]),
            ]);
        }

        /*
         * ⓘ আটকানো **বহাল থাকে** — মালিকের সিদ্ধান্ত: মাল ছাড়া পায়
         * কেবল বিলটা বাতিল বা নিশ্চিত হলে ("যতক্ষণ না cancel করছি")।
         *
         * ফিরিয়ে আনা মানে কেবল বিলটা আবার সম্পাদনাযোগ্য হওয়া; ক্রেতা
         * তখনো কাউন্টারে দাঁড়িয়ে, আর মালটা তাঁরই জন্য রাখা।
         */
        $invoice->forceFill(['parked_at' => null])->save();

        return $invoice->fresh(['lines']);
    }

    /**
     * একটা কাউন্টার বিক্রি সম্পূর্ণ করা।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @return array{invoice: SalesInvoice, change: string}
     */
    public function checkout(array $data, array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        $customer = $this->resolveCustomer($data['customer_id'] ?? null);

        /*
         * ভাগ করে দিলে "যা দিলেন" ভাগগুলোর যোগফলই।
         *
         * ── কেন এখানে গোনা হয়, পর্দার পাঠানো সংখ্যা নয় ──────────────
         * পর্দা যোগফলটা পাঠাতে পারত, কিন্তু তখন দুইটা সংখ্যা থাকত যারা
         * আলাদা হতে পারে — আর ওই তফাতটা মানে হয় গ্রাহকের নামে না-দেওয়া
         * জমা, নয় ক্যাশিয়ারের হাতে বেহিসাবি টাকা।
         *
         * `paid` তবু মিলিয়ে দেখা হয় (`split()`), কারণ পর্দাটা যদি
         * অন্য যোগফল দেখিয়ে থাকে তবে ক্রেতাকেও ওই সংখ্যাটাই বলা
         * হয়েছে, আর সেটা নীরবে বদলে দেওয়া চলে না।
         */
        $paid = $this->paidTotal($data);

        /*
         * টিলের পাঠানো চাবি — এক কার্টে একটা।
         *
         * একই চাবি আবার এলে সেটা একই বিক্রয়: ধীর সংযোগ আর দ্বিতীয়বার
         * Enter, দ্বিতীয় ক্রেতা নয়। আগেরটা খুঁজে দিয়ে দেওয়া হয়, নতুন
         * করে কিছু বসে না।
         *
         * চাবি না এলে কিছুই বদলায় না — পুরনো টিল, ইমপোর্ট বা পরীক্ষার
         * কোড যেভাবে চলত সেভাবেই চলে।
         */
        $key = trim((string) ($data['idempotency_key'] ?? '')) ?: null;

        if ($key !== null && ($already = $this->soldUnder($key)) !== null) {
            return $this->result($already, $paid);
        }

        /*
         * খসড়াটা নিজের লেনদেনে বসে, আর সেখানেই থেমে যায় — ইচ্ছাকৃত।
         *
         * ── কী ভেঙেছিল ─────────────────────────────────────────────
         * আগে তৈরি ও নিশ্চিত করা একই লেনদেনে ছিল। `SalesInvoiceService`
         * ছাড়ের পাহারাটা **ইচ্ছাকৃতভাবে লেনদেনের বাইরে** ডাকে, যাতে
         * ব্যতিক্রম ছুঁড়লেও অনুমোদনের অনুরোধটা থেকে যায় — কোডে মন্তব্য
         * করে কারণটাও লেখা। কিন্তু কাউন্টার ওই দুইটাকে **নিজের** আরেকটা
         * লেনদেনে মুড়ে রাখত, তাই সাবধানতাটা এক স্তর উপরে এসে অকেজো হয়ে
         * যেত: অনুরোধের সারি রোল-ব্যাক, খসড়া বিলও রোল-ব্যাক।
         *
         * ফল ছিল একটা বন্ধ দরজা — ক্যাশিয়ার "ছাড় অনুমোদনের অপেক্ষায়"
         * বার্তা পেতেন, মালিকের তালিকা ফাঁকাই থাকত, আর সীমা ছাড়ানো
         * ছাড়ের বিক্রয়টা কোনোদিন হত না।
         */
        $invoice = DB::transaction(function () use ($data, $lines, $customer, $key) {
            $head = [
                'customer_id' => $customer->id,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                'narration' => $data['narration'] ?? null,
            ];

            /*
             * তোলা বিলটা থাকলে সেটাই সম্পূর্ণ হয় — নতুন একটা নয়।
             *
             * ── কী ভেঙেছিল ─────────────────────────────────────────
             * `resume()` কেবল কার্টটা পর্দায় ফিরিয়ে দিত, আর "সম্পূর্ণ"
             * চাপলে কাউন্টার **আরেকটা নতুন খসড়া** বানাত। ফলে প্রতিটা
             * ধরে-রাখা-তারপর-বেচা চক্রে খাতায় একটা মরা খসড়া থেকে যেত,
             * আর তার নম্বরটাও খরচ হয়ে যেত — বিলের তালিকায় ফাঁক।
             *
             * ছাড়ের অনুমোদনেও এটাই লাগে: অনুরোধটা বসে একটা নির্দিষ্ট
             * কাগজের গায়ে। নতুন খসড়া বানালে ম্যানেজারের দেওয়া সম্মতি
             * পুরনো কাগজে পড়ে থাকত, আর নতুনটা আবার অনুমোদন চাইত।
             */
            $resumed = $this->draftToFinish($data['resumed_invoice_id'] ?? null);

            $invoice = $resumed !== null
                ? $this->invoices->update($resumed, $head, $lines)
                : $this->invoices->create($head, $lines);

            /*
             * চাবিটা বিলের গায়ে বসে, আর ইনডেক্সই আসল পাহারা।
             *
             * উপরে খুঁজে দেখা সাধারণ দুইবার-চাপা সামলায়। কিন্তু দুইটা
             * অনুরোধ কাছাকাছি এলে দুইজনেই খুঁজে কিছু পায় না, আর দুইজনেই
             * বসায় — ওখানে ইউনিক ইনডেক্স দ্বিতীয়টাকে ফিরিয়ে দেয়।
             */
            if ($key !== null) {
                $invoice->forceFill(['idempotency_key' => $key])->save();
            }

            return $invoice;
        });

        /*
         * ছাড়ের পাহারা — কোনো লেনদেনের ভেতরে নয়।
         *
         * এখানে আটকালে খসড়াটা খাতায় থেকে যায় আর অনুরোধটা ম্যানেজারের
         * তালিকায় ওঠে। অনুমোদনের পর ক্যাশিয়ার বিলটা তুলে (`resume`)
         * আবার বেচতে পারেন — কার্ট নতুন করে টাইপ করতে হয় না।
         */
        try {
            $this->invoices->assertDiscountApproved($invoice);
        } catch (ValidationException $blocked) {
            /*
             * ম্যানেজার পাশে দাঁড়িয়ে থাকলে এখানেই মিটে যায়।
             *
             * প্রথম ডাকটাই অনুরোধের সারিটা তৈরি করে — তাই অনুমোদনের
             * আগে ওটা ডাকা লাগেই। ম্যানেজার সম্মতি দিলে দ্বিতীয় ডাকটা
             * নিঃশব্দে পার হয়ে যায়, আর ক্রেতাকে অপেক্ষা করতে হয় না।
             *
             * পরিচয় না দিলে আগের আচরণই — বিলটা খসড়া হয়ে অপেক্ষা করে।
             */
            if (! $this->approveAtCounter($data, $invoice)) {
                /*
                 * ম্যানেজার পাশে নেই — বিলটা কাউন্টারে ঝুলিয়ে রাখা হয়।
                 *
                 * না ঝুলালে খসড়াটা থাকত ঠিকই, কিন্তু কাউন্টারের পর্দায়
                 * তার কোনো চিহ্ন থাকত না — ক্যাশিয়ার ভাবতেন সব হারিয়ে
                 * গেছে আর পুরো কার্ট আবার টাইপ করতেন। ঝুলানো তালিকায়
                 * উঠলে অনুমোদনের পর "তুলুন" চেপেই শেষ করা যায়।
                 */
                $invoice->forceFill(['parked_at' => now()])->save();

                throw $blocked;
            }

            $this->invoices->assertDiscountApproved($invoice);
        }

        return DB::transaction(function () use ($data, $customer, $paid, $invoice) {
            $invoice = $this->invoices->confirm($invoice);

            $total = (string) $invoice->total;

            /*
             * ফেরত টাকা — যা দিয়েছেন তার চেয়ে বিল কম হলে।
             *
             * আদায়ে বসে কেবল বিলের সমান বা তার কম, ফেরতটা নয়। ফেরতটা
             * খাতায় বসালে গ্রাহকের নামে এমন জমা দেখাত যা তিনি রাখেননি,
             * আর ক্যাশ টিলে টাকাটা দুইবার গোনা হত।
             */
            $applied = bccomp($paid, $total, 4) > 0 ? $total : $paid;
            $change = bccomp($paid, $total, 4) > 0 ? bcsub($paid, $total, 4) : '0.0000';

            if (bccomp($applied, '0', 4) > 0) {
                $this->takeMoney($data, $invoice, $customer, $applied);
            }

            return [
                'invoice' => $invoice->fresh(['lines']),
                'change' => $change,
            ];
        });
    }

    /**
     * টাকাটা নেওয়া — এক উপায়ে, বা কয়েকটায় ভাগ করে।
     *
     * ── কেন ভাগ করে নেওয়া লাগে ──────────────────────────────────────
     * ২,৩০০ টাকার বিলে ক্রেতা ২,০০০ বিকাশে পাঠিয়ে বাকি ৩০০ নগদে দেন —
     * বাংলাদেশে রোজকার ঘটনা, কারণ বিকাশের ব্যালেন্স গোল অঙ্কে থাকে।
     * এক উপায়ে বাধ্য করলে ক্যাশিয়ার পুরোটা "নগদ" লিখে দিতেন, আর
     * দিনশেষে ড্রয়ারে ২,০০০ কম পড়ত — ঠিক সেই মিথ্যা ঘাটতি, যেটা
     * সারাতেই উপায়ের তালিকাটা বানানো হয়েছিল।
     *
     * ── কেন প্রতিটা ভাগ আলাদা আদায় ──────────────────────────────────
     * একটা আদায়ের একটাই খাত, আর সেটাই ঠিক: ২,০০০ বিকাশের খাতে আর ৩০০
     * নগদের খাতে যাওয়ার কথা। এক সারিতে দুই খাতের টাকা লিখলে খাত ধরে
     * ব্যালেন্স মেলানোর উপায়ই থাকত না।
     *
     * ── পুরনো পথটা ভাঙে না ──────────────────────────────────────────
     * `payments` না এলে আগের মতোই একটা উপায় (বা নগদ)। টিল, ইমপোর্ট,
     * পুরনো পরীক্ষা — সবাই যেভাবে ডাকত সেভাবেই ডাকে।
     *
     * @param  array<string, mixed>  $data
     */
    private function takeMoney(array $data, SalesInvoice $invoice, Customer $customer, string $applied): void
    {
        foreach ($this->split($data, $applied) as $part) {
            if (bccomp($part['amount'], '0', 4) <= 0) {
                continue;
            }

            $method = $part['method'];

            $collection = $this->collections->create(
                [
                    'customer_id' => $customer->id,
                    /*
                     * খাত না বললে কিছুই পাঠানো হয় না — আদায়ের সেবাই
                     * প্রধান নগদ কাউন্টার বেছে নেয়।
                     *
                     * আগে এখানে নিজের একটা ডিফল্ট ছিল, আর সেটা ছিল
                     * "হাতে নগদ" মাথাটা (১১০১) — গ্রুপ খাত। ওখানে
                     * বসানো টাকা কোনো ব্যালেন্সে দেখাত না।
                     */
                    'account_id' => $method?->account_id ?? ($data['account_id'] ?? null),
                    'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                    'amount' => $part['amount'],
                    'instrument' => $method?->name(),
                    'instrument_no' => $this->reference($method, $part['reference']),
                    'narration' => __('sales::message.pos_narration', ['no' => $invoice->document_no]),
                ],
                [['sales_invoice_id' => $invoice->id, 'amount' => $part['amount']]],
            );

            $this->collections->confirm($collection);
        }
    }

    /**
     * কাউন্টারে বিল ধরে ফেরত নেওয়া।
     *
     * ── কেন কাউন্টার থেকেই ─────────────────────────────────────────
     * ক্রেতা দোকানে দাঁড়িয়ে আছেন, হাতে বিল আর মাল। ফেরতের জন্য অফিসের
     * পর্দায় যেতে বললে ক্যাশিয়ার হয় লাইন থামিয়ে রাখেন, নয় "পরে করব"
     * বলে কাগজে টুকে রাখেন — আর ওই কাগজটা রাতে হারায়। মালটা তখন
     * গুদামে ফেরে না, খাতায় বিক্রয়ই থেকে যায়।
     *
     * ── নতুন কোনো নিয়ম এখানে নেই ────────────────────────────────────
     * ভেতরে `SalesReturnService`-ই ডাকা হয়, তাই বিক্রীত পরিমাণের সীমা,
     * স্টকের অঙ্ক, ব্যয়-স্তরে ফেরত, খতিয়ানের দাখিলা — সবই একই কোড।
     * আলাদা করে লিখলে কাউন্টারের ফেরতে ব্যয়-স্তর ফিরত না, আর মাস শেষে
     * মুনাফা বেশি দেখাত।
     *
     * ── টাকা ফেরত ঐচ্ছিক, আর সেটাই বাস্তব ───────────────────────────
     * বাকিতে কেনা মাল ফেরত এলে টাকা যায় না, কেবল পাওনা কমে। নগদে
     * কেনা হলে ড্রয়ার থেকে টাকা ফেরত যায়, আর সেটা একটা পরিশোধ ভাউচার —
     * ছাপা যায়, অডিটে থাকে, আর টিলের গণনায় ধরা পড়ে।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @return array{return: SalesReturn, refund: ?Voucher}
     */
    public function takeBack(array $data, array $lines): array
    {
        $invoice = $this->soldOn($data['document_no'] ?? '');

        return DB::transaction(function () use ($data, $lines, $invoice) {
            $return = $this->returns->create([
                'customer_id' => $invoice->customer_id,
                'warehouse_id' => $data['warehouse_id'] ?? $invoice->warehouse_id,
                'sales_invoice_id' => $invoice->id,
                'reason_code_id' => $data['reason_code_id'] ?? null,
                'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                'narration' => __('sales::message.pos_return_narration', ['no' => $invoice->document_no]),
            ], $lines);

            $return = $this->returns->confirm($return);

            $refund = null;

            if (($data['refund'] ?? false) && bccomp((string) $return->total, '0', 4) > 0) {
                $refund = $this->refund($return);
            }

            return ['return' => $return, 'refund' => $refund];
        });
    }

    /**
     * নম্বর ধরে বিলটা খুঁজে বের করা।
     *
     * খসড়া বা বাতিল বিলের বিপরীতে ফেরত হয় না: খসড়ায় মাল বেরোয়ইনি, আর
     * বাতিলে সেটা ইতিমধ্যে ফেরত ধরা হয়েছে। দুইটার যেকোনোটায় ফেরত নিলে
     * গুদামে এমন মাল ঢুকত যা কোনোদিন বেরোয়নি।
     */
    public function soldOn(string $documentNo): SalesInvoice
    {
        $documentNo = trim($documentNo);

        $invoice = $documentNo === '' ? null : SalesInvoice::query()
            ->where('document_no', $documentNo)
            ->posted()
            ->with(['lines.product', 'customer'])
            ->first();

        if ($invoice === null) {
            throw ValidationException::withMessages([
                'document_no' => __('sales::validation.no_such_bill', ['no' => $documentNo]),
            ]);
        }

        return $invoice;
    }

    /**
     * এই লাইন থেকে আগে কত ফেরত গেছে।
     *
     * ক্যাশিয়ারকে জানাতে হয় আর কতটুকু বাকি — না জানালে তিনি পুরোটা
     * টাইপ করতেন আর সেবাটা আটকে দিত, ক্রেতার সামনে দাঁড়িয়ে।
     */
    public function alreadyReturned(SalesInvoiceLine $line): string
    {
        $sum = SalesReturnLine::query()
            ->where('sales_invoice_line_id', $line->id)
            ->whereHas('return', fn ($q) => $q->posted())
            ->sum('qty');

        return Money::quantity((string) ($sum ?: '0'));
    }

    /**
     * ড্রয়ার থেকে টাকা ফেরত — একটা পরিশোধ ভাউচার।
     *
     * ── কেন ভাউচার, ঋণাত্মক আদায় নয় ────────────────────────────────
     * ঋণাত্মক একটা আদায় লিখলে "আজ কত আদায় হয়েছে" সংখ্যাটা নীরবে কমে
     * যেত, অথচ ওই টাকাটা কেউ কোনোদিন দেয়ইনি। ভাউচার হলে দুইটা ঘটনা
     * দুইটা কাগজে থাকে — আর ফেরতের কাগজটা ছাপা যায়, যেটা ক্রেতা
     * চাইবেনই।
     */
    private function refund(SalesReturn $return): Voucher
    {
        $till = $this->tills->ensurePrimaryTill();

        $voucher = $this->vouchers->create([
            'type' => Voucher::PAYMENT,
            'trx_date' => $return->trx_date,
            'party_type' => 'customer',
            'party_id' => $return->customer_id,
            'narration' => __('sales::message.pos_refund_narration', ['no' => $return->document_no]),
        ], $this->vouchers->twoLineEntry(
            type: Voucher::PAYMENT,
            fromAccountId: $till->account_id,
            toAccountId: StandardChart::find(StandardChart::RECEIVABLE)->id,
            amount: (string) $return->total,
        ));

        return $this->vouchers->post($voucher);
    }

    /**
     * ক্রেতা মোট কত দিলেন।
     *
     * ভাগ করে দিলে ভাগগুলোর যোগফল; নাহলে `paid` ঘরটাই।
     *
     * @param  array<string, mixed>  $data
     */
    private function paidTotal(array $data): string
    {
        $payments = $data['payments'] ?? null;

        if (! is_array($payments) || $payments === []) {
            return $this->money($data['paid'] ?? '0');
        }

        $sum = '0';

        foreach ($payments as $payment) {
            $sum = bcadd($sum, $this->money($payment['amount'] ?? '0'), 4);
        }

        return $sum;
    }

    /**
     * টাকাটা কীভাবে ভাগ হলো — আর ফেরতটা কোন ভাগ থেকে যায়।
     *
     * ── কেন ভাগগুলোর যোগফল "যা দিলেন"-এর সমান হতে হয় ───────────────
     * বেশি হলে গ্রাহকের নামে এমন জমা বসত যা তিনি দেননি; কম হলে বিলটা
     * আংশিক শোধ দেখাত অথচ ক্যাশিয়ার পুরোটা নিয়েছেন। দুইটাই দিনশেষে
     * ধরা পড়ত, আর তখন কোন বিলে ভুল তা খুঁজে বের করা যেত না।
     *
     * ── ফেরত সবসময় নগদের ভাগ থেকে ──────────────────────────────────
     * ২,৩০০ টাকার বিলে ২,০০০ বিকাশে আর ৫০০ নগদে — ফেরত ২০০। ওই ২০০
     * ড্রয়ার থেকেই যায়, বিকাশ থেকে নয়: বিকাশে পাঠানো টাকা ফেরত দেওয়া
     * যায় না। সমান ভাগে কাটলে বিকাশের খাতে ১,৯১৩ বসত, অথচ ব্যাংকের
     * বিবরণীতে ২,০০০ — আর মাসের শেষে ওই তফাতটা কেউ মেলাতে পারত না।
     *
     * নগদের কোনো ভাগ না থাকলে বেশি টাকা নেওয়াই যায় না, কারণ ফেরত
     * দেওয়ার কিছু নেই।
     *
     * @param  array<string, mixed>  $data
     * @return list<array{method: ?PaymentMethod, amount: string, reference: ?string}>
     */
    private function split(array $data, string $applied): array
    {
        $payments = $data['payments'] ?? null;

        if (! is_array($payments) || $payments === []) {
            return [[
                'method' => $this->paymentMethod($data['payment_method_id'] ?? null),
                'amount' => $applied,
                'reference' => $data['reference'] ?? null,
            ]];
        }

        $parts = [];
        $paid = '0';

        foreach ($payments as $payment) {
            $amount = $this->money($payment['amount'] ?? '0');

            // শূন্যের সারি বাদ — পর্দায় একটা খালি ঘর থেকে গেলে সেটা
            // ইচ্ছা নয়, আর শূন্য টাকার আদায় খাতায় একটা অর্থহীন সারি
            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $parts[] = [
                'method' => $this->paymentMethod($payment['payment_method_id'] ?? null),
                'amount' => $amount,
                'reference' => $payment['reference'] ?? null,
            ];

            $paid = bcadd($paid, $amount, 4);
        }

        if ($parts === []) {
            throw ValidationException::withMessages(['payments' => __('sales::validation.no_payment_parts')]);
        }

        /*
         * পর্দা যদি নিজে একটা যোগফলও পাঠায়, সেটা মিলতে হবে।
         *
         * ঘরটা না এলে মিলিয়ে দেখার কিছু নেই — যোগফলটা ভাগগুলো থেকেই
         * গোনা হয়েছে। কিন্তু এলে সেটা ক্রেতাকে দেখানো সংখ্যা, আর সেটা
         * নীরবে বদলে দেওয়া চলে না।
         */
        $handed = trim((string) ($data['paid'] ?? ''));

        if ($handed !== '' && bccomp($paid, $this->money($handed), 4) !== 0) {
            throw ValidationException::withMessages([
                'payments' => __('sales::validation.split_does_not_add_up', [
                    'sum' => $paid,
                    'paid' => $this->money($handed),
                ]),
            ]);
        }

        $change = bcsub($paid, $applied, 4);

        if (bccomp($change, '0', 4) <= 0) {
            return $parts;
        }

        return $this->takeChangeFromCash($parts, $change);
    }

    /**
     * ফেরতটা নগদের ভাগ থেকে কেটে নেওয়া।
     *
     * @param  list<array{method: ?PaymentMethod, amount: string, reference: ?string}>  $parts
     * @return list<array{method: ?PaymentMethod, amount: string, reference: ?string}>
     */
    private function takeChangeFromCash(array $parts, string $change): array
    {
        foreach ($parts as $i => $part) {
            /*
             * উপায় না বলা মানে নগদ — `paymentMethod()` তাই null ফেরায়
             * আর আদায়ের সেবা প্রধান নগদ কাউন্টার বেছে নেয়। সেই
             * ভাগটাও এখানে নগদ হিসেবেই গোনা হয়।
             */
            $isCash = $part['method'] === null || $part['method']->isCash();

            if (! $isCash || bccomp($part['amount'], $change, 4) < 0) {
                continue;
            }

            $parts[$i]['amount'] = bcsub($part['amount'], $change, 4);

            return $parts;
        }

        throw ValidationException::withMessages([
            'payments' => __('sales::validation.change_needs_cash'),
        ]);
    }

    /**
     * এই চাবিতে আগেই কিছু বিক্রি হয়েছে কি না।
     *
     * খালি চাবিতে খোঁজা হয় না, ইচ্ছাকৃতভাবে: NULL খুঁজলে চাবিহীন
     * প্রথম বিলটাই উঠে আসত, আর টিলের হাতে অন্য কারও রসিদ চলে যেত।
     */
    private function soldUnder(string $key): ?SalesInvoice
    {
        return SalesInvoice::query()->where('idempotency_key', $key)->first();
    }

    /**
     * আগেই বসে যাওয়া বিক্রয়ের উত্তরটা আবার বানানো।
     *
     * ── ফেরত টাকা নতুন করে হিসাব হয়, সংরক্ষিত নয় ────────────────────
     * ক্রেতা যা দিয়েছিলেন সেটা এই অনুরোধেই এসেছে, আর বিলের মোট বিলেই
     * আছে — দুইটা থেকেই ফেরতটা বের হয়। আলাদা করে জমা রাখলে সেটা
     * তৃতীয় একটা সংখ্যা হত যা কোনোদিন যাচাই করা যেত না।
     *
     * @return array{invoice: SalesInvoice, change: string}
     */
    /**
     * পর্দা যে খসড়াটার কথা বলছে — যদি সেটা সত্যিই শেষ করার মতো হয়।
     *
     * নিশ্চিত হওয়া বা বাতিল বিল এখানে ফেরে না; ফিরলে ক্যাশিয়ার একই
     * বিলের টাকা দ্বিতীয়বার নিতেন। কোম্পানির স্কোপ মডেলেই বসানো, তাই
     * অন্য কোম্পানির নম্বর দিলে কিছুই পাওয়া যায় না।
     */
    private function draftToFinish(mixed $id): ?SalesInvoice
    {
        $id = (int) ($id ?: 0);

        if ($id <= 0) {
            return null;
        }

        return SalesInvoice::query()
            ->where('status', DocumentStatus::DRAFT)
            ->find($id);
    }

    /**
     * ম্যানেজারের নিজের লগইন এলে ছাড়টা এখানেই অনুমোদন হয়।
     *
     * ফেরত `false` মানে কেউ পরিচয় দেননি — তখন কিছুই বদলায় না আর
     * বিলটা আগের মতোই খসড়া হয়ে অনুমোদনের অপেক্ষা করে। পরিচয় ভুল হলে
     * `CounterApproval` নিজেই বার্তা ছুঁড়ে দেয়, কারণ ভুল পাসওয়ার্ড আর
     * "কেউ দেননি" এক জিনিস নয় — প্রথমটা ক্যাশিয়ারকে জানানো দরকার।
     *
     * @param  array<string, mixed>  $data
     */
    private function approveAtCounter(array $data, SalesInvoice $invoice): bool
    {
        $email = trim((string) ($data['approver_email'] ?? ''));
        $password = (string) ($data['approver_password'] ?? '');

        if ($email === '' || $password === '') {
            return false;
        }

        $approval = $this->counter->pending($invoice, 'discount');

        if ($approval === null) {
            return false;
        }

        $approver = $this->counter->decide(
            $approval,
            $email,
            $password,
            __('sales::validation.approved_at_counter', ['name' => auth()->user()?->name ?? '']),
        );

        /*
         * অডিটে বসে বিলের গায়ে, অনুমোদনের সারির পাশাপাশি।
         *
         * সারিটা বলে কে সম্মতি দিলেন। বিলের অডিট বলে **এই কাগজটার সাথে
         * কী ঘটেছিল** — আর বিল ধরে ইতিহাস দেখার সময় মানুষ ওটাই খোলেন।
         */
        $this->audit->recordAction(
            $invoice,
            'discount_approved',
            $approver->name,
        );

        return true;
    }

    private function result(SalesInvoice $invoice, string $paid): array
    {
        $total = (string) $invoice->total;

        return [
            'invoice' => $invoice->load('lines'),
            'change' => bccomp($paid, $total, 4) > 0 ? bcsub($paid, $total, 4) : '0.0000',
        ];
    }

    /**
     * কাউন্টারে যার নামে বিক্রি বসবে।
     *
     * কেউ না বাছলে সেটিংসে বসানো "নগদ গ্রাহক"। সেটাও না থাকলে থেমে যাওয়া
     * হয় — চুপচাপ প্রথম গ্রাহককে ধরে নিলে দিনের সব নগদ বিক্রি কোনো
     * একজন অচেনা মানুষের খাতায় জমা হত।
     */
    private function resolveCustomer(mixed $customerId): Customer
    {
        $id = (int) ($customerId ?: $this->settings->get('sales.walkin_customer_id', 0));

        $customer = $id > 0 ? Customer::query()->find($id) : null;

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => __('sales::validation.no_walkin_customer'),
            ]);
        }

        return $customer;
    }

    /**
     * বাছা উপায়টা — না বাছলে null, আর তখন নগদ ধরা হয়।
     *
     * নিষ্ক্রিয় উপায় বাছা যায় না: কেউ "কার্ড" বন্ধ করে দিলে সেটা আর
     * নতুন বিক্রয়ে আসা উচিত নয়, অথচ পুরনো বিক্রয়গুলোয় থেকে যায়।
     */
    private function paymentMethod(mixed $id): ?PaymentMethod
    {
        if (blank($id)) {
            return null;
        }

        $method = PaymentMethod::query()->active()->with('account')->find($id);

        if ($method === null) {
            throw ValidationException::withMessages([
                'payment_method_id' => __('sales::validation.unknown_payment_method'),
            ]);
        }

        return $method;
    }

    /**
     * লেনদেনের নম্বর — যে উপায়ে লাগে, কেবল সেখানে।
     *
     * ── কেন প্রতিটাতে নয় ────────────────────────────────────────────
     * নগদের কোনো TrxID নেই। প্রতিটা বিক্রয়ে জোর করে চাইলে ক্যাশিয়ার
     * `0` বসিয়ে এগিয়ে যেতেন, আর বানানো নম্বর কোনো নম্বর না থাকার
     * চেয়ে খারাপ: বিকাশের বিবরণীর সাথে মেলানোর সময় ওটা দেখে সবাই
     * ভাবে মিলে গেছে।
     *
     * ── কেন লাগলে ছাড় নেই ───────────────────────────────────────────
     * বিকাশে নেওয়া টাকা TrxID ছাড়া পরে মেলানোই যায় না। কাউন্টারে
     * ওটা লিখতে দুই সেকেন্ড; মাস শেষে খুঁজতে দুই দিন।
     */
    private function reference(?PaymentMethod $method, mixed $reference): ?string
    {
        $reference = trim((string) ($reference ?? ''));

        if ($method === null || ! $method->needs_reference) {
            return $reference === '' ? null : $reference;
        }

        if ($reference === '') {
            throw ValidationException::withMessages([
                'reference' => __('sales::validation.reference_required', ['method' => $method->name()]),
            ]);
        }

        return $reference;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '0.0000';
        }

        if (! is_numeric($value) || bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['paid' => __('sales::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    /**
     * আজ এই কাউন্টারে কত বিক্রি হয়েছে — পর্দার উপরে দেখানোর জন্য।
     *
     * ── কেন এখানে আর গোনা হয় না ─────────────────────────────────────
     * আগে এই পদ্ধতিটা নিজের কোয়েরি চালাত, আর সেটাই একবার হোম পর্দার
     * থেকে আলাদা উত্তর দিয়েছিল: এখানে খসড়াও গোনা হত। ক্রেতা টাকা
     * আনতে গেছেন, বিলটা কাউন্টারে ঝুলছে, অথচ ক্যাশিয়ারের ঘরে ওই
     * টাকাটা যোগ হয়ে বসে আছে — শিফট মেলানোর সময় হাতের নগদ কম পড়ত,
     * আর কেউ বুঝত না কেন।
     *
     * সংজ্ঞাটা এখন `SalesMetrics`-এ, একবার। সংখ্যাটা এখানে কেবল
     * চাওয়া হয়।
     */
    public function todaysTotal(): string
    {
        return SalesMetrics::salesTodayAtMyCounter()->value();
    }
}
