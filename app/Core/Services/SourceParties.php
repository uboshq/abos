<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Drill\DrillResolver;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * কাগজের পক্ষ — কাগজের ধরন আর আইডি (`source_type`, `source_id`) থেকে, গোটা পাতার একবারে।
 *
 * ⭐ মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম
 * দিলে সহজে বোঝা যায়।"*
 *
 * ── কোথা থেকে, কোন ক্রমে ───────────────────────────────────────────
 * (১) খাতা: একই কাগজের যেকোনো পক্ষ-ওয়ালা সারি। ⓘ টাকার দিকের সারিতে (ক্যাশ/ব্যাংক) পক্ষ থাকে না — পক্ষ বসে একই কাগজের
 *     **অন্য** সারিতে (গ্রাহকের পাওনার দিক)। খাতা সবার সাধারণ টেবিল, তাই এক প্রশ্নে সব ধরনের কাগজ।
 * (২) খাতায় না পেলে কাগজ নিজে — খাতায় না-ওঠা কাগজ (চালানের মজুদ, খসড়া): drill-এর মানচিত্র থেকে মডেল, ধরন প্রতি একটা প্রশ্ন,
 *     আর পক্ষ পড়ে [[PaperParties::keyOf()]] (`party_type`/`party_id` বা `customer_id`/`supplier_id` …)। ⛔ কোর কোনো মডিউলের
 *     নাম জানে না — মানচিত্র আর পক্ষের ধরন দুইটাই মডিউলের নিজের ঘোষণা।
 *
 * ⚠️ প্রশ্ন গোটা পাতার জন্য: খাতায় একটা, (২)-এ কাগজের ধরন প্রতি একটা, আর পক্ষের ধরন প্রতি একটা ([[PartyRegistry::labelsOf()]])।
 * ⓘ `…:reversal`, `…:free`, `…:cancel` সারি আসল কাগজের পক্ষই পায় — একই কাগজ।
 * ⓘ এক কাগজে দুই পক্ষ বিরল (দুই গ্রাহকের জাবেদা); তখন একটাই, স্থির নিয়মে (MIN) — প্রতিবার একই নাম।
 */
final class SourceParties
{
    public function __construct(
        private readonly PartyRegistry $parties,
        private readonly DrillResolver $drill,
        private readonly PaperParties $papers,
    ) {}

    /**
     * @param  iterable<array{0: string|null, 1: int|string|null}>  $sources  [source_type, source_id]
     * @return array<string, string> "source_type:source_id" (যেমন চাওয়া হয়েছিল) => "নাম · পয়েন্ট"
     */
    public function of(iterable $sources): array
    {
        $keys = $this->keysOf($sources);
        $labels = $this->labels(array_values($keys));
        $found = [];

        foreach ($keys as $asked => $party) {
            if (isset($labels[$party])) {
                $found[$asked] = $labels[$party];
            }
        }

        return $found;
    }

    /**
     * খাতার সারির পক্ষ — সারির নিজের পক্ষ, না থাকলে তার কাগজের পক্ষ; গোটা পাতার একবারে।
     *
     * ⓘ টাকার দিকের সারিতে (ক্যাশ/ব্যাংক) পক্ষ বসে না, অথচ খাতা পড়ার সময় প্রথম প্রশ্নই "কার টাকা" — তাই কাগজে ফিরে দেখা হয়।
     * ⚠️ পক্ষের নামের প্রশ্ন একবারই — নিজের পক্ষ আর কাগজের পক্ষ মিলে ([[PartyRegistry::labelsOf()]])।
     *
     * @param  iterable<object>  $entries  `id`, `party_type`, `party_id`, `source_type`, `source_id` ঘরসহ সারি
     * @return array<int, string> সারির id => "নাম · পয়েন্ট"
     */
    public function forEntries(iterable $entries): array
    {
        $own = [];
        $orphans = [];

        foreach ($entries as $entry) {
            $id = (int) data_get($entry, 'id');
            $type = (string) data_get($entry, 'party_type');
            $partyId = (int) data_get($entry, 'party_id');
            $source = (string) data_get($entry, 'source_type');
            $sourceId = (int) data_get($entry, 'source_id');

            if ($type !== '' && $partyId > 0) {
                $own[$id] = $type.':'.$partyId;
            } elseif ($source !== '' && $sourceId > 0) {
                $orphans[$id] = [$source, $sourceId];
            }
        }

        $viaSource = $this->keysOf(array_values($orphans));
        $keys = $own;

        foreach ($orphans as $id => [$source, $sourceId]) {
            if (isset($viaSource[$source.':'.$sourceId])) {
                $keys[$id] = $viaSource[$source.':'.$sourceId];
            }
        }

        $labels = $this->labels(array_values($keys));
        $found = [];

        foreach ($keys as $id => $party) {
            if (isset($labels[$party])) {
                $found[$id] = $labels[$party];
            }
        }

        return $found;
    }

