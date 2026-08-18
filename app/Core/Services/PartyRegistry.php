<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;

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
                ->get();

            $groups[] = [
                'type' => $type,
                'label' => __($labelKey),
                'options' => $rows
                    ->map(fn (Model $row) => [
                        'id' => (int) $row->getKey(),
                        'label' => method_exists($row, 'drillLabel')
                            ? $row->drillLabel()
                            : (string) $row->getKey(),
                    ])
                    ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all(),
            ];
        }

        return $groups;
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
