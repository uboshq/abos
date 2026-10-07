<?php

declare(strict_types=1);

namespace App\Core\Engines\Drill;

use App\Core\Contracts\Drillable;
use App\Core\Module\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Drill-down engine — সব মডিউলের drill_sources এক জায়গায় জড়ো করে,
 * তারপর যেকোনো (source_type, source_id) জোড়াকে একটা লিংকে পরিণত করে।
 *
 * এটাই নিয়ম ১-এর একমাত্র বাস্তবায়ন। কোনো রিপোর্ট নিজে থেকে "sales_invoice
 * হলে এই রুট" লিখবে না — লিখলে পরের মডিউলে সেটা আর কাজ করবে না।
 */
final class DrillResolver
{
    /** @var array<string, class-string>|null */
    private ?array $map = null;

    /**
     * এই অনুরোধে যে উৎসগুলো ইতিমধ্যে তোলা হয়েছে।
     *
     * ── ⛔ কেন, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
     * [[drill]] কম্পোনেন্ট **প্রতিটা সারিতে** `describe()` ডাকে, আর
     * সেটা প্রতিবার `find()` করত। ⓘ ১০০ সারির খতিয়ানে সেটা ১০০টা
     * কোয়েরি, আর `drillLabel()` সম্পর্ক ছুঁলে আরও ১০০।
     *
     * ⚠️ একই কাগজের একাধিক সারি সাধারণ — একটা ভাউচারের ছয়টা লাইন
     * ছয়বার একই ডকুমেন্ট চাইত। ⭐ স্মৃতিটা অন্তত ঐ পুনরাবৃত্তিটুকু
     * কাটে, আর কোনো আচরণ বদলায় না।
     *
     * ⓘ অনুরোধভিত্তিক (`scoped`), তাই পরের অনুরোধে তাজা তথ্যই আসে —
     * রিপোর্ট পড়ার মাঝখানে কেউ কাগজ বদলালে ভুল দেখানোর প্রশ্ন নেই।
     *
     * @var array<string, ?Drillable>
     */
    private array $seen = [];

    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * স্মৃতিটা ভুলিয়ে দেওয়া — যিনি কাগজ বদলান তিনি ডাকবেন।
     *
     * ⓘ আজ কোনো পর্দা এক অনুরোধে কাগজ বদলে আবার সেটার ড্রিল আঁকে না,
     * তাই প্রশ্নটা তাত্ত্বিক — কিন্তু দরজাটা খোলা রাখা হলো।
     */
    public function forget(): void
    {
        $this->seen = [];
    }

    /** @return array<string, class-string> */
    public function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = [];

        foreach ($this->registry->all() as $module) {
            foreach ($module->drillSources as $sourceType => $modelClass) {
                if (isset($map[$sourceType])) {
                    throw new RuntimeException(
                        "Two modules register the drill source '{$sourceType}': "
                        ."{$map[$sourceType]} and {$modelClass}. Source types must be unique — "
                        .'a ledger row cannot point at two different documents.'
                    );
                }

                $map[$sourceType] = $modelClass;
            }
        }

