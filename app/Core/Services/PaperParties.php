<?php

declare(strict_types=1);

namespace App\Core\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * কাগজের পক্ষ — কাগজের নিজের ঘর থেকে, গোটা পাতার জন্য একবারে।
 *
 * ⭐ মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম
 * দিলে সহজে বোঝা যায়।"* — গ্রাহক হলে "নাম · পয়েন্ট", না হলে সরবরাহকারী/কর্মী/ব্যক্তির নাম ([[PartyRegistry::labelsOf()]])।
 *
 * ── ⛔ কোর কোনো মডিউলের নাম জানে না ───────────────────────────────────
 * পক্ষের ধরনগুলো আসে মডিউলের নিজের ঘোষণা থেকে ([[PartyRegistry::types()]] — `customer`, `supplier`, `employee`, `person` …),
 * আর কাগজে পক্ষ বসে দুইভাবে: (১) `party_type` + `party_id` জোড়া (ভাউচার, চেক, নোট); (২) ধরনের নামে ঘর — `customer_id`,
 * `supplier_id`, `employee_id`, `person_id` (চালান, আদায়, ক্রয়ের বিল, খরচের দাবি, উত্তোলন)। ⓘ নতুন ধরনের পক্ষ এলে এখানে কিছু
 * বদলাতে হয় না।
 *
 * ⚠️ প্রশ্ন পক্ষের ধরন প্রতি একটা — সারি প্রতি কখনো নয় (পঞ্চাশ সারির পাতায় পঞ্চাশটা প্রশ্ন হত)। কাগজগুলো ডাকনেওয়ালা আগেই তুলেছেন।
 */
final class PaperParties
{
    public function __construct(private readonly PartyRegistry $parties) {}

    /**
     * কাগজের পক্ষ কোনটা — [ধরন, id], অথবা নেই।
     *
     * ⓘ জোড়া আগে (সে-ই কাগজের ঘোষিত পক্ষ); তারপর ঘোষিত ধরনগুলোর নিজের ঘর, ঘোষণার ক্রমে।
     * ⚠️ কেবল তোলা ঘর পড়া হয় (`getAttributes()`), তাই যে কাগজে ঘরটা নেই তাকে কখনো ছোঁড়ে না।
     *
     * @return array{0: string, 1: int}|null
     */
    public function keyOf(Model $paper): ?array
    {
        $fields = $paper->getAttributes();
        $type = (string) ($fields['party_type'] ?? '');
        $id = (int) ($fields['party_id'] ?? 0);

        if ($type !== '' && $id > 0 && $this->parties->knows($type)) {
            return [$type, $id];
        }

        foreach ($this->parties->types() as $type) {
            $id = (int) ($fields[$type.'_id'] ?? 0);

            if ($id > 0) {
                return [$type, $id];
            }
        }

        return null;
    }

    /**
     * অনেকগুলো কাগজের পক্ষের নাম — একবারে।
     *
     * @param  iterable<Model>  $papers  যেকোনো ধরনের কাগজ, মেশানো চলে
     * @return array<string, string> [[slot()]] => "নাম · পয়েন্ট"
     */
    public function of(iterable $papers): array
    {
        $keys = [];

        foreach ($papers as $paper) {
            if ($paper instanceof Model && ($key = $this->keyOf($paper)) !== null) {
                $keys[self::slot($paper)] = $key;
            }
        }

        if ($keys === []) {
            return [];
        }

        $labels = $this->parties->labelsOf(array_values($keys));
        $found = [];

        foreach ($keys as $slot => [$type, $id]) {
            $label = $labels[$type.':'.$id] ?? null;

            if ($label !== null) {
                $found[$slot] = $label;
            }
        }

        return $found;
    }

    /** একটা কাগজের পক্ষ — বিজ্ঞপ্তির মতো একক জায়গার জন্য ([[NamesItsPartyInNotices]]); তালিকায় [[of()]] */
    public function labelOf(Model $paper): ?string
    {
        return $this->of([$paper])[self::slot($paper)] ?? null;
    }

    /** [[of()]]-এর চাবি — ক্লাস আর id, যাতে মেশানো তালিকায় দুই ধরনের ৭ নম্বর এক না হয় */
    public static function slot(Model $paper): string
    {
        return $paper::class.':'.$paper->getKey();
    }
}
