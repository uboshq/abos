<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\OpenPeriod;
use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Note;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionApplication;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ টাকা নেওয়ার পর্দার "খোলা বিল ও ক্রেডিট" — দফা ১ আর ৩ (মালিকের নকশা ১০০, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ এক টেবিলে চার ধরন, পুরনো আগে: বিল (sal_invoices), ডেবিট নোট আর ক্রেডিট নোট (acc_notes, গ্রাহকের), আর আগের আদায়ের
 * অপ্রয়োগকৃত অংশ (`credit`)। ক্রেডিটের দুই ধরন ঋণাত্মক অঙ্কে — পর্দা আর ফোন যেমন দেখায়।
 * ⓘ বাকি = বিলের নিজের হিসাব ([[SalesInvoice::dueAmount()]]) — বিলের পাতা, বাকির দেয়াল আর এই তালিকা এক কথা বলে।
 * ⓘ ক্রেডিটের খোলা অংশ = তার মোট − পাকা আদায়ে বসা প্রয়োগ ([[CollectionApplication]])।
 * ⛔ বিল কেবল গ্রাহকের শাখার — আদায় বসে গ্রাহকের শাখায় (দফা ১৪), অন্য শাখার বিলে ভাগ দিলে দুই শাখার পাওনা ভুল হত।
 */
final class CollectionOpenItems
{
    public function __construct(
        private readonly OpenPeriod $periods,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{customer: array{id: string, label: string, credit_limit: string|null, oldest_overdue_days: int|null, branch: string|null},
     *     due: string, credit: string, month_open: bool, attachment_required: bool,
     *     items: list<array{id: string, kind: string, no: string, date: string, due_date: string|null, amount: string, open: string, overdue_days: int|null, url: string}>}
     *     ⓘ `url` পর্দার জন্য বাড়তি (কাগজের পাতা); পর্দা আর ফোন দুজনেই `id` (public_id) পাঠায়
     */
    public function for(Customer $customer, ?Carbon $on = null): array
    {
        $on ??= Carbon::today();
        $customer->loadMissing(Customer::PLACE);

        $items = [
            ...$this->bills($customer, $on),
            ...$this->notes($customer, $on),
            ...$this->priorCredits($customer),
        ];

        // ⓘ পুরনো আগে; একই দিনে বিল আগে, তারপর নোট, তারপর ক্রেডিট — পর্দার স্বয়ংক্রিয় ভাগের ক্রমও এটাই
        $rank = ['bill' => 0, 'debit_note' => 1, 'credit_note' => 2, 'credit' => 3];
        usort($items, fn (array $a, array $b) => [$a['date'], $rank[$a['kind']]] <=> [$b['date'], $rank[$b['kind']]]);

        $credit = '0.0000';
        $overdue = null;

        foreach ($items as $item) {
            if (in_array($item['kind'], CollectionInput::CREDIT_KINDS, true)) {
                $credit = bcadd($credit, ltrim($item['open'], '-'), 4);
            }

            if ($item['overdue_days'] !== null) {
                $overdue = max($overdue ?? 0, $item['overdue_days']);
            }
        }

        $limit = $customer->effectiveCreditLimit();

        return [
            'customer' => [
                'id' => (string) $customer->public_id,
                'label' => $customer->nameWithPoint(),
                'credit_limit' => bccomp($limit, '0', 4) > 0 ? $limit : null,
                'oldest_overdue_days' => $overdue,
                // ⓘ পর্দার মাথার "শাখা: গ্রাহকের শাখা (…)" — আদায় এই শাখাতেই বসে (দফা ১৪)
                'branch' => $customer->branchName(),
            ],
            // ⛔ পর্দার বকেয়া = খাতার বকেয়া, হুবহু ([[Customer::outstanding()]]) — নিজের যোগফল নয়
            'due' => $customer->outstanding(),
            'credit' => $credit,
            'month_open' => $this->periods->isOpen($on),
            'attachment_required' => (bool) $this->settings->get('sales.collection.attachment_required', false),
            'items' => array_values($items),
        ];
    }

    /** ক্রেডিট নোটের বা ডেবিট নোটের খোলা অংশ — মোট বিয়োগ পাকা আদায়ে বসা প্রয়োগ */
    public function openOfNote(Note $note): string
    {
        $kind = $note->isCredit() ? CollectionApplication::CREDIT_NOTE : CollectionApplication::DEBIT_NOTE;
        $open = bcsub(bcadd((string) $note->total, '0', 4), self::appliedFrom($kind, (int) $note->id), 4);

        return bccomp($open, '0', 4) > 0 ? $open : '0.0000';
    }

    /**
     * আগের আদায়ের যে অংশ কোনো বিলে বসেনি — এখনো গ্রাহকের খাতায় Cr।
     *
     * ⓘ নতুন কাগজে `unapplied` লেখা থাকে; পুরনোয় ফাঁকা — তখন অঙ্ক বিয়োগ বিলের সারি (আগের নিয়মে বাড়তি টাকা গ্রাহকের খাতায়
     * Cr থাকত, কোনো বিলে নয়)।
     */
    public function openOfCredit(Collection $collection): string
    {
        if (! in_array((string) $collection->status, DocumentStatus::POSTED, true)) {
            return '0.0000';
        }

        $left = $collection->unapplied !== null
            ? bcadd((string) $collection->unapplied, '0', 4)
            : bcsub((string) $collection->amount, (string) ($collection->lines()->sum('amount') ?: '0'), 4);

        $open = bcsub($left, self::appliedFrom(CollectionApplication::CREDIT, (int) $collection->id), 4);

        return bccomp($open, '0', 4) > 0 ? $open : '0.0000';
    }

    /** পাকা আদায়ে এই উৎস থেকে কত বসেছে — বাতিল আর খসড়া বাদ */
    public static function appliedFrom(string $kind, int $sourceId): string
    {
        return bcadd((string) (DB::table('sal_collection_applications as a')
            ->join('sal_collections as c', 'c.id', '=', 'a.collection_id')
            // ⛔ কাঁচা প্রশ্নে কোম্পানির নাম নিজে ([[EveryRawQueryNamesItsCompanyTest]])
            ->where('c.company_id', \App\Core\Support\CompanyContext::id())
            ->where('a.kind', $kind)
            ->where('a.source_id', $sourceId)
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', DocumentStatus::POSTED)
            ->sum('a.amount') ?: '0'), '0', 4);
    }

    /** @return list<array<string, mixed>> */
    private function bills(Customer $customer, Carbon $on): array
    {
        $out = [];

        $bills = SalesInvoice::acrossBranches()
            ->where('sal_invoices.customer_id', $customer->id)
            ->where('sal_invoices.status', DocumentStatus::CONFIRMED)
            ->when($customer->branch_id !== null, fn ($q) => $q->where('sal_invoices.branch_id', $customer->branch_id))
            ->withCollected()
            ->orderBy('sal_invoices.trx_date')->orderBy('sal_invoices.id')
            ->get();

        foreach ($bills as $bill) {
            $open = $bill->dueAmount();

            if (bccomp($open, '0', 4) <= 0) {
                continue;
            }

            $out[] = $this->row('bill', (string) $bill->public_id, (string) $bill->document_no, $bill->trx_date,
                $bill->due_on, (string) $bill->total, $open, $on) + ['url' => route(...$bill->drillRoute())];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function notes(Customer $customer, Carbon $on): array
    {
        $out = [];

        $notes = Note::acrossBranches()
            ->where('party_type', 'customer')
            ->where('party_id', $customer->id)
            ->where('status', DocumentStatus::CONFIRMED)
            ->orderBy('trx_date')->orderBy('id')
            ->get();

        foreach ($notes as $note) {
            $open = $this->openOfNote($note);

            if (bccomp($open, '0', 4) <= 0) {
                continue;
            }

            $credit = $note->isCredit();
            $out[] = $this->row($credit ? 'credit_note' : 'debit_note', (string) $note->public_id, (string) $note->document_no,
                $note->trx_date, null, $credit ? '-'.bcadd((string) $note->total, '0', 4) : (string) $note->total,
                $credit ? '-'.$open : $open, $on) + ['url' => route(...$note->drillRoute())];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function priorCredits(Customer $customer): array
    {
        $out = [];

        $collections = Collection::acrossBranches()
            ->where('customer_id', $customer->id)
            ->whereIn('status', DocumentStatus::POSTED)
            ->orderBy('trx_date')->orderBy('id')
            ->get();

        foreach ($collections as $collection) {
            $open = $this->openOfCredit($collection);

            if (bccomp($open, '0', 4) <= 0) {
                continue;
            }

            $out[] = $this->row('credit', (string) $collection->public_id, (string) $collection->document_no, $collection->trx_date,
                null, '-'.bcadd((string) $collection->amount, '0', 4), '-'.$open, Carbon::today())
                + ['url' => route(...$collection->drillRoute())];
        }

        return $out;
    }

    /** @return array{id: string, kind: string, no: string, date: string, due_date: string|null, amount: string, open: string, overdue_days: int|null} */
    private function row(string $kind, string $id, string $no, mixed $date, mixed $dueOn, string $amount, string $open, Carbon $on): array
    {
        $dueDate = $dueOn === null ? null : Carbon::parse($dueOn)->startOfDay();
        $late = $dueDate !== null && $dueDate->lt($on->copy()->startOfDay()) ? (int) $dueDate->diffInDays($on->copy()->startOfDay()) : null;

        return [
            'id' => $id,
            'kind' => $kind,
            'no' => $no,
            'date' => Carbon::parse($date)->toDateString(),
            'due_date' => $dueDate?->toDateString(),
            'amount' => bcadd($amount, '0', 4),
            'open' => bcadd($open, '0', 4),
            'overdue_days' => $late,
        ];
    }
}
