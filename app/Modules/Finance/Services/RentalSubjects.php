<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Modules\Accounts\Models\FixedAsset;
use Illuminate\Database\Eloquent\Model;

/**
 * কী ভাড়া নেওয়া — গুদাম, শাখা, গাড়ি, স্থায়ী সম্পদ।
 *
 * ── ⓘ কেন এই সেবাটা লাগল, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * মালিকের কথা: *"গোডাউনের সাথে এটার একটা লিংক করা উচিত হেড অফিসের সাথে
 * লিংক করা উচিত"*। ⛔ "কীসের জন্য" ঘরটা ছিল একটা বাক্য, তাই ভাড়ার
 * সংখ্যাটা কোনো জায়গার সাথে মেলানো যেত না।
 *
 * ⭐ আসল লাভ উল্টো দিকে: গুদামের পাতা খুলে দেখা যায় ভাড়া কত, জামানত কত,
 * চুক্তি কবে শেষ। ⚠️ যে ভাড়া কোনো জায়গার সাথে মেলে না, সেটাই ডিপোকে
 * ছেড়ে আসা গুদামের ভাড়া দিতে থাকায়।
 *
 * ── ⛔ গুদামটা এখানে নাম ধরে ডাকা যায় না ─────────────────────────────
 * ⚠️ গুদাম ইনভেন্টরির, আর অর্থ ইনভেন্টরির উপর নির্ভর করে না
 * ([[BoundariesTest]] সেটা পাহারা দেয়)। ⓘ তাই ওটা আসে কোরের drill
 * রেজিস্ট্রি থেকে — কেবল চাবির নাম ধরে (`warehouse`), ক্লাসের নাম নয়।
 * ⭐ বাড়তি লাভ: নাম আর লিংক দুইটাই ঐ মডিউলের নিজের ঘোষণা থেকে আসে,
 * তাই ওদের পর্দা সরলে এখানে কিছু বদলাতে হয় না।
 */
final class RentalSubjects
{
    /**
     * ⓘ ক্রমটা ইচ্ছাকৃত: ডিপোতে ভাড়া নেওয়া জিনিসের তালিকা এই ক্রমেই
     * লম্বা — গুদাম সবচেয়ে বেশি, তারপর অফিস, তারপর গাড়ি।
     *
     * @var list<string>
     */
    public const TYPES = ['warehouse', 'branch', 'vehicle', 'fixed_asset'];

    public function __construct(private readonly DrillResolver $drill) {}

    public function knows(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * বাছার তালিকা — "ধরন:আইডি" => "গুদাম — নাম"।
     *
     * ⓘ চ্যাপ্টা তালিকা, কারণ [[x-ui.select]] কেবল চ্যাপ্টাই নেয়; আর
     * ধরনটা লেখাতেই থাকে বলে "হেড অফিস" আর "হেড অফিস গুদাম" আলাদা পড়া যায়।
     *
     * @return array<string, string>
     */
    public function forPicker(): array
    {
        $out = [];

        foreach (self::TYPES as $type) {
            foreach ($this->rowsOf($type) as $row) {
                $out[$type.':'.$row->getKey()] = __('finance::field.rental_subject_'.$type)
                    .' — '.$this->labelOf($row);
            }
        }

        return $out;
    }

    /**
     * কী ভাড়া নেওয়া, আর তার পাতা কোথায়।
     *
     * ⓘ জিনিসটা মুছে গেলে বা না চিনলে `route` শূন্য — তখন পর্দায় কেবল
     * লেখা বসে। ⛔ ভাঙা লিংক দেখানোর চেয়ে লেখা ভালো।
     *
     * @return array{label: string|null, route: array{0: string, 1: array<string, mixed>}|null}
     */
    public function describe(?string $type, int|string|null $id): array
    {
        if ($type === null || $id === null || ! $this->knows($type)) {
            return ['label' => null, 'route' => null];
        }

        /*
         * ⓘ যে ধরনগুলো drill চেনে (গুদাম, গাড়ি), ওদের নাম আর পথ ওখান
         * থেকেই — এক জায়গায় লেখা থাকলে দুই জায়গায় আলাদা হয় না।
         */
        if ($this->drill->knows($type)) {
            $seen = $this->drill->describe($type, $id);

            return [
                'label' => $seen['resolved'] ? $seen['label'] : null,
                'route' => $seen['resolved'] ? $seen['route'] : null,
            ];
        }

        $row = $this->modelFor($type)?->newQuery()->find($id);

        if ($row === null) {
            return ['label' => null, 'route' => null];
        }

        return [
            'label' => $this->labelOf($row),

            /*
             * ⚠️ শাখার নিজের কোনো পাতা নেই (কোম্পানির সেটআপে বসে), তাই
             * নাম আছে, লিংক নেই। ⓘ পাতা বসলে কেবল এই এক জায়গায় যোগ হবে।
             */
            'route' => $row instanceof FixedAsset
                ? ['accounts.asset.show', ['asset' => $row->getKey()]]
                : null,
        ];
    }

    /** @return iterable<Model> */
    private function rowsOf(string $type): iterable
    {
        $model = $this->modelFor($type);

        if ($model === null) {
            return [];
        }

        return $model->newQuery()
            /*
             * ⓘ নিষ্ক্রিয়গুলো বাদ — যে গুদাম আর নেই, তার জন্য নতুন
             * চুক্তি লেখা হয় না। ⚠️ পুরনো চুক্তিতে বসানো থাকলে সেটা
             * তবু দেখা যায়: `describe()` তালিকা দেখে না।
             */
            ->when(
                in_array('is_active', $model->getFillable(), true),
                fn ($q) => $q->where('is_active', true),
            )
            ->when(
                in_array('company_id', $model->getFillable(), true),
                fn ($q) => $q->where('company_id', CompanyContext::id()),
            )
            ->get();
    }

    private function modelFor(string $type): ?Model
    {
        if (! $this->knows($type)) {
            return null;
        }

        if ($type === 'branch') {
            return new Branch;
        }

        if ($type === 'fixed_asset') {
            return new FixedAsset;
        }

        $class = $this->drill->map()[$type] ?? null;

        return is_string($class) && class_exists($class) && is_a($class, Model::class, true)
            ? new $class
            : null;
    }

    private function labelOf(Model $row): string
    {
        if (method_exists($row, 'drillLabel')) {
            return $row->drillLabel();
        }

        if (method_exists($row, 'name')) {
            return (string) $row->name();
        }

        return (string) ($row->getAttribute('name') ?? $row->getKey());
    }
}
