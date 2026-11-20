<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\OldReceiptPapers;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\Collection;

/**
 * ⭐ রসিদের তালিকায় আগের আদায় (COL-…) — কেবল দেখা, "আদায় (পুরনো)" দাগে (মালিক, ২০ নভেম্বর ২০২৬: "টাকা ঢোকার কাগজ একটাই")।
 *
 * ⓘ আদায় আর লেখা হয় না, কিন্তু আগের দশটা কাগজ রসিদের তালিকা থেকেই খোঁজা যায় — একই তারিখ, পক্ষ আর শাখার ছাঁকনিতে।
 * ⛔ দেয়াল আদায়ের নিজের: চাবি `sales.collection.view` (না থাকলে কিছুই নয়), শাখা ([[ScopedToUserBranch]]) আর SR-এর ডিলার
 * ([[ScopedToUserDealers]]) মডেলেই। বাতিল বাদ — আদায়ের তালিকার শুরুর মতো।
 * ⓘ আলাদা ছোট টেবিল, রসিদের পাতা-ভাঙার সাথে মেশানো নয় — দুই কোয়েরির এক পাতা-ভাঙা গোনা ভুল করত।
 */
final class SalesOldCollections implements OldReceiptPapers
{
    public function forReceiptList(array $filters, ?string $search, int $limit = 20): array
    {
        $empty = ['rows' => [], 'count' => 0, 'total' => '0', 'more_url' => null];

        if (! (auth()->user()?->can('sales.collection.view') ?? false)) {
            return $empty;
        }

        $party = $filters['party'] ?? null;
        $customerId = null;

        if (is_string($party) && $party !== '') {
            [$type, $id] = array_pad(explode(':', $party, 2), 2, '');

            // ⓘ আদায় কেবল গ্রাহকের — অন্য পক্ষ বাছা থাকলে আগের আদায় নেই
            if ($type !== 'customer' || ! ctype_digit($id)) {
                return $empty;
            }

            $customerId = (int) $id;
        }

        $query = Collection::query()
            ->search($search)
            ->where('sal_collections.status', '<>', DocumentStatus::CANCELLED)
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('sal_collections.trx_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('sal_collections.trx_date', '<=', $d))
            ->when($customerId !== null, fn ($q) => $q->where('sal_collections.customer_id', $customerId))
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('sal_collections.branch_id', (int) $b));

        $count = (clone $query)->count();

        if ($count === 0) {
            return $empty;
        }

        $rows = (clone $query)
            ->with(['customer.location.parent', 'customer.branch'])
            ->orderByDesc('sal_collections.trx_date')->orderByDesc('sal_collections.id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Collection $c): array => [
                'no' => (string) $c->document_no,
                'date' => (string) $c->trx_date?->toDateString(),
                'party' => (string) ($c->customer?->nameWithPoint() ?? ''),
                'amount' => (string) $c->amount,
                'status' => (string) __('core.status.'.$c->status),
                'url' => route('sales.collection.show', $c),
            ])->values()->all();

        return [
            'rows' => $rows,
            'count' => $count,
            // ⛔ মোটে কেবল পাকা — খসড়া আর সইয়ের অপেক্ষা খাতায় ওঠেনি (মালিকের নিয়ম: মোটে কেবল চূড়ান্ত কাগজ)
            'total' => (string) (clone $query)->whereIn('sal_collections.status', DocumentStatus::POSTED)->sum('sal_collections.amount'),
            'more_url' => $count > count($rows) ? route('sales.collection.index') : null,
        ];
    }
}
