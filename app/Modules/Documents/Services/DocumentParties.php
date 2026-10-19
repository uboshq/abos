<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Services\PaperParties;
use App\Core\Services\PartyRegistry;
use App\Modules\Documents\Models\DocumentLink;
use Illuminate\Database\Eloquent\Model;

/**
 * কাগজটা কার — জোড়া রেকর্ড থেকে, গোটা পাতার জন্য একবারে।
 *
 * ⭐ মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম
 * দিলে সহজে বোঝা যায়।"* — ডকুমেন্ট সেন্টার, অনুমোদনের সারি আর সইয়ের কেন্দ্রে নম্বরের পাশে পক্ষ।
 *
 * ⓘ পক্ষ আসে কাগজের জোড়া থেকে ([[DocumentLink]]): সরাসরি গ্রাহক/সরবরাহকারী/কর্মী জোড়া থাকলে সে-ই ("নাম · পয়েন্ট",
 * [[PartyRegistry::labelsOf()]]); না থাকলে জোড়া ক্রয়ের কাগজের পক্ষ ([[PaperParties]] — যেমন ক্রয় আদেশের সরবরাহকারী)।
 * এক কাগজে কয়েকটা জোড়া থাকলে প্রথমটা (আগে জোড়া), প্রতিবার একই নাম।
 * ⛔ কোনো মডিউলের ক্লাস নয় ([[BoundariesTest]]) — রেকর্ড চেনা হয় ড্রিলের নাম দিয়ে ([[DrillResolver]])।
 * ⚠️ প্রশ্ন: জোড়ায় একটা, পক্ষের ধরন প্রতি একটা, জোড়া কাগজের ধরন প্রতি একটা — সারি ধরে কখনো নয়।
 */
final class DocumentParties
{
    public function __construct(
        private readonly PartyRegistry $parties,
        private readonly PaperParties $papers,
        private readonly DrillResolver $drill,
    ) {}

    /**
     * @param  iterable<int|string|null>  $documentIds
     * @return array<int, string> কাগজের id => "নাম · পয়েন্ট"
     */
    public function of(iterable $documentIds): array
    {
        $ids = [];

        foreach ($documentIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $links = DocumentLink::query()
            ->whereIn('document_id', array_values($ids))
            ->orderBy('id')
            ->get(['id', 'document_id', 'source_type', 'source_id']);

        $direct = [];   // কাগজ => [ধরন, id] — সরাসরি পক্ষ
        $through = [];  // কাগজ => [ধরন, id] — জোড়া কাগজ
        $paperIds = []; // ধরন => id-গুলো

        foreach ($links as $link) {
            $documentId = (int) $link->document_id;
            $type = (string) $link->source_type;
            $sourceId = (int) $link->source_id;

            if ($sourceId <= 0 || $type === '') {
                continue;
            }

            if ($this->parties->knows($type)) {
                $direct[$documentId] ??= [$type, $sourceId];
            } elseif ($this->drill->knows($type)) {
                $through[$documentId] ??= [$type, $sourceId];
                $paperIds[$type][$sourceId] = $sourceId;
            }
        }

        $labels = $direct === [] ? [] : $this->parties->labelsOf(array_values($direct));
        $paperLabels = $this->paperLabels($paperIds);

        $out = [];

        foreach ($ids as $documentId) {
            $label = isset($direct[$documentId]) ? ($labels[implode(':', $direct[$documentId])] ?? null) : null;
            $label ??= isset($through[$documentId]) ? ($paperLabels[implode(':', $through[$documentId])] ?? null) : null;

            if ($label !== null) {
                $out[$documentId] = $label;
            }
        }

        return $out;
    }

    /**
     * জোড়া কাগজগুলোর পক্ষ — ধরন প্রতি একটা প্রশ্নে কাগজ, তারপর পক্ষ একবারে।
     *
     * @param  array<string, array<int, int>>  $paperIds
     * @return array<string, string> "ধরন:id" => নাম
     */
    private function paperLabels(array $paperIds): array
    {
        $loaded = [];
        $map = $this->drill->map();

        foreach ($paperIds as $type => $sourceIds) {
            $class = $map[$type] ?? null;

            if ($class === null || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            foreach ($class::query()->whereKey(array_values($sourceIds))->get() as $paper) {
                $loaded[$type.':'.$paper->getKey()] = $paper;
            }
        }

        $labels = $this->papers->of($loaded);
        $out = [];

        foreach ($loaded as $key => $paper) {
            $label = $labels[PaperParties::slot($paper)] ?? null;

            if ($label !== null) {
                $out[$key] = $label;
            }
        }

        return $out;
    }
}
