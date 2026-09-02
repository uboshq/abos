<?php

declare(strict_types=1);

namespace App\Core\Engines\Audit;

use App\Core\Security\FieldSecurity;
use App\Models\AuditTrail;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * "১৫ জুন এই কাগজটা কেমন ছিল?" — অডিট থেকে গড়ে তোলা উত্তর।
 *
 * ── কী ছিল না ────────────────────────────────────────────────────────
 * ইতিহাসটা পুরোটাই ছিল ([[AuditEngine]] প্রতিটা ঘরের পুরনো ও নতুন মান
 * রাখে), কিন্তু পর্দায় দেখা যেত কেবল **ঘটনার তালিকা** — "রহিম দাম
 * বদলেছেন, ১১০ থেকে ১২০"। চল্লিশটা ঘটনার একটা কাগজে "ওইদিন এটা কেমন
 * ছিল" জানতে হলে চল্লিশটা সারি হাতে মিলিয়ে যেতে হত।
 *
 * আর নিরীক্ষায় প্রশ্নটা ঠিক ওভাবেই আসে: *"৩০ জুনের ব্যালেন্স শিটে এই
 * বিলটা কত ছিল?"* — কে কবে কী বদলেছেন তা নয়।
 *
 * ── আজকের সারি থেকে পেছনে হাঁটা — আর কেন সেটা অনুমান নয় ───────────────
 * প্রথমে সামনের দিকে গোনার চেষ্টা করা হয়েছিল: তৈরির মুহূর্তের মান
 * থেকে শুরু করে পরিবর্তনগুলো বসানো। **কিন্তু তৈরির মানগুলো অডিটে
 * লেখাই হয় না**, আর সেটা ইচ্ছাকৃত ([[IsAudited]]-এ কারণ লেখা আছে):
 * নতুন রেকর্ডের মান রেকর্ডেই আছে, তাই কপিটা প্রতিটা তৈরিতে পঁচিশটা
 * বাড়তি সারি জমাত আর নতুন কিছু বলত না।
 *
 * তাই ভিত্তিটা **আজকের সারি**, আর তার উপর থেকে ওই মুহূর্তের পরের
 * প্রতিটা পরিবর্তন খুলে নেওয়া হয় — প্রতিটা ঘরে তার **প্রথম** পরের
 * পরিবর্তনের `old_value`।
 *
 * ── আর যে ঘরটা কখনো বদলায়নি? ─────────────────────────────────────────
 * তার আজকের মানটাই ওইদিনের মান — **এটা অনুমান নয়, উপসংহার**, আর
 * শর্তটা একটাই: ওই মুহূর্ত থেকে আজ পর্যন্ত অডিট চালু ছিল। চালু
 * থাকলে কোনো পরিবর্তন অলেখা যায়নি, অর্থাৎ ঘরটা বদলায়ওনি।
 *
 * ⚠️ **শর্তটা না মিললে উত্তরটা "জানা নেই"** — আর ঠিক এখানেই সহজ
 * বাস্তবায়নটা নীরবে মিথ্যা বলত: ইতিহাস শুরুর আগের কোনো তারিখ চাইলে
 * সে আজকের মানটাই আত্মবিশ্বাসের সাথে দেখিয়ে দিত। নিরীক্ষায় "জানি
 * না" একটা বৈধ উত্তর; ভুল সংখ্যা নয়।
 *
 * ── অনুমতি এখানেও খাটে ────────────────────────────────────────────────
 * ⚠️ এই পর্দাটা [[FieldSecurity]] পাশ কাটানোর সবচেয়ে সহজ দরজা হতে
 * পারত: ক্রয়মূল্য আজ ঢাকা, অথচ "গত মাসে কেমন ছিল" জিজ্ঞেস করলে খোলা।
 * তাই ফেরত দেওয়ার আগে প্রতিটা ঘর একই পাহারার ভেতর দিয়ে যায়।
 */
final class TimeMachine
{
    /** ঘরটার মান অডিট থেকে সরাসরি জানা। */
    public const KNOWN = 'known';

    /** ওইদিন ঘরটা খালি ছিল — জানা, আর জানা মানটা শূন্যতা। */
    public const EMPTY_THEN = 'empty';

    /** ঘরটা কোনোদিন অডিটে যায় না — অতীত সম্পর্কে কিছুই বলা যায় না। */
    public const UNTRACKED = 'untracked';

