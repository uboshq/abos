<?php

declare(strict_types=1);

namespace App\Core\Engines\Coding;

use App\Core\Support\CodeFromName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * খালি কোডের ঘরে কী বসবে — মালিকের নিয়ম, ২ সেপ্টেম্বর ২০২৬।
 *
 * ── নিয়মটা এক লাইনে ─────────────────────────────────────────────────
 * **কোড সবসময় নিজে থেকে বসবে, আর মানুষ সবসময় বদলাতে পারবেন।**
 * অর্থাৎ কোড না লেখার কারণে কোনো সংরক্ষণ আটকাবে না, আর নিজের কোড
 * থাকলে সেটাই থাকবে।
 *
 * ── কেন [[NumberSeriesEngine]] দিয়ে সবটা হয় না ───────────────────────
 * ওই ইঞ্জিনটা ডকুমেন্টের নম্বর দেয় — `INV-2026-0001`। বিলের নম্বর হিসেবে
 * ওটা নিখুঁত, কারণ বিলের নম্বরের কাজই ক্রম বোঝানো।
 *
 * কিন্তু মালিকের নির্দেশ ছিল স্পষ্ট: **কখনোই `ACC-0001` বা `UNIT-0001`
 * নয়**। কারণটা কাগজে:
 *
 *   হিসাবের কোড `১০১০` একটা সংখ্যা নয়, **একটা ঠিকানা** — প্রথম অঙ্ক
 *   বলে সম্পদ না দায় না আয় না ব্যয়, পরেরগুলো বলে কোন শাখায়।
 *   অভিজ্ঞ হিসাবরক্ষক ওই কাঠামো ধরেই খাতা পড়েন। `ACC-0007` দিলে
 *   কোডটা আর কিছুই বলে না, শুধু গোনে।
 *
 *   এককের কোড `KG`, মুদ্রার `BDT` — **আন্তর্জাতিক মান**, আর ওগুলো
 *   চালানে ছাপা হয়। `UNIT-0003` ছাপা একটা চালান বাইরের কাউকে দেখানো
 *   যায় না।
 *
 * তাই তিনটা আলাদা কৌশল, আর কোন জিনিসে কোনটা — সেটা **মডিউল ঠিক করে**,
 * কোর নয় (§১৯.৭)। কোর কেবল কৌশলগুলো দেয়:
 *
 *   fromName()      নাম থেকে সংক্ষিপ্ত রূপ — একক, মুদ্রা, ব্র্যান্ড, বিভাগ
 *   underParent()   অভিভাবকের নিচে পরের খালি নম্বর — হিসাব তালিকা
 *   [[NumberSeriesEngine]]  ক্রমিক সিরিজ — বিল, ভাউচার, গ্রাহক, পণ্য
 *
 * ── কেন `Model` ক্লাস-নাম নেওয়া হয়, টেবিলের নাম নয় ──────────────────
 * অনন্যতা যাচাই করতে হয় **কোম্পানির ভেতরে**, আর সেই সীমাটা আসে
 * [[BelongsToCompany]]-র গ্লোবাল স্কোপ থেকে। কাঁচা টেবিলে কোয়েরি করলে
 * স্কোপটা বাদ পড়ত, আর তখন এক কোম্পানির `KG` অন্য কোম্পানিতে `KG2`
 * বানাত — অথচ ওদের কেউ কারও কোড দেখেই না।
 */
final class CodeSuggester
{
    /**
     * একই কোড দ্বিতীয়বার বসলে কতবার চেষ্টা করা হবে।
     *
     * ৫০-এ থামে, আর থামলে খালি ফেরত দেয়, ব্যতিক্রম নয়: কোড বসানো
     * একটা **সুবিধা**, আর সুবিধা কখনো সংরক্ষণ আটকাতে পারে না। যিনি
     * পঞ্চাশটা "KG" বানিয়েছেন তাঁকে বরং নিজের কোড লিখতে দেওয়া ভালো।
     */
    private const TRIES = 50;

    /**
     * কোড কলামটা কত চওড়া — টেবিল দেখে, অনুমান করে নয়।
     *
     * ── কেন মাপা হয় ─────────────────────────────────────────────────
     * `mdm_currencies.code` **varchar(8)**, `mdm_brands.code`
     * varchar(32)। একটা স্থির সীমা বসালে হয় মুদ্রায় লম্বা কোড গিয়ে
     * ইনসার্ট ভাঙত, নয় ব্র্যান্ডের কোড অকারণে কাটা পড়ত।
     *
     * ডাকার পক্ষের কাছে সংখ্যাটা চাওয়া হয়নি ইচ্ছাকৃতভাবে: তাহলে
     * প্রতিটা মডিউলকে নিজের কলামের প্রস্থ মনে রাখতে হত, আর মাইগ্রেশনে
     * প্রস্থ বদলালে সেই সংখ্যাটা নীরবে ভুল হয়ে যেত।
     *
     * @var array<class-string, int>
     */
    private array $widths = [];