    /**
     * কাগজ => পক্ষের চাবি ("ধরন:id") — নাম ছাড়া।
     *
     * @param  iterable<array{0: string|null, 1: int|string|null}>  $sources
     * @return array<string, string> "source_type:source_id" (যেমন চাওয়া) => "ধরন:id"
     */
    private function keysOf(iterable $sources): array
    {
        $asked = [];
        $byType = [];

        foreach ($sources as [$type, $id]) {
            $type = (string) $type;
            $id = (int) $id;

            if ($type === '' || $id <= 0) {
                continue;
            }

            // ⓘ "sales_invoice:reversal" → "sales_invoice" — একই কাগজ
            $base = str_contains($type, ':') ? (string) strstr($type, ':', true) : $type;
            $asked[$type.':'.$id] = [$base, $id];
            $byType[$base][$id] = $id;
        }

        if ($byType === []) {
            return [];
        }

        $partyOf = $this->fromTheBooks($byType);

        /* (২) খাতায় নেই — কাগজ নিজে, ধরন প্রতি একটা প্রশ্ন */
        $missing = [];

        foreach ($byType as $base => $ids) {
            foreach ($ids as $id) {
                if (! isset($partyOf[$base.':'.$id])) {
                    $missing[$base][] = $id;
                }
            }
        }

        $map = $missing === [] ? [] : $this->drill->map();

        foreach ($missing as $base => $ids) {
            $class = $map[$base] ?? null;

            if ($class === null || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            foreach ($class::query()->whereKey($ids)->get() as $paper) {
                $pair = $this->papers->keyOf($paper);

                if ($pair !== null) {
                    $partyOf[$base.':'.$paper->getKey()] = $pair[0].':'.$pair[1];
                }
            }
        }

        $found = [];

        foreach ($asked as $key => [$base, $id]) {
            if (isset($partyOf[$base.':'.$id])) {
                $found[$key] = $partyOf[$base.':'.$id];
            }
        }

        return $found;
    }

    /**
     * (১) খাতা থেকে — এক প্রশ্নে, সব ধরনের কাগজের।
     *
     * ⓘ ONLY_FULL_GROUP_BY-নিরাপদ: বাছা ঘর দুইটাই group-এর, বাকিটা সমষ্টি। ⓘ আসল আর উল্টো/বিনা-মূল্যের সারি দুইটাই খোঁজা হয়
     * (`LIKE 'x:%'`) — পক্ষ যেকোনোটায় থাকলেই চলে; ফলের চাবি আসল কাগজের নামে।
     *
     * @param  array<string, array<int, int>>  $byType
     * @return array<string, string> "আসল ধরন:id" => "পক্ষের ধরন:id"
     */
    private function fromTheBooks(array $byType): array
    {
        $rows = LedgerEntry::query()
            ->whereNotNull('party_type')
            ->whereNotNull('party_id')
            ->where(function ($q) use ($byType) {
                foreach ($byType as $type => $ids) {
                    $q->orWhere(fn ($w) => $w
                        ->where(fn ($t) => $t->where('source_type', $type)->orWhere('source_type', 'like', $type.':%'))
                        ->whereIn('source_id', array_values($ids)));
                }
            })
            ->groupBy('source_type', 'source_id')
            ->select('source_type', 'source_id')
            ->selectRaw("MIN(CONCAT(party_type, ':', party_id)) as party_key")
            ->toBase()
            ->get();

        $partyOf = [];

        // ⓘ আসল নামের সারি আগে বসে, উল্টো সারি কেবল ফাঁক ভরে
        foreach ($rows->sortBy(fn ($row) => str_contains((string) $row->source_type, ':') ? 1 : 0) as $row) {
            $type = (string) $row->source_type;
            $base = str_contains($type, ':') ? (string) strstr($type, ':', true) : $type;
            $partyOf[$base.':'.$row->source_id] ??= (string) $row->party_key;
        }

        return $partyOf;
    }

    /**
     * @param  list<string>  $keys  "ধরন:id"
     * @return array<string, string>
     */
    private function labels(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return $this->parties->labelsOf(array_map(
            fn (string $key) => [(string) strstr($key, ':', true), (int) substr((string) strrchr($key, ':'), 1)],
            array_values(array_unique($keys)),
        ));
    }
}
