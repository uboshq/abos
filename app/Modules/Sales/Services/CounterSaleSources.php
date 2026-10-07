<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Contracts\CounterSaleSource;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;

/**
 * কাউন্টারের উৎসগুলো — চাবি থেকে কাগজ (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ+চ)।
 *
 * ── ⭐ কেন একটা খাতা ─────────────────────────────────────────────────
 * ঠিকানায় আসে কেবল `?source=do&source_id=12`। কাউন্টার কোনো উৎসের ভিতর জানে না ([[CounterSaleSource]]) —
 * চাবি থেকে কোন মডেল, তা এখানেই একবার লেখা। প্রথম উৎস ডেলিভারি অর্ডার (abos-2c, চাবি `do`)।
 *
 * ⚠️ DeliveryOrder `class_exists` আর চুক্তি মানে কি না দেখে তবেই খাতায় — মডেলটা আসার আগের প্রতিটা অবস্থাও
 * চলে (আর আসার পরে নিজে থেকেই জ্বলে ওঠে)। ⓘ পরীক্ষা নিজের নকল উৎস বসায় [[extend()]] দিয়ে।
 *
 * ⛔ দেয়াল মডেলের নিজের স্কোপে — কোম্পানি আর শাখা। অন্যের কাগজ এখানে "নেই" ([[find()]] `null`), তাই দরজা ৪০৪।
 */
final class CounterSaleSources
{
    /** ডেলিভারি অর্ডারের মডেল — নাম লেখা, `use` নয়: ক্লাসটা না থাকলেও এই ফাইল চলে */
    private const DELIVERY_ORDER = 'App\\Modules\\Sales\\Models\\DeliveryOrder';

    /** @var array<string, class-string> পরীক্ষার (বা পরের উৎসের) বাড়তি চাবি */
    private static array $extra = [];

    /** ⓘ বাড়তি উৎস — চাবি আর মডেল; একই চাবি আবার দিলে নতুনটা জেতে */
    public static function extend(string $key, string $class): void
    {
        self::$extra[$key] = $class;
    }

    public static function forget(string $key): void
    {
        unset(self::$extra[$key]);
    }

    /** @return array<string, class-string> চাবি → মডেল, কেবল যারা সত্যিই চুক্তি মানে */
    public function map(): array
    {
        $map = [];
        $do = self::DELIVERY_ORDER;

        if (class_exists($do) && is_subclass_of($do, CounterSaleSource::class)) {
            $map[$do::counterSourceKey()] = $do;
        }

        // ⭐ বিক্রয় আদেশ — চাবি `so` (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৬); খোলা DO শেষ না হওয়া পর্যন্ত দুইটাই
        $map[\App\Modules\Sales\Models\SalesOrder::counterSourceKey()] = \App\Modules\Sales\Models\SalesOrder::class;

        foreach (self::$extra as $key => $class) {
            $map[$key] = $class;
        }

        return array_filter($map, fn (string $class) => class_exists($class)
            && is_subclass_of($class, CounterSaleSource::class)
            && is_subclass_of($class, Model::class));
    }

    public function knows(string $key): bool
    {
        return array_key_exists($key, $this->map());
    }

    /**
     * চাবি আর আইডি থেকে উৎস — মডেলের নিজের স্কোপে (কোম্পানি, শাখা)। না থাকলে, বা অন্যের হলে, `null`।
     */
    public function find(string $key, int $id): ?CounterSaleSource
    {
        $class = $this->map()[$key] ?? null;

        if ($class === null || $id <= 0) {
            return null;
        }

        $found = $class::query()->find($id);

        return $found instanceof CounterSaleSource ? $found : null;
    }

    /**
     * এই বিলটা কোন উৎস থেকে — খসড়া বিল নিজের উৎস মনে রাখে (`counter_source`, `counter_source_id`)।
     *
     * ⚠️ শাখার সীমা ছাড়া, কোম্পানির দেয়াল রেখে: শেষ সইয়ের পরে বিক্রি শেষ করেন বানানেওয়ালা
     * ([[HeldCounterSaleFinisher]]); তাঁর দেখার শাখা বদলে গেলেও বিলের উৎস হারানো চলে না। ⛔ কোম্পানি না মিললে `null`।
     */
    public function forInvoice(SalesInvoice $invoice): ?CounterSaleSource
    {
        $key = (string) ($invoice->counter_source ?? '');
        $id = (int) ($invoice->counter_source_id ?? 0);
        $class = $this->map()[$key] ?? null;

        if ($key === '' || $id <= 0 || $class === null) {
            return null;
        }

        $query = method_exists($class, 'acrossBranches') ? $class::acrossBranches() : $class::query();
        $found = $query->find($id);

        if (! $found instanceof CounterSaleSource) {
            return null;
        }

        $company = $found->getAttribute('company_id');

        return $company !== null && (int) $company !== (int) $invoice->company_id ? null : $found;
    }

    /** @return array{key: string, id: int} বিলে যা লেখা হয় */
    public function identify(CounterSaleSource $source): array
    {
        /** @var Model&CounterSaleSource $source */
        return ['key' => $source::counterSourceKey(), 'id' => (int) $source->getKey()];
    }
}