    /**
     * একটা রেকর্ড ওই মুহূর্তে কেমন ছিল।
     *
     * @param  class-string  $type
     * @return array{
     *     existed: bool,
     *     deleted: bool,
     *     history_begins: ?CarbonInterface,
     *     complete: bool,
     *     applied: int,
     *     later: int,
     *     fields: array<string, array{value: mixed, certainty: string}>
     * }
     */
    public function at(string $type, int $id, CarbonInterface $moment): array
    {
        $trails = AuditTrail::query()
            ->forRecord($type, $id)
            ->with('changes')
            ->orderBy('id')
            ->get();

        $creation = $trails->firstWhere('action', AuditTrail::CREATED);

        /*
         * তৈরির ঘটনাটাই যদি ওই মুহূর্তের পরে হয়, তবে ওইদিন কাগজটা
         * ছিল না। খালি ঘর দেখানোর বদলে সেটা স্পষ্ট বলাই ঠিক — নাহলে
         * কেউ "ওইদিন সব শূন্য ছিল" পড়ে নিতেন।
         */
        if ($creation !== null && $creation->created_at->greaterThan($moment)) {
            return $this->nothingYet($trails, $moment, $creation);
        }

        [$upTo, $later] = $trails->partition(
            fn (AuditTrail $trail): bool => $trail->created_at->lessThanOrEqualTo($moment)
        );

        /*
         * ওই মুহূর্তের পরে যে ঘরগুলো বদলেছে, তাদের **আগের** মান।
         *
         * প্রতিটা ঘরের জন্য কেবল **প্রথম** পরের পরিবর্তনটা গোনা হয়।
         * একই ঘর তিনবার বদলালে (১০০ → ১১০ → ১২০ → ১৩০) ওই মুহূর্তের
         * মান ছিল প্রথম পরিবর্তনের আগেরটা, অর্থাৎ ১০০। পরেরগুলোর
         * `old_value` বসালে উত্তরটা হত ১১০ বা ১২০ — কাছাকাছি, আর
         * তাই ভুলটা কেউ ধরত না।
         */
        $rewound = [];

        foreach ($later as $trail) {
            foreach ($trail->changes as $change) {
                if (! array_key_exists($change->field, $rewound)) {
                    $rewound[$change->field] = $change->old_value;
                }
            }
        }

        $begins = $trails->first()?->created_at;

        /*
         * ওই মুহূর্ত থেকে আজ পর্যন্ত অডিট চালু ছিল কি না।
         *
         * এটাই পুরো পর্দাটার শর্ত। চালু থাকলে কোনো পরিবর্তন অলেখা
         * যায়নি, তাই "যে ঘরটা বদলের তালিকায় নেই সেটা বদলায়ওনি"
         * কথাটা প্রমাণিত। না থাকলে ওটা কেবল আশা।
         */
        $covered = $begins !== null && $begins->lessThanOrEqualTo($moment);

        $deleted = $this->wasDeleted($upTo);

        return [
            'existed' => ! $deleted,
            'deleted' => $deleted,
            'history_begins' => $begins,
            'complete' => $covered,
            'applied' => $upTo->count(),
            'later' => $later->count(),
            'fields' => $deleted ? [] : $this->present($type, $id, $rewound, $covered),
        ];
    }

    /**
     * সেদিন কাগজটা তৈরিই হয়নি।
     *
     * @param  Collection<int, AuditTrail>  $trails
     * @return array{existed: bool, deleted: bool, history_begins: ?CarbonInterface, complete: bool, applied: int, later: int, fields: array<string, array{value: mixed, certainty: string}>}
     */
    private function nothingYet(Collection $trails, CarbonInterface $moment, AuditTrail $creation): array
    {
        return [
            'existed' => false,
            'deleted' => false,
            'history_begins' => $creation->created_at,
            'complete' => true,
            'applied' => 0,
            'later' => $trails->filter(
                fn (AuditTrail $t): bool => $t->created_at->greaterThan($moment)
            )->count(),
            'fields' => [],
        ];
    }

    /**
     * ওই মুহূর্তে রেকর্ডটা মোছা অবস্থায় ছিল কি না।
     *
     * শেষ ঘটনাটাই সিদ্ধান্ত দেয়: মোছার পর ফেরানো হলে সেটা আবার ছিল।
     *
     * @param  Collection<int, AuditTrail>  $upTo
     */
    private function wasDeleted(Collection $upTo): bool
    {
        $last = $upTo->last(fn (AuditTrail $trail): bool => in_array(
            $trail->action,
            [AuditTrail::DELETED, AuditTrail::RESTORED],
            true,
        ));

        return $last?->action === AuditTrail::DELETED;
    }

