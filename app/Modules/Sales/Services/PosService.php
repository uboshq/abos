<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
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

        return DB::transaction(function () use ($data, $lines, $customer, $paid) {
            $invoice = $this->invoices->create(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                    'narration' => $data['narration'] ?? null,
                ],
                $lines,
            );

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
                $collection = $this->collections->create(
                    [
                        'customer_id' => $customer->id,
                        'account_id' => $data['account_id'] ?? $this->cashAccount()->id,
                        'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                        'amount' => $applied,
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

    private function cashAccount(): Account
    {
        $account = Account::query()->where('code', StandardChart::CASH_IN_HAND)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_id' => __('sales::validation.missing_account', ['code' => StandardChart::CASH_IN_HAND]),
            ]);
        }

        return $account;
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

    /** আজ এই কাউন্টারে কত বিক্রি হয়েছে — পর্দার উপরে দেখানোর জন্য। */
    public function todaysTotal(): string
    {
        $total = SalesInvoice::query()
            ->whereDate('trx_date', now()->toDateString())
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->where('created_by', auth()->id())
            ->sum('total');

        return (string) ($total ?: '0');
    }
}
