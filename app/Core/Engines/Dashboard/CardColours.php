<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

/**
 * ⭐ মডিউল ড্যাশবোর্ডের রং — মালিকের রঙিন নমুনা "সব মডিউলের রঙিন ড্যাশবোর্ড" থেকে (মালিক, ৩১ অক্টোবর ২০২৬: "desh board e multi
 * color ba 10 12 ti color", "Sample file dekhe koro")। কোন মডিউলের কোন রং তা `config/abos.php`-এর `dashboard_colours`-এ — এখানে কেবল
 * নিয়ম, কোনো মডিউলের নাম নয়।
 *
 *   head  — মাথার পট্টির দুই রং (নমুনার c1 → c2); নমুনায় না থাকলে মডিউলের নিজের রং।
 *   cards — উপরের চার কার্ড:
 *     · "এখনই দেখুন" ([[Stat::BAD]]) লাল, "নজর দিন" ([[Stat::WARN]]) হলুদ — নমুনার মানে, সব মডিউলে এক; একই মানের দ্বিতীয় কার্ড একই
 *       পরিবারের পরের ছায়ায় (হলুদ → অ্যাম্বার → কমলা, লাল → গোলাপি), দুই এক রং পাশাপাশি নয়;
 *     · বাকিগুলো অবস্থান ধরে নমুনার রং — যদি সেটা আগে না বসে থাকে আর লাল/হলুদ না হয় (মানে ছাড়া লাল নয়); নইলে তালিকার পরেরটা।
 *
 * ⓘ ফল CSS-এর `var(--color-pal-*)` — `tokens.css`-এ নমুনার বারো রং ৭০০ ধাপে (সাদা লেখা ≥ ৪.৫ঃ১); রঙিন সুইচ বন্ধে ব্র্যান্ড ৭০০।
 */
final class CardColours
{
    /** নমুনার পরে যা বাকি থাকে, এই ক্রমে */
    private const REST = ['green', 'purple', 'teal', 'pink', 'sky', 'indigo', 'blue', 'lime', 'orange', 'amber'];

    /** মানে বহন করা রং — অন্য কার্ডে বসে না */
    private const MEANING = [Stat::BAD => 'red', Stat::WARN => 'yellow'];

    /** একই মানের পরের কার্ডের ছায়া */
    private const FAMILY = ['yellow' => ['amber', 'orange'], 'red' => ['pink', 'orange']];

    /** @return array{0: string, 1: string} মাথার ঢালের দুই রং (CSS) */
    public static function head(string $module): array
    {
        $head = (array) (self::paint($module)['head'] ?? []);

        if ($head === []) {
            $own = 'var(--color-module-'.$module.', var(--color-brand-700))';

            return [$own, $own];
        }

        return [self::css($head[0]), self::css($head[1] ?? $head[0])];
    }

    /**
     * @param  list<string>  $tones  উপরের কার্ডগুলোর [[Stat::$tone]], ক্রমে
     * @return list<string> প্রতিটা কার্ডের রঙের নাম (`blue`, `red` …)
     */
    public static function names(string $module, array $tones): array
    {
        $sample = array_values((array) (self::paint($module)['cards'] ?? []));
        $order = [...$sample, ...self::REST];
        $used = [];

        foreach ($tones as $i => $tone) {
            $name = self::MEANING[$tone] ?? null;

            if ($name !== null && in_array($name, $used, true)) {
                $name = collect(self::FAMILY[$name])->first(fn (string $c) => ! in_array($c, $used, true)) ?? $name;
            }

            $want = $sample[$i] ?? null;
            if ($name === null && $want !== null && ! in_array($want, $used, true) && ! in_array($want, self::MEANING, true)) {
                $name = $want;
            }

            $name ??= collect($order)->first(fn (string $c) => ! in_array($c, $used, true) && ! in_array($c, self::MEANING, true)) ?? 'blue';
            $used[] = $name;
        }

        return $used;
    }

    /**
     * @param  list<string>  $tones
     * @return list<string> CSS রং — নমুনায় না থাকা মডিউলের প্রথম মানহীন কার্ড মডিউলের নিজের রঙে
     */
    public static function for(string $module, array $tones): array
    {
        $own = self::paint($module) === [];

        return array_map(
            fn (string $name, int $i) => $own && $i === 0 && ! isset(self::MEANING[$tones[0] ?? ''])
                ? 'var(--color-module-'.$module.', var(--color-brand-700))'
                : self::css($name),
            self::names($module, $tones),
            array_keys($tones),
        );
    }

    /**
     * "নজরে রাখুন" তালিকার সারির রং — নমুনার মানে: লাল এখনই, হলুদ নজর, সবুজ ভালো, বাকি সব আকাশি (তথ্য)।
     * ⓘ কার্ডের মতো ছায়া বদলায় না — তালিকায় একই মানের অনেক সারি, রং মানে বলে, ক্রম নয়।
     */
    public static function watch(?string $tone): string
    {
        return self::css(self::MEANING[$tone ?? ''] ?? ($tone === Stat::GOOD ? 'green' : 'sky'));
    }

    private static function css(string $name): string
    {
        return 'var(--color-pal-'.$name.')';
    }

    /** @return array<string, mixed> */
    private static function paint(string $module): array
    {
        $all = (array) config('abos.dashboard_colours', []);

        return (array) ($all[$module] ?? []);
    }
}
