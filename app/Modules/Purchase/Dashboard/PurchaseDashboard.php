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
use App\Modules\Accounts\Services\AccountsFacts;
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
                /*
                 * ⭐ "নতুন বিল" নয়, "সরাসরি ক্রয়" — মালিক, ২১ সেপ্টেম্বর ২০২৬।
                 *
                 * ── ⛔ ১৯ সেপ্টেম্বরের সিদ্ধান্তটা এখানে পৌঁছায়নি ──────────
                 * সেদিন ক্রয় বিলের **তালিকা** থেকে "নতুন বিল" তুলে দেওয়া
                 * হয়েছিল, আর কারণটা লেখাও আছে: *বিল জন্মায় মাল গ্রহণে আর
                 * সরাসরি ক্রয়ে, আপনা থেকে*। ⚠️ কিন্তু ড্যাশবোর্ডের টালিটা
                 * রয়ে গিয়েছিল — এক পর্দায় দরজা বন্ধ, পাশের পর্দায় খোলা।
                 *
                 * ⓘ ফল রোজকার ভাষায়: এখান থেকে ঢুকলে একটা **খালি** বিল
                 * খুলত, যার পেছনে কোনো মাল গ্রহণ নেই — আর তখন মজুদ আর
                 * খাতা দুইটা আলাদা গল্প বলত।
                 *
                 * ⚠️ অনুমতিটা `purchase.bill.create`-ই থাকে, কারণ সরাসরি
                 * ক্রয়ও শেষে একটা বিলই জন্ম দেয় — [[DirectPurchaseController]]
                 * নিজেও ঠিক এই অনুমতিটাই দাবি করে।
                 */
                new Tile(label: __('purchase::menu.direct'), href: route('purchase.direct.create'),
                    permission: 'purchase.bill.create', icon: 'purchase'),
                new Tile(label: __('purchase::action.new_order'), href: route('purchase.order.create'),
                    permission: 'purchase.order.create', icon: 'plus'),
                new Tile(label: __('purchase::menu.payments'), href: route('purchase.payment.index'),
                    permission: 'purchase.payment.view', icon: 'cash'),
                new Tile(label: __('purchase::menu.bills'), href: route('purchase.bill.index'),
                    permission: 'purchase.bill.view', icon: 'reports'),
            ],

            stats: [
                /*
                 * ⭐ দেনা খাতা থেকে, বিলের যোগফল থেকে নয় — ২৭ সেপ্টেম্বর ২০২৬।
                 *
                 * ── ⛔ লাইভ QA-তে কী দেখা গেল ──────────────────────────────
                 * TCL-এ ৳৩,০০০ কেনা, ৳১,০০০ দেওয়া, ৳২০০ ফেরত — আসল দেনা
                 * ৳১,৮০০, অথচ এই ঘর বলছিল **৳৩,০০০**। ⓘ সংখ্যাটা নিশ্চিত
                 * বিলগুলোর `total` যোগ করত, আর বিলের মোট টাকা গেলেও কমে না,
                 * মাল ফেরত গেলেও না।
                 *
                 * ⚠️ হিসাবের ড্যাশবোর্ড, অর্থের ড্যাশবোর্ড আর CFO পাতা আগে
                 * থেকেই [[AccountsFacts::payable]] পড়ে — খাত ২১১১-এর জের।
                 * ⛔ এখানে নিজের `SUM` মানে একই প্রশ্নের দ্বিতীয় সংজ্ঞা, আর
                 * দুই পর্দায় দুই উত্তর। তাই সংজ্ঞা একটাই, ওখানেই।
                 *
                 * ⓘ পাহারা: [[ThePayableOnTheDashboardForgotThePaymentsTest]]
                 */
                new Stat(
                    label: __('purchase::dashboard.payable'),
                    value: Money::format(app(AccountsFacts::class)->payable()),
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
