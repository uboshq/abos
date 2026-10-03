<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * খতিয়ানের সারিতে যাদের নাম বসতে পারে — গ্রাহক, সরবরাহকারী।
 *
 * ── কেন এটা কোরে, অথচ কোনো মডিউলের নাম জানে না ───────────────────────
 * ভাউচারের ফর্মে পক্ষ বাছতে হলে জানা দরকার কী কী ধরনের পক্ষ আছে। ওই
 * তালিকাটা কোরে লিখে দিলে Accounts মডিউল Customer ও Supplier-এর নাম
 * জেনে ফেলত, আর নতুন কোনো পক্ষ (যেমন কর্মী) যোগ করতে গেলে কোরের ফাইল
 * খুলতে হত — সেকশন ১৯.৭ ঠিক এটাই নিষেধ করে।
 *
 * তাই তালিকাটা আসে মডিউলের নিজের ঘোষণা থেকে (`module.php`-র `parties`),
 * আর কোর কেবল সেগুলো জড়ো করে।
 *
 * ── কেন `drill_sources`-এর সাথে জোড়া ────────────────────────────────
 * পক্ষের নাম, তার পাতার লিংক — দুইটাই drill source আগে থেকেই জানে।
 * আলাদা করে আরেকটা মানচিত্র বানালে একদিন একটায় নাম বদলাত আর অন্যটায়
 * নয়।
 */