    /**
     * ঘরগুলো পর্দার জন্য সাজানো — নিশ্চয়তার মাত্রা সহ।
     *
     * @param  class-string  $type
     * @param  array<string, mixed>  $rewound  ওই মুহূর্তের পরে বদলানো ঘরগুলোর আগের মান
     * @return array<string, array{value: mixed, certainty: string}>
     */
    private function present(string $type, int $id, array $rewound, bool $covered): array
    {
        $today = $this->todaysValues($type, $id);
        $fields = [];

        /*
         * মডেলের ঘোষিত ঘরগুলোই তালিকা, বদলের তালিকা নয়।
         *
         * ── কেন ────────────────────────────────────────────────────
         * বদলের তালিকায় কেবল সেই ঘরগুলো আছে যাদের নিয়ে কোনোদিন কিছু
         * লেখা হয়েছে। ওটাকেই তালিকা ধরলে পর্দাটা **সম্পূর্ণ দেখাত,
         * অথচ থাকত না** — আর যে ঘরটা নেই সেটা কেউ "ছিল না" ধরে
         * নিতেন। অনুপস্থিতি আর শূন্যতা এক জিনিস নয়।
         */
        foreach ($this->declaredFields($type) as $field) {
            $changedLater = array_key_exists($field, $rewound);
            $value = $changedLater ? $rewound[$field] : ($today[$field] ?? null);

            $certainty = match (true) {
                /*
                 * অডিটে কোনোদিন যায় না — অতীত সম্পর্কে কিছুই বলা যায়
                 * না, আজকের মান থাকলেও নয়।
                 */
                in_array($field, AuditEngine::NEVER_LOGGED, true) => self::UNTRACKED,

                /* ওই মুহূর্তের পরে বদলেছে — তাই আগের মানটা লেখা আছে। */
                $changedLater => $this->emptyish($value) ? self::EMPTY_THEN : self::KNOWN,

                /*
                 * বদলের তালিকায় নেই। তখন উত্তরটা আজকের মান — কিন্তু
                 * **কেবল যদি** ওই মুহূর্ত থেকে আজ পর্যন্ত অডিট চালু
                 * ছিল। নাহলে ঘরটা অলেখা কোনো এক সময়ে বদলে থাকতে পারে,
                 * আর আজকের মান দেখানো হবে সরাসরি মিথ্যা।
                 */
                $today === null, ! $covered => self::UNTRACKED,

                default => $this->emptyish($value) ? self::EMPTY_THEN : self::KNOWN,
            };

            /*
             * অনুমতি না থাকলে অতীতেও ঢাকা।
             *
             * এটাই এই ফাইলের সবচেয়ে জরুরি অংশ: এটা না থাকলে "গত মাসে
             * কেমন ছিল" প্রশ্নটা ঘরের পাহারা টপকে যাওয়ার একটা খোলা
             * দরজা হত ([[FieldSecurity]])।
             */
            if ($certainty === self::KNOWN && ! FieldSecurity::visible($type, $field)) {
                $value = FieldSecurity::mask();
            }

            $fields[$field] = [
                'value' => $certainty === self::KNOWN ? $value : null,
                'certainty' => $certainty,
            ];
        }

        ksort($fields);

        return $fields;
    }

    private function emptyish(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * আজকের সারিটা — ভিত্তি।
     *
     * নরম-মোছা সারিও টানা হয়, কারণ অডিটের পুরো কাজটাই মোছা কাগজের
     * ইতিহাস দেখানো। আর `withoutGlobalScopes()` **ব্যবহার করা হয় না**:
     * কোম্পানির দেয়াল এখানেও দাঁড়িয়ে থাকা চাই, নাহলে এই পর্দাটাই
     * বহু-টেন্যান্ট ফাঁসের দরজা হত।
     *
     * @param  class-string  $type
     * @return array<string, mixed>|null
     */
    private function todaysValues(string $type, int $id): ?array
    {
        if (! class_exists($type)) {
            return null;
        }

        /** @var class-string<Model> $type */
        $query = $type::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($type), true)) {
            $query->withTrashed();
        }

        return $query->find($id)?->getAttributes();
    }

    /**
     * মডেলটা কোন ঘরগুলোর কথা বলে।
     *
     * `fillable` ধরা হয়, ডাটাবেজের কলাম নয় — পর্দায় যা মানুষ ভরেন
     * সেটাই প্রশ্নের বিষয়। `id`, `company_id`, ছাপ, নরম-মোছার ঘর —
     * এগুলো নিয়ে "ওইদিন কেমন ছিল" প্রশ্নটাই ওঠে না।
     *
     * @param  class-string  $type
     * @return list<string>
     */
    private function declaredFields(string $type): array
    {
        if (! class_exists($type)) {
            return [];
        }

        $model = new $type;

        return array_values(array_diff(
            $model->getFillable(),
            ['company_id', 'branch_id', 'created_by', 'updated_by'],
        ));
    }
}