    /**
     * নাম থেকে কোড — `Kilogram` → `KG`, `Sachet` → `SAC`।
     *
     * ── এটা [[CodeFromName]]-এর বদলি নয়, তার উপরে একটা স্তর ─────────
     * অক্ষর বের করার কাজটা ২০২৬-০৮-০৯ থেকে [[CodeFromName]] করে, আর
     * সেটা এখানে **ডাকা হয়, নকল করা হয় না**। ওর নিয়মটা (নামের গোড়া
     * থেকে তিন অক্ষর, আদ্যক্ষর নয়) একটা মাপা সিদ্ধান্ত — তালিকার
     * বেশিরভাগ নামই এক শব্দের, আর এক শব্দে আদ্যক্ষরের নিয়ম ভেঙে পড়ে।
     *
     * এই স্তরটা যোগ করে ঠিক দুইটা জিনিস:
     *
     *   ১. **অভিধান** — `Piece` → `PCS`, `Kilogram` → `KG`,
     *      `Bangladeshi Taka` → `BDT`। অক্ষরের নিয়মে এগুলো `PIE`,
     *      `KIL`, `BAN` হয়, আর মালিক ২ সেপ্টেম্বর ২০২৬-এ স্পষ্ট
     *      বলেছেন এগুলো **ওই প্রচলিত রূপেই** বসতে হবে। `PCS` "Piece"-এর
     *      কোনো অংশ নয় — ওটা প্রথা, নিয়ম নয়, তাই অভিধান ছাড়া উপায় নেই।
     *
     *   ২. **মুছে ফেলা সারিও গোনা** — [[CodeSuggester::isFree()]] দেখুন।
     *
     * ── কেন অভিধানটা কোরে নয় ────────────────────────────────────────
     * "Piece মানে PCS" একটা **ব্যবসায়িক** কথা। অভিধানটা ডাকার সময়
     * পাঠাতে হয়, আর সেটা থাকে যে মডিউল জিনিসটার মালিক তার কাছে —
     * [[MasterData\Support\CodeConventions]] (§১৯.৭)।
     *
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $dictionary  UPPERCASE নাম => কোড
     */
    public function fromName(
        string $model,
        ?string $name,
        array $dictionary = [],
        ?string $fallbackPrefix = null,
    ): string {
        $clean = trim(Str::upper(preg_replace('/\s+/', ' ', (string) $name) ?? ''));

        if ($clean !== '' && isset($dictionary[$clean])) {
            return $this->makeUnique($model, $dictionary[$clean], $this->widthFor($model));
        }

        $derived = CodeFromName::suggest(
            (string) $name,
            fn (string $code): bool => ! $this->isFree($model, $code),
        );

        if ($derived !== '') {
            return $derived;
        }

        return $fallbackPrefix === null
            ? ''
            : $this->makeUnique($model, Str::upper($fallbackPrefix), $this->widthFor($model));
    }

    /**
     * অভিভাবক খাতের নিচে পরের খালি নম্বর — `১১৩০`-এর পরে `১১৩১`।
     *
     * ── ধাপটা কোথা থেকে আসে ─────────────────────────────────────────
     * ভাইদের দেখে। `1100 · 1200 · 1300` থাকলে পরেরটা `1400`; `1101 ·
     * 1102` থাকলে `1103`। **ধাপটা অনুমান করা হয় না, মাপা হয়** — সবচেয়ে
     * বড় দুইটা ভাইয়ের ব্যবধান। একটা স্থির ধাপ বসালে যে কোম্পানি শতকে
     * গোনে তার খাতা ভেঙে যেত।
     *
     * ── প্রথম সন্তান ────────────────────────────────────────────────
     * ভাই না থাকলে অভিভাবকের কোড ধরে: দলের নিচে দল হলে `+১০০`
     * (`1000` → `1100`), দলের নিচে পাতা হলে `+১` (`1100` → `1101`)।
     * এটাই ABOS-এর নিজের ছকে দেখা যায়, আর নতুন কোম্পানিও ওই ছক
     * দিয়েই শুরু করে।
     *
     * ── অঙ্কের সংখ্যা ধরে রাখা ───────────────────────────────────────
     * `0100`-এর পরে `0101`, `101` নয়। শূন্য হারালে কোডগুলো টেক্সট
     * হিসেবে ভুল ক্রমে সাজত, আর খাতার প্রতিটা প্রতিবেদন কোড ধরেই সাজে।
     *
     * @param  class-string<Model>  $model
     */
    public function underParent(
        string $model,
        ?Model $parent,
        bool $isGroup = false,
    ): string {
        $max = $this->widthFor($model);

        $siblings = $model::query()
            ->when(
                $parent !== null,
                fn ($q) => $q->where('parent_id', $parent->getKey()),
                fn ($q) => $q->whereNull('parent_id'),
            )
            ->pluck('code')
            ->map(static fn ($c): string => (string) $c)
            ->filter(static fn (string $c): bool => $c !== '' && ctype_digit($c))
            ->map(static fn (string $c): array => ['n' => (int) $c, 'w' => Str::length($c)])
            ->sortBy('n')
            ->values();

        if ($siblings->isNotEmpty()) {
            $last = $siblings->last();
            $width = $last['w'];

            $step = $siblings->count() > 1
                ? max(1, $last['n'] - $siblings[$siblings->count() - 2]['n'])
                : 1;

            return $this->firstFree($model, $last['n'] + $step, $step, $width, $max);
        }

        $parentCode = (string) ($parent?->code ?? '');

        if ($parentCode !== '' && ctype_digit($parentCode)) {
            $step = $isGroup ? 100 : 1;

            return $this->firstFree(
                $model,
                (int) $parentCode + $step,
                $step,
                Str::length($parentCode),
                $max,
            );
        }

        /*
         * উপরের কোনোটাই না মিললে — অর্থাৎ খাতাটা একেবারে খালি, বা
         * কোডগুলো সংখ্যা নয় — তখন গোটা তালিকার সবচেয়ে বড় সংখ্যাটা ধরে
         * পরের হাজার। নতুন কোম্পানির প্রথম খাত `1000` পায়, যেটা
         * ABOS-এর নিজের ছকের প্রথম কোডও।
         */
        $highest = $model::query()
            ->pluck('code')
            ->map(static fn ($c): string => (string) $c)
            ->filter(static fn (string $c): bool => ctype_digit($c))
            ->map(static fn (string $c): int => (int) $c)
            ->max();

        return $this->firstFree($model, $highest === null ? 1000 : ((int) floor($highest / 1000) + 1) * 1000, 1000, 4, $max);
    }

