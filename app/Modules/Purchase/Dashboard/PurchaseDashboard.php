<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use Illuminate\Support\Carbon;

/**
 * ক্রয় মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন "দেনা" সবচেয়ে বড় সংখ্যা ─────────────────────────────────────
 * বিক্রয়ে প্রশ্নটা "কত এলো"; ক্রয়ে প্রশ্নটা **"কত দিতে হবে"**। ওই
 * সংখ্যাটাই নগদের পরিকল্পনা ঠিক করে, আর সেটা না জানলে মাস শেষে
 * অপ্রত্যাশিত চাপ আসে।
 */
final class PurchaseDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $month = Carbon::today()->startOfMonth()->toDateString();

        return new DashboardDefinition(
            title: __('purchase::dashboard.title'),
            subtitle: __('purchase::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('purchase::action.new_bill'), href: route('purchase.bill.create'),
                    permission: 'purchase.bill.create', icon: 'purchase'),
                new Tile(label: __('purchase::action.new_order'), href: route('purchase.order.create'),
                    permission: 'purchase.order.create', icon: 'plus'),
                new Tile(label: __('purchase::menu.payments'), href: route('purchase.payment.index'),
                    permission: 'purchase.payment.view', icon: 'money'),
                new Tile(label: __('purchase::menu.bills'), href: route('purchase.bill.index'),
                    permission: 'purchase.bill.view', icon: 'report'),
            ],

            stats: [
                new Stat(
                    label: __('purchase::dashboard.payable'),
                    value: Money::format(PurchaseBill::query()->whereIn('status', DocumentStatus::POSTED)->sum('total')),
                    hint: __('purchase::dashboard.payable_hint'),
                    href: route('purchase.bill.index'),
                    tone: Stat::BAD,
                ),

                new Stat(
                    label: __('purchase::dashboard.bought_this_month'),
                    value: Money::format(PurchaseBill::query()->whereIn('status', DocumentStatus::POSTED)
                        ->where('trx_date', '>=', $month)->sum('total')),
                    hint: __('purchase::dashboard.bought_hint'),
                    href: route('purchase.bill.index'),
                ),

                new Stat(
                    label: __('purchase::dashboard.paid_this_month'),
                    value: Money::format(Payment::query()->where('trx_date', '>=', $month)->sum('amount')),
                    hint: __('purchase::dashboard.paid_hint'),
                    href: route('purchase.payment.index'),
                    tone: Stat::GOOD,
                ),

                /*
                 * ── কেন অপেক্ষমাণ ক্রয়াদেশ আলাদা সংখ্যা ──────────────────
                 * অর্ডার দেওয়া হয়েছে কিন্তু মাল আসেনি — এটা দেনা নয়,
                 * **প্রতিশ্রুতি**। দুইটা মিলিয়ে দিলে দেনার সংখ্যাটা বড়
                 * দেখাত, আর নগদের পরিকল্পনা ভুল হত।
                 */
                new Stat(
                    label: __('purchase::dashboard.open_orders'),
                    value: (string) PurchaseOrder::query()->where('status', DocumentStatus::CONFIRMED)->count(),
                    hint: __('purchase::dashboard.open_orders_hint'),
                    href: route('purchase.order.index'),
                    tone: Stat::WARN,
                ),
            ],

            listings: [
                new Listing(
                    label: __('purchase::dashboard.biggest_payables'),
                    columns: [
                        ['key' => 'no', 'label' => __('purchase::field.document_no'),
                            'render' => fn ($b) => $b->document_no],
                        ['key' => 'party', 'label' => __('purchase::field.supplier'),
                            'render' => fn ($b) => $b->supplier?->name() ?? '—'],
                        ['key' => 'amount', 'label' => __('purchase::dashboard.payable'), 'width' => '9rem',
                            'render' => fn ($b) => Money::format($b->total)],
                    ],
                    rows: PurchaseBill::query()->whereIn('status', DocumentStatus::POSTED)
                        ->with('supplier')->orderByDesc('total')->limit(8)->get(),
                    empty: __('purchase::dashboard.nothing_payable'),
                    href: route('purchase.bill.index'),
                ),
            ],
        );
    }
}
