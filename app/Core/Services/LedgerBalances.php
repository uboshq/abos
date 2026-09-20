<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * অনেক খাতের জের একবারে — প্রতি খাতে একটা করে কোয়েরি নয়।
 *
 * ── ⛔ কী ঘটত, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * [[Account::balanceOn]] প্রতিটা পাতা-খাতের জন্য আলাদা একটা
 * `SUM(debit), SUM(credit)` চালাত, আর গ্রুপ হলে সন্তানদের ধরে নিচে
 * নামত। ⓘ ১৯০ খাতের ছকে মেপে দেখা: **৮১টা কোয়েরি** একটা পাতা আঁকতে।
 *
 * ⚠️ আর প্রতিটা যোগফলের কোনো নিচের তারিখ-সীমা নেই — অর্থাৎ ঐ খাতের
 * **পুরো ইতিহাস** প্রতিবার। আজ খতিয়ান ছোট, তাই দেখা যায় না; দুই লাখ
 * সারিতে ৮১টা এমন যোগফল মানে সেকেন্ড, মিলিসেকেন্ড নয়।
 *
 * ── ⭐ কেন এটা টাকার নিয়ম ছোঁয় না ───────────────────────────────────
 * [[ChartOfAccountsController]]-এ আগের সেশন কারণসহ লিখে গেছে কেন পুরো
 * সাবট্রির একটাই যোগফল নেওয়া হয়নি: **চিহ্নটা প্রতিটা খাত নিজের প্রকৃতি
 * ধরে ঠিক করে**, আর টাকার অঙ্কের নিয়ম একা বদলানো ঠিক নয়।
 *
 * ⓘ এখানে সেই নিয়মটা অক্ষত। কেবল ডেবিট-ক্রেডিটের **কাঁচা যোগফল**
 * একবারে তোলা হয় (`GROUP BY account_id`), আর চিহ্ন বসানো হয় আগের
 * জায়গাতেই, আগের মতোই। ⭐ অর্থাৎ উত্তর হুবহু এক, কেবল প্রশ্নটা একবার
 * করা হয়।
 */
final class LedgerBalances
{
    /**
     * `শর্তের চাবি` → `খাতের আইডি` → `[ডেবিট, ক্রেডিট]`
     *
     * @var array<string, array<int, array{0: string, 1: string}>>
     */
    private array $loaded = [];

    /**
     * এই খাতগুলোর কাঁচা যোগফল একবারে তুলে রাখা।
     *
     * @param  list<int>  $accountIds
     */
    public function preload(array $accountIds, ?string $upto = null, ?int $branchId = null): void
    {
        $key = $this->key($upto, $branchId);
        $want = array_values(array_diff($accountIds, array_keys($this->loaded[$key] ?? [])));

        if ($want === []) {
            return;
        }

        $rows = LedgerEntry::query()
            ->whereIn('account_id', $want)
            ->when($upto, fn (Builder $q, string $date) => $q->whereDate('trx_date', '<=', $date))
            ->when($branchId, fn (Builder $q, int $branch) => $q->where('branch_id', $branch))
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->get();

        /*
         * ⚠️ যে খাতে একটাও সারি নেই সে ফলাফলে আসে না — তাই আগে সবগুলো
         * শূন্য বসিয়ে নেওয়া হয়। ⓘ নাহলে ওরা "তোলা হয়নি" গণ্য হত আর
         * প্রতিবার আবার জিজ্ঞেস করা হত, অর্থাৎ ঠিক যে N+1 সারানো হচ্ছে
         * সেটাই খালি খাতগুলোর জন্য থেকে যেত।
         */
        foreach ($want as $id) {
            $this->loaded[$key][$id] = ['0', '0'];
        }

        foreach ($rows as $row) {
            $this->loaded[$key][(int) $row->account_id] = [
                (string) $row->d,
                (string) $row->c,
            ];
        }
    }

    /**
     * তোলা থাকলে কাঁচা যোগফল, নাহলে `null`।
     *
     * ⓘ `null` মানে "জানি না" — ডাকা জায়গা তখন আগের মতোই নিজে গুনবে।
     * ⭐ তাই এই সেবাটা না থাকলেও সব আগের মতো চলে; এটা কেবল দ্রুত পথ।
     *
     * @return array{0: string, 1: string}|null
     */
    public function raw(int $accountId, ?string $upto = null, ?int $branchId = null): ?array
    {
        return $this->loaded[$this->key($upto, $branchId)][$accountId] ?? null;
    }

    /** সব ভুলে যাওয়া — কেউ খাতায় লিখলে ডাকবেন। */
    public function forget(): void
    {
        $this->loaded = [];
    }

    private function key(?string $upto, ?int $branchId): string
    {
        return ($upto ?? 'all').'|'.($branchId ?? 'all');
    }
}