    /**
     * এই কোডটা কি এই কোম্পানিতে খালি?
     *
     * `withTrashed()` ইচ্ছাকৃত: মুছে ফেলা সারিও কোডটা ধরে রাখে, কারণ
     * ABOS-এ মোছা মানে soft delete — সারিটা ফিরিয়ে আনা যায়, আর ফিরে
     * এসে দেখে তার কোড অন্য কেউ নিয়ে নিয়েছে, এমন হওয়া উচিত নয়।
     *
     * @param  class-string<Model>  $model
     */
    public function isFree(string $model, string $code): bool
    {
        $query = $model::query()->where('code', $code);

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        return ! $query->exists();
    }

    /**
     * একই কোড থাকলে পাশে একটা সংখ্যা — `KG`, `KG2`, `KG3`।
     *
     * সংখ্যাটা পাশে বসে, নিচে নয়: `KG2` এখনো দুই-অক্ষরের কোডের মতো
     * দেখায় আর চালানে বেমানান লাগে না। `KG-0002` হলে ঠিক যে জিনিসটা
     * মালিক বারণ করেছেন সেটাই ফিরে আসত।
     *
     * @param  class-string<Model>  $model
     */
    private function makeUnique(string $model, string $base, int $max): string
    {
        $base = Str::substr($base, 0, $max);

        if ($base !== '' && $this->isFree($model, $base)) {
            return $base;
        }

        for ($n = 2; $n <= self::TRIES; $n++) {
            $suffix = (string) $n;
            $candidate = Str::substr($base, 0, max(1, $max - Str::length($suffix))).$suffix;

            if ($this->isFree($model, $candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * `code` কলামের প্রস্থ, একবার দেখে মনে রাখা।
     *
     * পড়া না গেলে ১৬ — ABOS-এর সবচেয়ে সরু কোড কলাম ৮, সবচেয়ে চওড়া
     * ৪০। ১৬ মাঝামাঝি ও নিরাপদ দিকে: বেশি কাটা পড়ার চেয়ে সংরক্ষণ
     * ভাঙা অনেক খারাপ।
     *
     * @param  class-string<Model>  $model
     */
    private function widthFor(string $model): int
    {
        return $this->widths[$model] ??= (function () use ($model): int {
            try {
                $table = (new $model)->getTable();

                foreach (\Illuminate\Support\Facades\Schema::getColumns($table) as $column) {
                    if ($column['name'] !== 'code') {
                        continue;
                    }

                    return preg_match('/\((\d+)\)/', (string) $column['type'], $m)
                        ? max(1, (int) $m[1])
                        : 16;
                }
            } catch (\Throwable) {
                // টেবিলটা নেই বা ড্রাইভারটা বলতে পারে না — নিচের ডিফল্ট।
            }

            return 16;
        })();
    }

    /** @param  class-string<Model>  $model */
    private function firstFree(string $model, int $start, int $step, int $width, int $max): string
    {
        for ($n = $start, $i = 0; $i < self::TRIES; $n += $step, $i++) {
            $candidate = Str::padLeft((string) $n, $width, '0');

            if (Str::length($candidate) <= $max && $this->isFree($model, $candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