final class PartyRegistry
{
    /** @var array<string, string>|null */
    private ?array $labels = null;

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly DrillResolver $drill,
    ) {}

    /**
     * ধরন => লেবেলের অনুবাদ-কী।
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->labels !== null) {
            return $this->labels;
        }

        $labels = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->parties as $type => $label) {
                $labels[$type] = $label;
            }
        }

        return $this->labels = $labels;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->all());
    }

    public function knows(string $type): bool
    {
        return array_key_exists($type, $this->all());
    }

    public function labelFor(string $type): ?string
    {
        $key = $this->all()[$type] ?? null;

        return $key === null ? null : __($key);
    }

    /**
     * এই ধরনের একটা পক্ষ সত্যিই আছে কি না — এই কোম্পানিতে।
     *
     * ── কেন কোম্পানি ধরে দেখা হয় ────────────────────────────────────
     * মডেলগুলোয় কোম্পানির গ্লোবাল স্কোপ বসানো, তাই সাধারণ কোয়েরিই
     * নিজের কোম্পানির বাইরে যায় না। তবু ধরে নেওয়া হয় না: স্কোপটা
     * কোনোদিন সরলে এই যাচাইটাই শেষ পাহারা, আর ততক্ষণে অন্য কোম্পানির
     * গ্রাহকের নাম আপনার খতিয়ানে বসে যেত।
     */
    public function exists(string $type, int $id): bool
    {
        $model = $this->modelFor($type);

        if ($model === null || $id <= 0) {
            return false;
        }

        return $model::query()
            ->whereKey($id)
            ->when(
                in_array('company_id', $model->getFillable(), true),
                fn ($q) => $q->where('company_id', CompanyContext::id()),
            )
            ->exists();
    }

    /**
     * পর্দায় বাছার মতো তালিকা — ধরন ধরে সাজানো।
     *
     * ── কেন সবগুলো একবারে পাঠানো হয় ─────────────────────────────────
     * জাবেদার সারি ব্রাউজারেই যোগ হয়, তাই প্রতিটা নতুন সারির জন্য
     * সার্ভারে গেলে ভাউচার লেখা ধীর হত। ডিপোর গ্রাহক-সরবরাহকারী
     * মিলিয়ে কয়েকশো — এক পাতায় পাঠানো যায়।
     *
     * তালিকা হাজারে গেলে এটা আর চলবে না; তখন সার্ভারে খোঁজা ফিরবে।
     * অনুমানটা এখানে লেখা রইল, যাতে সীমাটা কেউ আবিষ্কার না করে।
     *
     * @return list<array{type: string, label: string, options: list<array{id: int, label: string}>}>
     */
    public function forPicker(): array
    {
        $groups = [];

        foreach ($this->all() as $type => $labelKey) {
            $model = $this->modelFor($type);

            if ($model === null) {
                continue;
            }

            $rows = $model::query()
                ->when(
                    in_array('is_active', $model->getFillable(), true),
                    fn ($q) => $q->where('is_active', true),
                )
                /*
                 * ⭐ হেডারে বাছা শাখার পক্ষ — মালিক, ১ অক্টোবর ২০২৬: *"প্রতিটা শাখা পুরো আলাদা"*।
                 * ⛔ চেক, নোট, হাত-ঋণ, ভাড়া, জমা আর সম্পদের পিকার এখান থেকে সব শাখার গ্রাহক-সরবরাহকারী পেত।
                 * ⓘ কোর মডিউলের নাম জানে না, তাই যে পক্ষের মডেল নিজে `inViewedBranch` দেয় তাকেই ছাঁকা হয়
                 * ([[Customer::scopeInViewedBranch()]]); বাকিদের আচরণ আগের মতো।
                 */
                ->when(
                    method_exists($model, 'scopeInViewedBranch'),
                    fn ($q) => $q->inViewedBranch(),
                )
                // ⓘ পয়েন্টের নাম খোঁজার জন্য — নিচের [[pickerHint()]]; সারি প্রতি একটা প্রশ্ন নয়
                ->when(
                    method_exists($model, 'location'),
                    fn ($q) => $q->with('location'),
                )
                ->get();

            /*
             * ⭐ এক ধরনের ভেতরে দুই রকম — মালিক, ২ অক্টোবর ২০২৬: সরবরাহকারী আর সেবাদাতা এক নয়।
             * ⓘ কোর মডিউলের নাম জানে না, তাই মডেল নিজে বলে কোন সারির পাশে কী লেখা ([[Supplier::pickerNotes()]]):
             * নোট-ওয়ালা সারি নামের পাশে "(সেবাদাতা)", আর তালিকায় পরে; নোট ছাড়া সারি আগে। ধরন একটাই থাকে —
             * কাগজে `party_type` বদলায় না, তাই পুরনো ভাউচার আর পর্দার গঠন অটুট।
             */
            $notes = method_exists($model, 'pickerNotes') ? $model::pickerNotes() : [];

            $groups[] = [
                'type' => $type,
                'label' => __($labelKey),
                'options' => $rows
                    ->map(function (Model $row) use ($notes) {
                        $label = method_exists($row, 'drillLabel') ? $row->drillLabel() : (string) $row->getKey();
                        $note = $notes[(int) $row->getKey()] ?? null;

                        $hint = $this->pickerHint($row);

                        return [
                            'id' => (int) $row->getKey(),
                            'label' => $note === null ? $label : $label.' ('.$note.')',
                            'note' => $note,
                            'hint' => $hint,
                            'find' => $this->pickerFind($row, $label, $hint),
                        ];
                    })
                    ->sortBy([
                        fn (array $a, array $b) => ($a['note'] !== null) <=> ($b['note'] !== null),
                        fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $groups;
    }

    /**
     * অনেকগুলো পক্ষের নাম একবারে — ধরন প্রতি একটা প্রশ্ন।
     *
     * ⓘ ভাউচারের তালিকায় "কার কাছ থেকে / কাকে" কলামের জন্য, ১৯ সেপ্টেম্বর
     * ২০২৬। ⚠️ সারি প্রতি একটা প্রশ্ন চালালে পঞ্চাশ সারির পাতায় পঞ্চাশটা।
     *
     * @param  iterable<array{0: string, 1: int}>  $pairs  [ধরন, id]
     * @return array<string, string>  "ধরন:id" => নাম
     */
    public function labelsOf(iterable $pairs): array
    {
        $byType = [];

        foreach ($pairs as [$type, $id]) {
            if ($type !== null && $type !== '' && (int) $id > 0) {
                $byType[$type][] = (int) $id;
            }
        }

        $labels = [];

        foreach ($byType as $type => $ids) {
            $model = $this->modelFor($type);

            if ($model === null) {
                continue;
            }

            foreach ($model::query()->whereKey(array_unique($ids))->get() as $row) {
                $labels[$type.':'.$row->getKey()] = method_exists($row, 'drillLabel')
                    ? $row->drillLabel()
                    : (string) $row->getKey();
            }
        }

        return $labels;
    }

    /**
     * ⭐ অনেকগুলো পক্ষের **পাতার ঠিকানা** একবারে — নামের পাশাপাশি।
     *
     * ── কেন নামের সাথে ঠিকানাটাও ────────────────────────────────────
     * মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬: *"সব জায়গায় হাইপার লিংক দেওয়ার
     * কথা, but notun kaje kotaw hyperlink dicche na"*। ⚠️ রিপোর্টগুলোতে
     * পক্ষের নাম বসত [[labelsOf]] দিয়ে, আর নাম দিয়ে কোনো পাতায় যাওয়া
     * যায় না — তাই প্রতিটা রিপোর্টে ঐ ঘরগুলো মরা থাকত।
     *
     * ⛔ প্রতিটা রিপোর্টে "সরবরাহকারী হলে এই রুট" লেখা হয়নি — ঠিকানাটা
     * আসে মডেলের নিজের `drillRoute()` থেকে, অর্থাৎ ঐ একটাই মানচিত্র
     * ([[App\Core\Contracts\Drillable]])। নতুন ধরনের পক্ষ যোগ হলে
     * রিপোর্টগুলোতে কিছু বদলাতে হয় না।
     *
     * @param  iterable<array{0: string, 1: int}>  $pairs  [ধরন, id]
     * @return array<string, array{0: string, 1: array<string, mixed>}> "ধরন:id" => [রুট, ঘর]
     */
    public function routesOf(iterable $pairs): array
    {
        $byType = [];

        foreach ($pairs as [$type, $id]) {
            if ($type !== null && $type !== '' && (int) $id > 0) {
                $byType[$type][] = (int) $id;
            }
        }

        $routes = [];

        foreach ($byType as $type => $ids) {
            $model = $this->modelFor($type);

            if ($model === null) {
                continue;
            }

            foreach ($model::query()->whereKey(array_unique($ids))->get() as $row) {
                if (! method_exists($row, 'drillRoute')) {
                    continue;
                }

                $route = $row->drillRoute();

                /*
                 * ⚠️ রুটটা সত্যিই নিবন্ধিত কি না দেখে নেওয়া হয় — মডিউলটা
                 * বন্ধ থাকলে রুটও থাকে না, আর তখন `route()` ছুঁড়ে ফেলত
                 * আর গোটা রিপোর্টটাই ৫০০ হয়ে যেত।
                 */
                if (isset($route[0]) && Route::has($route[0])) {
                    $routes[$type.':'.$row->getKey()] = [$route[0], $route[1] ?? []];
                }
            }
        }

        return $routes;
    }

    /**
     * নামের নিচের ছোট লাইন — কোড · পয়েন্ট · মোবাইল।
     *
     * ⭐ মালিক, ৩ অক্টোবর ২০২৬: রসিদের ডিপোজিটরের নামের তালিকায় খোঁজার ঘর
     * নেই, আর ইউবি-তে ৪১৪ জন গ্রাহক — *নাম খুঁজে পাওয়া যায় না*। ⚠️ তালিকায়
     * একই নামের দুইটা দোকানও আছে (দুইটা "M/S. Bismillah Store") — নাম
     * একা তাঁদের আলাদা করে না, কোড আর পয়েন্ট করে।
     *
     * ⓘ কোর মডিউলের নাম জানে না, তাই কেবল সাধারণ ঘরগুলো পড়া হয়: যে
     * মডেলে `code`, `phone`/`mobile` বা `location` আছে, তারটাই বসে।
     */
    private function pickerHint(Model $row): string
    {
        return collect([
            $row->getAttribute('code'),
            $this->placeOf($row),
            $row->getAttribute('phone') ?? $row->getAttribute('mobile'),
        ])->filter(fn ($v) => filled($v))->map(fn ($v) => (string) $v)->implode(' · ');
    }

    /**
     * পয়েন্টের নিজের নাম — কেবল এক ধাপ, পুরো পথ নয়।
     *
     * ⛔ `drillLabel()` নয়: সে উপরের সাত ধাপ হেঁটে পথ বানায়, আর এখানে ঐ
     * শিকল তোলা নেই — উন্নয়নে LazyLoadingViolation, চালু সার্ভারে ৪১৪
     * সারিতে নীরব N+1। ⓘ তালিকায় দুইটা নাম আলাদা করতে পয়েন্টই যথেষ্ট।
     */
    private function placeOf(Model $row): ?string
    {
        $place = $row->relationLoaded('location') ? $row->getRelation('location') : null;

        if (! $place instanceof Model) {
            return null;
        }

        $bn = $place->getAttribute('name_bn');

        return app()->getLocale() === 'bn' && filled($bn) ? (string) $bn : $place->getAttribute('name_en');
    }

    /**
     * খোঁজার লেখা — নাম দুই ভাষাতেই, আর কোড · পয়েন্ট · মোবাইল।
     *
     * ⚠️ দুই ভাষার নাম কারণ পর্দায় একটাই দেখা যায়: ইংরেজিতে টাইপ করা
     * ক্যাশিয়ার বাংলা নামের গ্রাহককে খুঁজে পেতেন না (কাউন্টারের একই পাঠ,
     * [[direct-sale.js]] `customerMatches`)।
     */
    private function pickerFind(Model $row, string $label, string $hint): string
    {
        $place = $row->relationLoaded('location') ? $row->getRelation('location') : null;

        return mb_strtolower(collect([
            $label,
            $row->getAttribute('name_en'),
            $row->getAttribute('name_bn'),
            $hint,
            $place instanceof Model ? $place->getAttribute('name_en') : null,
            $place instanceof Model ? $place->getAttribute('name_bn') : null,
        ])->filter(fn ($v) => filled($v))->map(fn ($v) => (string) $v)->unique()->implode(' '));
    }

    /**
     * কাগজে পক্ষের পরিচয় — নাম, কোড, ঠিকানা, ফোন (ডেবিট/ক্রেডিট নোটের ছাপা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ কোর মডিউলের নাম জানে না, তাই মডেলের নিজের পদ্ধতি বা ঘর যা থাকে তাই — `name()`/`drillLabel()`, `code`,
     * `address()`/`address`, `mobile`/`phone`। না থাকলে খালি; অন্য কোম্পানির সারি কখনো নয়।
     *
     * @return array{name: string, code: string, address: string, phone: string}|null
     */
    public function paperFacts(string $type, int $id): ?array
    {
        $model = $this->modelFor($type);

        if ($model === null || $id <= 0) {
            return null;
        }

        $row = $model::query()
            ->whereKey($id)
            ->when(
                in_array('company_id', $model->getFillable(), true),
                fn ($q) => $q->where('company_id', CompanyContext::id()),
            )
            ->first();

        if ($row === null) {
            return null;
        }

        $name = method_exists($row, 'name') ? (string) $row->name()
            : (method_exists($row, 'drillLabel') ? (string) $row->drillLabel() : (string) $row->getKey());
        $address = method_exists($row, 'address') ? (string) $row->address() : (string) ($row->getAttribute('address') ?? '');

        return [
            'name' => $name,
            'code' => (string) ($row->getAttribute('code') ?? ''),
            'address' => $address,
            'phone' => (string) ($row->getAttribute('mobile') ?? $row->getAttribute('phone') ?? ''),
        ];
    }

    private function modelFor(string $type): ?Model
    {
        if (! $this->knows($type)) {
            return null;
        }

        $class = $this->drill->map()[$type] ?? null;

        return $class === null ? null : new $class;
    }
}
