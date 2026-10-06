<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * মালিকের পরিকল্পনা (সংস্করণ ২, ৪ অক্টোবর ২০২৬) §৯-এর তিন রিপোর্ট — খোলা আদেশ ও ব্যাক অর্ডার, সীমায় আটকানো আদেশ,
 * বিক্রয় খাতা (ইনভয়েস ধরে)।
 *
 * ⓘ আদেশ আজ ডেলিভারি অর্ডারে (DO) — "বিক্রয় আদেশ আর DO এক কাগজ" হলে প্রথম দুইটা সেই কাগজ ধরবে; বিক্রয় আদেশের
 * সারি-ধরা বাকি আগে থেকেই আছে ([[SalesReports::pendingOrders()]])।
 */
final class SalesOrderBookReports
{
    public const OPEN_ORDERS = 'sales.open_orders';

    public const CREDIT_BLOCKED = 'sales.credit_blocked';

    public const INVOICE_BOOK = 'sales.invoice_book';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::openOrders());
        $engine->register(self::creditBlocked());
        $engine->register(self::invoiceBook());
    }

    /**
     * খোলা আদেশ ও ব্যাক অর্ডার — বন্ধ নয় এমন DO-র প্রতিটা সারি: চাওয়া/অনুমোদিত, মজুদে ধরা, আর না-পাওয়া (ব্যাক অর্ডার)।
     * ⓘ ধরা মাল DO-র আটকানো মাল থেকে ([[DeliveryOrderStock]], `sal_do_stock_holds`) — ছাড়া না হওয়া আটকানো যোগ।
     */
    public static function openOrders(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::OPEN_ORDERS,
            permission: 'sales.report',
            title: 'sales::order_book.open_title',
            filters: ['date_range', 'branch', 'customer_id'],
            query: function (array $f): Builder {
                $held = '(select COALESCE(SUM(h.qty - h.consumed_qty), 0) from sal_do_stock_holds h'
                    .' where h.delivery_order_line_id = l.id and h.released_at is null)';
                $wanted = 'COALESCE(l.approved_qty, l.qty)';

                return DB::table('sal_delivery_order_lines as l')
                    ->join('sal_delivery_orders as o', 'o.id', '=', 'l.delivery_order_id')
                    ->join('customers as cu', 'cu.id', '=', 'o.customer_id')
                    ->join('inv_products as p', 'p.id', '=', 'l.product_id')
                    ->where('o.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 'o.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'o.customer_id'))
                    ->whereNull('o.deleted_at')
                    ->whereNotIn('o.status', DeliveryOrderStatus::CLOSED)
                    ->where('o.status', '<>', DeliveryOrderStatus::DRAFT)
                    ->whereBetween('o.trx_date', [$f['from'], $f['to']])
                    ->when($f['customer_id'] ?? null, fn ($q, $id) => $q->where('o.customer_id', (int) $id))
                    ->orderBy('o.trx_date')->orderBy('o.document_no')->orderBy('l.id')
                    ->select(['o.trx_date', 'o.document_no'])
                    ->selectRaw(self::label('o.status', fn (string $s) => DeliveryOrderStatus::label($s), [
                        DeliveryOrderStatus::SUBMITTED, DeliveryOrderStatus::SUPERVISOR_PENDING, DeliveryOrderStatus::SUPERVISOR_APPROVED,
                        DeliveryOrderStatus::ACCOUNTS_HELD, DeliveryOrderStatus::ACCOUNTS_APPROVED, DeliveryOrderStatus::DEPOT_CHECK,
                    ]).' as status')
                    ->selectRaw(self::name('cu').' as customer_name')
                    ->selectRaw("CONCAT(p.code, ' - ', ".self::name('p').') as product_name')
                    ->selectRaw("{$wanted} as wanted_qty, {$held} as held_qty")
                    ->selectRaw("GREATEST({$wanted} - {$held}, 0) as back_order_qty")
                    ->selectRaw('DATEDIFF(?, o.trx_date) as age_days', [now()->toDateString()]);
            },
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'document_no', 'label' => 'core.table.document', 'width' => '8rem'],
                ['key' => 'status', 'label' => 'sales::order_book.status', 'width' => '9rem'],
                ['key' => 'customer_name', 'label' => 'sales::field.customer'],
                ['key' => 'product_name', 'label' => 'sales::field.product'],
                ['key' => 'wanted_qty', 'label' => 'sales::order_book.wanted', 'type' => ReportColumn::QUANTITY],
                ['key' => 'held_qty', 'label' => 'sales::order_book.held', 'type' => ReportColumn::QUANTITY],
                ['key' => 'back_order_qty', 'label' => 'sales::order_book.back_order', 'type' => ReportColumn::QUANTITY],
                ['key' => 'age_days', 'label' => 'sales::order_book.age_days', 'width' => '5rem', 'total' => false],
            ],
        );
    }

    /**
     * সীমায় আটকানো আদেশ — বাকির সীমা পেরোনোয় হিসাবে আটকে থাকা DO: কত কম, কবে থেকে, কত দিন। ⓘ টাকা এলে নিজে আবার যাচাই
     * হয় ([[DeliveryOrderAccounts::recheckCustomer()]]); এখানে যা আছে তা "এখন" আটকে — তারিখের ছাঁকনি নেই।
     */
    public static function creditBlocked(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::CREDIT_BLOCKED,
            permission: 'sales.report',
            title: 'sales::order_book.blocked_title',
            filters: ['branch', 'customer_id'],
            /*
             * ⭐ দুই কাগজ এক তালিকায় — সমন্বয়ক, ৪ অক্টোবর ২০২৬ (DO বিক্রয় আদেশে মেশানো, ধাপ ৩)।
             *
             * ⓘ সুইচ (`sales.orders_replace_do`) বন্ধ কোম্পানিতে সীমায় আটকে থাকে DO (`accounts_held`); চালু কোম্পানিতে বিক্রয়
             * আদেশ (`credit_held`) — আর চালুর আগের আটকে থাকা DO নিজের নম্বরে শেষ হয়, তাই সেগুলোও থাকে। ⚠️ দুই অংশই সবসময়
             * পড়া হয়: সুইচ দেখে একটা লুকালে, সুইচ বদলের দিন আগের ধারার আটকে থাকা কাগজ তালিকা থেকে হারাত — অথচ টাকার
             * অপেক্ষা তখনো চলছে। ⓘ নতুন ধারার বাইরে `credit_held` আদেশ জন্মায়ই না, তাই এক কোম্পানির জন্য উত্তর তার নিজের ধারার।
             */
            query: function (array $f): Builder {
                $deliveryOrders = DB::table('sal_delivery_orders as o')
                    ->join('customers as cu', 'cu.id', '=', 'o.customer_id')
                    ->where('o.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 'o.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'o.customer_id'))
                    ->whereNull('o.deleted_at')
                    ->where('o.status', DeliveryOrderStatus::ACCOUNTS_HELD)
                    ->when($f['customer_id'] ?? null, fn ($q, $id) => $q->where('o.customer_id', (int) $id))
                    ->select(['o.trx_date', 'o.document_no', 'o.total', 'o.accounts_short', 'cu.credit_limit'])
                    ->selectRaw(self::name('cu').' as customer_name')
                    ->selectRaw('o.accounts_held_at as held_at')
                    ->selectRaw('DATE(o.accounts_held_at) as held_on')
                    ->selectRaw('DATEDIFF(?, DATE(o.accounts_held_at)) as days_held', [now()->toDateString()]);

                $salesOrders = DB::table('sal_orders as s')
                    ->join('customers as cu', 'cu.id', '=', 's.customer_id')
                    ->where('s.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 's.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 's.customer_id'))
                    ->whereNull('s.deleted_at')
                    ->where('s.status', SalesOrderStatus::CREDIT_HELD)
                    ->when($f['customer_id'] ?? null, fn ($q, $id) => $q->where('s.customer_id', (int) $id))
                    ->select(['s.trx_date', 's.document_no', 's.total', 's.credit_short as accounts_short', 'cu.credit_limit'])
                    ->selectRaw(self::name('cu').' as customer_name')
                    ->selectRaw('s.credit_held_at as held_at')
                    ->selectRaw('DATE(s.credit_held_at) as held_on')
                    ->selectRaw('DATEDIFF(?, DATE(s.credit_held_at)) as days_held', [now()->toDateString()]);

                // ⓘ পুরনোটা আগে — যার অপেক্ষা সবচেয়ে লম্বা
                return $deliveryOrders->unionAll($salesOrders)->orderBy('held_at');
            },
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'document_no', 'label' => 'core.table.document', 'width' => '8rem'],
                ['key' => 'customer_name', 'label' => 'sales::field.customer'],
                ['key' => 'total', 'label' => 'sales::field.total', 'type' => ReportColumn::MONEY],
                ['key' => 'credit_limit', 'label' => 'sales::order_book.limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'accounts_short', 'label' => 'sales::order_book.short', 'type' => ReportColumn::MONEY],
                ['key' => 'held_on', 'label' => 'sales::order_book.held_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                // ⓘ দিন একটা পরিমাণ — গোটা সংখ্যা, দশমিকের শূন্য ছাড়া ([[AQuantityShowedFourZerosTest]])
                ['key' => 'days_held', 'label' => 'sales::order_book.days_held', 'type' => ReportColumn::QUANTITY, 'width' => '5rem', 'total' => false],
            ],
        );
    }

    /**
     * বিক্রয় খাতা — ইনভয়েস ধরে এক সারি: মোট, ছাড়, ভ্যাট, আদায়, বাকি, অবস্থা; বাতিল হলে বাতিল-ইনভয়েসের নম্বর।
     * ⓘ বাকি = মোট − আদায় − রসিদ − পাকা ফেরত ([[SalesInvoice::scopeWithCollected()]]-এর একই তিন ভাগ); বাতিল ইনভয়েসের বাকি ০।
     */
    public static function invoiceBook(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::INVOICE_BOOK,
            permission: 'sales.report',
            title: 'sales::order_book.invoice_title',
            filters: ['date_range', 'branch', 'customer_id'],
            query: function (array $f): Builder {
                $bills = SalesInvoice::query()->withCollected()->toBase()
                    ->where('sal_invoices.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 'sal_invoices.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'sal_invoices.customer_id'))
                    ->whereIn('sal_invoices.status', [...DocumentStatus::POSTED, DocumentStatus::CANCELLED])
                    ->whereBetween('sal_invoices.trx_date', [$f['from'], $f['to']])
                    ->when($f['customer_id'] ?? null, fn ($q, $id) => $q->where('sal_invoices.customer_id', (int) $id));

                $cancelled = DB::getPdo()->quote(DocumentStatus::CANCELLED);
                $paid = '(i.collected_total + i.voucher_total)';

                return DB::query()
                    ->fromSub($bills, 'i')
                    ->leftJoin('customers as cu', 'cu.id', '=', 'i.customer_id')
                    ->leftJoin('sal_invoice_cancellations as x', fn ($j) => $j->on('x.sales_invoice_id', '=', 'i.id')->where('x.status', DocumentStatus::CONFIRMED))
                    ->orderBy('i.trx_date')->orderBy('i.document_no')
                    ->select(['i.id', 'i.trx_date', 'i.document_no', 'x.document_no as cxl_no'])
                    ->selectRaw(self::label('i.status', fn (string $s) => DocumentStatus::label($s), [...DocumentStatus::POSTED, DocumentStatus::CANCELLED]).' as status')
                    ->selectRaw("'".SalesInvoice::drillSourceType()."' as source_type_literal")
                    ->selectRaw(self::name('cu').' as customer_name')
                    ->selectRaw('i.subtotal, COALESCE(i.discount, 0) + COALESCE(i.bill_discount, 0) as discount, COALESCE(i.tax, 0) as tax, i.total')
                    ->selectRaw("CASE WHEN i.status = {$cancelled} THEN 0 ELSE {$paid} END as paid")
                    ->selectRaw("CASE WHEN i.status = {$cancelled} THEN 0 ELSE GREATEST(i.total - {$paid} - i.returned_total, 0) END as due");
            },
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'document_no', 'label' => 'core.table.document', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal', 'source_id' => 'id'],
                ['key' => 'customer_name', 'label' => 'sales::field.customer'],
                ['key' => 'subtotal', 'label' => 'sales::field.subtotal', 'type' => ReportColumn::MONEY],
                ['key' => 'discount', 'label' => 'sales::field.discount', 'type' => ReportColumn::MONEY],
                ['key' => 'tax', 'label' => 'sales::field.tax', 'type' => ReportColumn::MONEY],
                ['key' => 'total', 'label' => 'sales::field.total', 'type' => ReportColumn::MONEY],
                ['key' => 'paid', 'label' => 'sales::field.collected', 'type' => ReportColumn::MONEY],
                ['key' => 'due', 'label' => 'sales::field.due', 'type' => ReportColumn::MONEY],
                ['key' => 'status', 'label' => 'sales::order_book.status', 'width' => '7rem'],
                ['key' => 'cxl_no', 'label' => 'sales::cancellation.title', 'width' => '8rem'],
            ],
        );
    }

    /**
     * অবস্থার নাম, কাঁচা চাবি নয় — CASE-এ, যাতে পর্দা, ছাপা আর ফাইল একই লেখা পায়।
     *
     * @param  callable(string): string  $label
     * @param  list<string>  $statuses
     */
    private static function label(string $column, callable $label, array $statuses): string
    {
        $pdo = DB::getPdo();
        $cases = implode(' ', array_map(fn (string $s) => 'WHEN '.$pdo->quote($s).' THEN '.$pdo->quote((string) $label($s)), $statuses));

        return "CASE {$column} {$cases} ELSE {$column} END";
    }

    private static function name(string $alias): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)"
            : "{$alias}.name_en";
    }
}
