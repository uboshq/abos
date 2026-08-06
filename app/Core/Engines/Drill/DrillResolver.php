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

    public function __construct(private readonly ModuleRegistry $registry) {}

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

        /** @var Model|null $model */
        $model = $modelClass::query()->find($sourceId);

        if ($model === null) {
            return null;
        }

        if (! $model instanceof Drillable) {
            throw new RuntimeException(
                "{$modelClass} is registered as drill source '{$sourceType}' but does not implement "
                .Drillable::class.'.'
            );
        }

        return $model;
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