        return $this->map = $map;
    }

    public function knows(string $sourceType): bool
    {
        return isset($this->map()[$sourceType]);
    }

    /**
     * এই উৎসটা কোন মডিউলের।
     *
     * ── কেন এটা এখানে ───────────────────────────────────────────────
     * সংযুক্তি ফাইলগুলো মডিউল ধরে ফোল্ডারে বসে (attachments/৩/purchase/…),
     * আর কোন ডকুমেন্ট কোন মডিউলের সেটা জানে কেবল রেজিস্ট্রি। সংযুক্তির
     * পর্দায় ওই তালিকাটা আবার লিখলে কোর মডিউলের নাম চিনে ফেলত, আর নতুন
     * মডিউলের কাগজ রাখতে গিয়ে কোর ফাইল খুলতে হত (সেকশন ১৯.৭)।
     */
    public function moduleFor(string $sourceType): ?string
    {
        foreach ($this->registry->all() as $module) {
            if (array_key_exists($sourceType, $module->drillSources)) {
                return $module->code;
            }
        }

        return null;
    }

    /**
     * একটা লেজার/স্টক রো-এর উৎস ডকুমেন্ট।
     *
     * খুঁজে না পেলে null — কারণ ডকুমেন্ট বাতিল বা সফট-ডিলিট হয়ে থাকতে পারে,
     * আর তখন রিপোর্ট ভেঙে পড়া উচিত নয়; লিংকটা শুধু নিষ্ক্রিয় দেখাবে।
     */
    public function resolve(string $sourceType, int|string $sourceId): ?Drillable
    {
        /*
         * বিপরীত এন্ট্রিও তার মূল ডকুমেন্টে ফেরত যায়।
         *
         * PostingEngine::reverse() সারিগুলো "expense_voucher:reversal"
         * নামে বসায়, যাতে একই ডকুমেন্ট দুইবার পোস্ট হয়েছে বলে ভুল না
         * হয়। কিন্তু ওই নামটা কোনো মডিউল ঘোষণা করে না, তাই ড্রিল-ডাউনে
         * সারিগুলো "উৎস পাওয়া যাচ্ছে না" দেখাত।
         *
         * অথচ ব্যাখ্যা সবচেয়ে বেশি দরকার ঠিক ওই সারিগুলোরই: ডে বুকে
         * একটা উল্টো এন্ট্রি দেখে প্রথম প্রশ্নই হয় "এটা কীসের"। উপসর্গটা
         * ছেঁটে দিলে উত্তরটা এক ক্লিক দূরে থাকে।
         */
        $sourceType = str_contains($sourceType, ':')
            ? strtok($sourceType, ':')
            : $sourceType;

        $modelClass = $this->map()[$sourceType] ?? null;

        if ($modelClass === null) {
            return null;
        }

        /*
         * ⓘ চাবিতে ধরনটাও আছে — দুই মডিউলের দুইটা কাগজের আইডি এক হতেই
         * পারে, আর কেবল আইডি ধরে রাখলে একটা আরেকটার উত্তর দিত।
         */
        $key = $sourceType.'#'.$sourceId;

        if (array_key_exists($key, $this->seen)) {
            return $this->seen[$key];
        }

        /** @var Model|null $model */
        $model = $modelClass::query()->find($sourceId);

        if ($model === null) {
            /* ⓘ "নেই"-ও মনে রাখা হয়, নাহলে বাতিল কাগজের প্রতিটা সারি
               বারবার একই খালি খোঁজ চালাত। */
            return $this->seen[$key] = null;
        }

        if (! $model instanceof Drillable) {
            throw new RuntimeException(
                "{$modelClass} is registered as drill source '{$sourceType}' but does not implement "
                .Drillable::class.'.'
            );
        }

        return $this->seen[$key] = $model;
    }

    /**
     * লিংক বানানোর জন্য যা যা লাগে — না পাওয়া গেলে অন্তত টাইপটা দেখানো হয়,
     * যাতে ব্যবহারকারী বুঝতে পারে সংখ্যাটা কোথা থেকে এসেছে, এমনকি ডকুমেন্ট
     * আর খোলা না গেলেও।
     *
     * @return array{type: string, id: int|string, document_no: ?string, label: string, route: ?array, resolved: bool}
     */
    public function describe(string $sourceType, int|string $sourceId): array
    {
        $document = $this->resolve($sourceType, $sourceId);

        if ($document === null) {
            return [
                'type' => $sourceType,
                'id' => $sourceId,
                'document_no' => null,
                'label' => __('core.drill.unavailable', ['type' => __('core.source.'.$sourceType)]),
                'route' => null,
                'resolved' => false,
            ];
        }

        return [
            'type' => $sourceType,
            'id' => $sourceId,
            'document_no' => $document->drillDocumentNo(),
            'label' => $document->drillLabel(),
            'route' => $document->drillRoute(),
            'resolved' => true,
        ];
    }
}
