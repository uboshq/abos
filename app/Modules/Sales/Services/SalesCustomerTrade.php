<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\CustomerTrade;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Panels\TradeGlance;
use App\Core\Support\ViewedBranch;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * গ্রাহকের এক নজরের ছবি — বিক্রয়ের দিক থেকে ([[CustomerTrade]])।
 *
 * ── ⛔ কোনো অঙ্ক দ্বিতীয় উপায়ে গোনা হয় না ───────────────────────────
 * ⓘ প্রতিটা সংখ্যা যে পর্দার সাথে মিলতে হবে, তার নিয়মই এখানে:
 *  • বিল গোনা [[SalesFacts]]-এর — কেবল `posted()`, খসড়া ও বাতিল বাদ।
 *  • বকেয়া বিল [[SalesInvoice::dueAmount()]]-এর — `মোট > আদায় + রসিদ
 *    ভাউচার`, আর আদায়ের দুইটা অঙ্ক [[SalesInvoice::scopeWithCollected()]]
 *    থেকে, নিজে লেখা নয় (বিলের তালিকাও ঠিক এভাবে গোনে)।
 *  • শেষ জমা **খাতা** থেকে — খাতায় যা বসেছে সেটাই টাকা আসা। ⚠️ চেক
 *    জমা পড়লেও খাতায় ওঠে কেবল ক্লিয়ার হলে (মালিক, ২৬ সেপ্টেম্বর), তাই
 *    কাগজের তারিখ ধরলে ঝুলে থাকা চেককে "শেষ জমা" বলা হত।
 */
final class SalesCustomerTrade implements CustomerTrade
{
    public function __construct(private readonly DrillResolver $drill) {}

    public function glanceFor(int $customerId, string $monthStart, string $today): ?TradeGlance
    {
        $posted = fn (): Builder => SalesInvoice::query()
            ->where('customer_id', $customerId)
            ->posted();

        $month = $posted()->whereBetween('trx_date', [$monthStart, $today])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS amount')
            ->toBase()->first();

        $lifetime = $posted()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS amount')
            ->toBase()->first();

        $pendingIds = $this->pendingInvoiceIds($posted());

        $last = $posted()->orderByDesc('trx_date')->orderByDesc('id')->first();

        $payment = $this->lastPayment($customerId);

        return new TradeGlance(
            monthCount: (int) ($month->n ?? 0),
            monthAmount: bcadd((string) ($month->amount ?? '0'), '0', 4),
            lifetimeCount: (int) ($lifetime->n ?? 0),
            lifetimeAmount: bcadd((string) ($lifetime->amount ?? '0'), '0', 4),
            pendingCount: count($pendingIds),
            pendingItems: $pendingIds === []
                ? 0
                : SalesInvoiceLine::query()->whereIn('sales_invoice_id', $pendingIds)->count(),
            lastPurchaseDate: $last?->trx_date?->toDateString(),
            lastPurchaseAmount: $last === null ? null : bcadd((string) $last->total, '0', 4),
            lastPurchaseUrl: $last === null ? null : route('sales.invoice.show', $last),
            lastPaymentDate: $payment['date'] ?? null,
            lastPaymentAmount: $payment['amount'] ?? null,
            lastPaymentUrl: $payment['url'] ?? null,
            invoicesUrl: route('sales.invoice.index', ['customer' => $customerId]),
        );
    }

    /**
     * যে বিলগুলোর এখনো কিছু পাওনা — না-দেওয়া আর আংশিক দেওয়া দুইটাই।
     *
     * ⓘ শর্তটা [[SalesInvoice::dueAmount()]]-এর হুবহু: `মোট − আদায় > ০`।
     * ⚠️ বিলের সাথে না বাঁধা জমা (অগ্রিম, হিসাবে জমা) কোনো বিলকে শোধ করে
     * না — বিলের পাতাও তাই বলে। মোট বকেয়া তবু খাতা থেকে আসে, তাই সেটা
     * ঠিকই কমে।
     *
     * @return list<int>
     */
    private function pendingInvoiceIds(Builder $posted): array
    {
        $perBill = SalesInvoice::query()
            ->whereIn('sal_invoices.id', $posted->select('sal_invoices.id'))
            ->withCollected();

        return DB::query()->fromSub($perBill, 'b')
            ->whereRaw('b.total > b.collected_total + b.voucher_total')
            ->pluck('b.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * শেষ জমা — খাতায় গ্রাহকের নামে বসা শেষ আদায় বা রসিদ ভাউচার।
     *
     * ⛔ উল্টানো দাখিলা বাদ: বাতিল করা আদায়ের মূল সারিটা খাতায় থেকেই যায়
     * (উল্টো সারি তার **পরে** বসে, [[PostingEngine::reverse()]]), তাই পরে
     * কোনো `<উৎস>:reversal` সারি থাকলে সেটা আর জমা নয়।
     *
     * @return array{date: string, amount: string, url: ?string}|null
     */
    private function lastPayment(int $customerId): ?array
    {
        // ⭐ হেডারে বাছা শাখায় (৩০ সেপ্টেম্বর ২০২৬) — [[ViewedBranch]] — কার্ডের বাকি অঙ্ক বিলের দেয়ালে আগেই ছাঁকা
        $open = fn (): Builder => ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->forParty(Customer::drillSourceType(), $customerId)
            ->whereIn('ledger_entries.source_type', self::paymentSources())
            ->where('ledger_entries.credit', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('ledger_entries as rev')
                ->whereColumn('rev.source_id', 'ledger_entries.source_id')
                ->whereRaw("rev.source_type = CONCAT(ledger_entries.source_type, ':reversal')")
                ->whereColumn('rev.id', '>', 'ledger_entries.id'));

        $row = $open()->orderByDesc('trx_date')->orderByDesc('id')->first();

        if ($row === null) {
            return null;
        }

        $amount = (string) $open()
            ->where('ledger_entries.source_type', $row->source_type)
            ->where('ledger_entries.source_id', $row->source_id)
            ->sum('credit');

        $route = $this->drill->describe((string) $row->source_type, (int) $row->source_id)['route'];

        return [
            'date' => $row->trx_date->toDateString(),
            'amount' => bcadd($amount, '0', 4),
            'url' => $route === null ? null : route($route[0], $route[1] ?? []),
        ];
    }

    /**
     * টাকা আসার কাগজ — আদায় আর রসিদ ভাউচার (কাউন্টারের জমাও রসিদ ভাউচার)।
     *
     * @return list<string>
     */
    private static function paymentSources(): array
    {
        return [Collection::drillSourceType(), Voucher::SOURCE_TYPES[Voucher::RECEIPT]];
    }
}
