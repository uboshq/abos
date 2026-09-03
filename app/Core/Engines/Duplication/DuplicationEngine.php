<?php

declare(strict_types=1);

namespace App\Core\Engines\Duplication;

use App\Core\Services\DuplicateGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * একই জিনিস দুইবার ঢোকা ঠেকানো — যন্ত্র এক, দরজা অনেক।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * [[DuplicateGuard]] অনেক দিন ধরেই আছে, কিন্তু ডাকত কেবল গ্রাহক ও
 * সরবরাহকারী। পণ্য ডাকত না — তাই লাইভে হুবহু এক নামে দুইটা পণ্য বসে
 * গিয়েছিল, দুইটাতেই আলাদা মজুদ, আর "মোট কত আছে" প্রশ্নের ভুল উত্তর।
 *
 * ইঞ্জিনটা সেই যন্ত্রকেই মডিউলের ঘোষণার ([[DuplicationRegistry]]) সাথে
 * জোড়ে: সার্ভিস আর হাতে কলাম দেয় না, শুধু বলে "এই মডেলের এই ডেটা" —
 * নিয়মটা রেজিস্ট্রি থেকে আসে। ফলে নতুন মাস্টারের দরজা লাগানো এক লাইন,
 * আর একটা গার্ড দাবি করতে পারে নাম-ওয়ালা প্রতিটা মাস্টার ঘোষিত।
 *
 * ── ফোন আটকায়, নাম কেবল সতর্ক করে ───────────────────────────────────
 * একই ফোন = প্রায় নিশ্চিতভাবে একই পক্ষ, তাই হার্ড-ব্লক। একই নাম সেটা নয়
 * ("রহিম স্টোর" দুই বাজারে দুইটা), তাই সতর্ক করে থামে — ব্যবহারকারী
 * `allow_duplicate` দিয়ে জেনেশুনে এগোতে পারেন। বিশ্বের চারটা বড় ERP-ও
 * এই পথেই চলে (warn + override), হার্ড-ব্লক নয়।
 */
final class DuplicationEngine
{
    public function __construct(
        private readonly DuplicationRegistry $registry,
        private readonly DuplicateGuard $guard,
    ) {}

    /**
     * ঢোকার সময় নকল-পরীক্ষা — মডেল ঘোষিত না হলে চুপচাপ ছেড়ে দেয়।
     *
     * `$data` রেফারেন্সে: `allow_duplicate` একটা সিদ্ধান্তের ঘর, রেকর্ডের
     * কলাম নয় — শেষে মুছে ফেলা হয়, নাহলে mass-assignment-এ ছুঁড়ে ফেলত।
     *
     * @param  class-string  $model
     * @param  array<string, mixed>  $data
     */
    public function check(string $model, array &$data, ?int $exceptId = null): void
    {
        $rule = $this->registry->for($model);

        // সিদ্ধান্তের ঘরটা সবসময় সরানো হয়, নিয়ম থাক বা না-থাক — নাহলে
        // যে মডেল ঘোষিত নয় তার ডেটায় ওটা থেকে গিয়ে সংরক্ষণ ভাঙত
        $allowed = (bool) ($data['allow_duplicate'] ?? false);
        unset($data['allow_duplicate']);

        if ($rule === null) {
            return;
        }

        // ফোন — শক্ত সংকেত, আটকায়। প্রথম ভরা ফোন-ঘরটা ধরে দেখা হয়।
        if ($rule['phone'] !== []) {
            $this->guard->assertPhoneIsFree(
                $model,
                $rule['phone'],
                $this->firstFilled($data, $rule['phone']),
                $exceptId,
            );
        }

        if ($allowed) {
            return;
        }

        $matches = $this->nameMatches($model, $rule['name'], $data, $exceptId);

        if ($matches->isNotEmpty()) {
            throw ValidationException::withMessages([
                $rule['name'][0] => __('core.duplicate.name_matches').' '.__('core.duplicate.confirm_hint'),
            ]);
        }
    }

    /**
     * দুই ভাষার নাম — দুইটাই needle।
     *
     * ── কেন প্রতিটা ঘর আলাদা করে দেখা ───────────────────────────────
     * DuplicateGuard::nameMatches() একটা needle নেয় আর সেটাকে সব ঘরের
     * সাথে মেলায়। কিন্তু এখানে ঢোকা রেকর্ডেরও দুইটা নাম (name_en, name_bn)।
     * শুধু name_en পাঠালে বাংলা-only ব্যবহারকারীর নকল ধরা পড়ত না —
     * আর এই রিপোতে বাংলা-first এন্ট্রি সাধারণ। তাই ভরা প্রতিটা নাম-ঘরকে
     * আলাদা needle ধরে দেখা হয়, প্রতিটা বিদ্যমান সারির দুই নামের বিপরীতে।
     *
     * @param  class-string  $model
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $data
     * @return Collection<int, Model>
     */
    private function nameMatches(string $model, array $columns, array $data, ?int $exceptId): Collection
    {
        $found = collect();

        foreach ($columns as $column) {
            $value = $data[$column] ?? null;

            if (blank($value)) {
                continue;
            }

            $found = $found->merge(
                $this->guard->nameMatches($model, $columns, (string) $value, $exceptId),
            );
        }

        // একই সারি দুই needle-এ এলে একবারই গোনা হয়
        return $found->unique(static fn ($row) => $row->getKey())->values();
    }

    /**
     * তালিকার প্রথম ভরা ঘরের মান।
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $columns
     */
    private function firstFilled(array $data, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (filled($data[$column] ?? null)) {
                return (string) $data[$column];
            }
        }

        return null;
    }
}
