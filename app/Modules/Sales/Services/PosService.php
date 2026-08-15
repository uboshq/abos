<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
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
        $paid = $this->money($data['paid'] ?? '0');

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

        return DB::transaction(function () use ($data, $lines, $customer, $paid, $key) {
            $invoice = $this->invoices->create(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                    'narration' => $data['narration'] ?? null,
                ],
                $lines,
            );

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
                /*
                 * টাকাটা কীভাবে এল — নগদ, বিকাশ, নাকি কার্ড।
                 *
                 * উপায়টা বাছা না থাকলে নগদ, কারণ কাউন্টারে বেশিরভাগ
                 * বিক্রয় নগদেই আর প্রতিবার বাছতে বলা মানে দ্রুততা নষ্ট।
                 */
                $method = $this->paymentMethod($data['payment_method_id'] ?? null);

                $collection = $this->collections->create(
                    [
                        'customer_id' => $customer->id,
                        /*
                         * খাত না বললে কিছুই পাঠানো হয় না — আদায়ের সেবাই
                         * প্রধান নগদ কাউন্টার বেছে নেয়।
                         *
                         * আগে এখানে নিজের একটা ডিফল্ট ছিল, আর সেটা
                         * ছিল "হাতে নগদ" মাথাটা (১১০১) — গ্রুপ খাত।
                         * ওখানে বসানো টাকা কোনো ব্যালেন্সে দেখাত না।
                         * দুইটা জায়গায় দুইটা ডিফল্ট থাকলে একটা ভুল
                         * হলে অন্যটা দেখে ধরা যায় না।
                         */
                        'account_id' => $method?->account_id ?? ($data['account_id'] ?? null),
                        'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                        'amount' => $applied,
                        'instrument' => $method?->name(),
                        'instrument_no' => $this->reference($method, $data['reference'] ?? null),
                        'narration' => __('sales::message.pos_narration', ['no' => $invoice->document_no]),
                    ],
                    [['sales_invoice_id' => $invoice->id, 'amount' => $applied]],
                );

                $this->collections->confirm($collection);
            }

            return [
                'invoice' => $invoice->fresh(['lines']),
                'change' => $change,
            ];
        });
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
