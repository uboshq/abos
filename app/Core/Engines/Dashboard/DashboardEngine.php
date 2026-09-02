<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Widget;
use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Models\User;
use RuntimeException;

/**
 * প্রতিটা মডিউলের নিজের ড্যাশবোর্ড — একটাই ইঞ্জিন।
 *
 * ── কেন ইঞ্জিন, বারোটা কন্ট্রোলার নয় ─────────────────────────────────
 * বারোটা মডিউলের বারোটা ড্যাশবোর্ড মানে বারোটা কন্ট্রোলার, বারোটা
 * ব্লেড, আর বারো রকম সাজ। ছয় মাস পরে তিনটায় সংখ্যা উপরে, চারটায়
 * নিচে, আর একটায় চার্টই নেই — কেউ ইচ্ছে করে সেটা করত না, তবু হত।
 *
 * এখানে **সাজানোর কাজটা এক জায়গায়**, আর মডিউল কেবল বলে *কোন
 * সংখ্যাগুলো*। নতুন মডিউল একটা ক্লাস লিখে ঘোষণা করলেই তার ড্যাশবোর্ড
 * বাকিগুলোর মতোই দেখায়।
 *
 * ── কেন অনুমতি এখানে ছাঁকা হয় ────────────────────────────────────────
 * মডিউল নিজে ছাঁকলে একদিন কেউ ভুলে যেত, আর ভুলটা **নীরব**: সংখ্যাটা
 * দেখা যেত, কেউ অভিযোগ করতেন না। এক জায়গায় ছাঁকলে ভোলার সুযোগ নেই।
 */
final class DashboardEngine
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * একটা মডিউলের ড্যাশবোর্ড, এই ব্যবহারকারীর জন্য।
     *
     * @throws RuntimeException মডিউলটা ড্যাশবোর্ড ঘোষণা না করলে
     */
    public function for(string $moduleCode, ?User $user): DashboardDefinition
    {
        $module = $this->modules->get($moduleCode);

        if ($module === null || $module->dashboard === null) {
            throw new RuntimeException("Module '{$moduleCode}' declares no dashboard.");
        }

        /** @var class-string<ProvidesDashboard> $provider */
        $provider = $module->dashboard;

        $definition = $provider::dashboard();

        return new DashboardDefinition(
            title: $definition->title,
            subtitle: $definition->subtitle,
            stats: $this->allowed($definition->stats, $user),
            panels: $definition->panels,
            listings: $definition->listings,
            tiles: $this->tilesFor($definition->tiles, $user),
            reminders: $this->remindersFor($module, $user),
        );
    }

    /**
     * গোটা ব্যবসার এক পাতা — প্রতিটা মডিউলের মাথার সংখ্যাটা।
     *
     * ── কেন প্রতিটা মডিউলের **প্রথম** সংখ্যাটাই ────────────────────────
     * কোর জানে না কোন মডিউলের কোন সংখ্যাটা সবচেয়ে জরুরি — সেটা
     * মডিউলের নিজের জ্ঞান। তাই নিয়মটা সহজ ও ঘোষিত: **যে সংখ্যাটা
     * মডিউল নিজের ড্যাশবোর্ডে সবার আগে রাখে, সেটাই তার মুখ।**
     *
     * এতে দুইটা জিনিস একসাথে মেলে: কোরে কোনো মডিউলের নাম লিখতে হয়
     * না (§১৯.৭), আর মডিউল তার মুখ বদলাতে চাইলে নিজের ফাইলেই ক্রম
     * বদলায় — কোরে কেউ আসে না।
     *
     * ── কেন এটা মডিউল-ড্যাশবোর্ডের নকল নয় ────────────────────────────
     * মডিউলের পর্দা **গভীর**: এক বিষয়ের ছয়টা সংখ্যা, চার্ট, তালিকা।
     * এই পাতাটা **চওড়া**: বারো বিষয়ের একটা করে সংখ্যা, আর কোনটায়
     * নামতে হবে সেই সিদ্ধান্ত। দুইটা আলাদা প্রশ্ন, তাই দুইটা পর্দা।
     *
     * @return list<array{module: string, name: string, stat: Stat}>
     */
    public function overall(?User $user): array
    {
        $out = [];

        foreach ($this->modules->all() as $module) {
            if ($module->dashboard === null) {
                continue;
            }

            /** @var class-string<ProvidesDashboard> $provider */
            $provider = $module->dashboard;

            $stats = $this->allowed($provider::dashboard()->stats, $user);

            if ($stats === []) {
                continue;
            }

            $out[] = [
                'module' => $module->code,
                'name' => $module->name[app()->getLocale()] ?? $module->name['en'],
                'stat' => $stats[0],
            ];
        }

        return $out;
    }

    /** মডিউলটা আদৌ ড্যাশবোর্ড ঘোষণা করেছে কি না। */
    public function has(string $moduleCode): bool
    {
        return $this->modules->get($moduleCode)?->dashboard !== null;
    }

    /**
     * এই ড্যাশবোর্ডের দরজার চাবি — মডিউল নিজেই যেটা ঘোষণা করেছে।
     *
     * ── কেন মেনুর সারি থেকেই নেওয়া হয় ───────────────────────────────
     * চাবির নামটা দ্বিতীয় জায়গায় (`DashboardDefinition`-এ) লিখলে
     * দুইটা সত্য তৈরি হত, আর একদিন মেনু লুকিয়ে থাকত অথচ দরজা খোলা
     * থাকত। **যে সারিটা লিংক দেখায় আর যে চাবিটা দরজা খোলে — একটাই
     * স্ট্রিং**, তাই দুইটা আলাদা হতে পারে না।
     *
     * ── আর সারিটা না থাকলে? ─────────────────────────────────────────
     * `null` ফেরে, আর কন্ট্রোলার তখন **ঢুকতে দেয় না**। ভুলে সারি
     * লিখতে ভুলে গেলে পাতাটা সবার জন্য বন্ধ হবে — খোলা নয়। একটা
     * বন্ধ দরজার অভিযোগ আসে; একটা খোলা দরজার আসে না (২ সেপ্টেম্বর ২০২৬)।
     */
    public function permissionFor(string $moduleCode): ?string
    {
        $module = $this->modules->get($moduleCode);

        if ($module === null) {
            return null;
        }

        foreach ($module->menu['dashboard'] ?? [] as $row) {
            if (($row['route'] ?? null) === 'module.dashboard'
                && ($row['route_params']['module'] ?? null) === $moduleCode
                && is_string($row['permission'] ?? null)
                && $row['permission'] !== '') {
                return $row['permission'];
            }
        }

        return null;
    }

    /**
     * যে কাজের চাবি নেই, সেই টাইলটা দেখানোই হয় না।
     *
     * ── কেন সংখ্যার উল্টো নিয়ম ──────────────────────────────────────
     * সংখ্যা ঢাকা হয়, বাদ যায় না — কারণ কাঠামোটা এক রাখা দরকার।
     * টাইল **একটা বোতাম**, আর যে বোতাম চাপলে ৪০৩ আসে সেটা রাখা
     * নিষ্ঠুর: মানুষ চাপেন, ব্যর্থ হন, আর ভাবেন ব্যবস্থাটা ভাঙা।
     *
     * @param  list<Tile>  $tiles
     * @return list<Tile>
     */
    private function tilesFor(array $tiles, ?User $user): array
    {
        return array_values(array_filter(
            $tiles,
            fn (Tile $tile): bool => (bool) $user?->can($tile->permission),
        ));
    }

    /**
     * এই মডিউলের করণীয় — তার নিজের উইজেট থেকেই।
     *
     * @return list<Widget>
     */
    private function remindersFor(ModuleDefinition $module, ?User $user): array
    {
        $out = [];

        foreach ($module->widgets as $provider) {
            foreach ($provider::widgets() as $widget) {
                /*
                 * ── শূন্য করণীয় করণীয় নয় ────────────────────────────
                 * "০ ফুরিয়ে আসছে" একটা সুখবর, কাজ নয়। তালিকায় রাখলে
                 * প্রতিদিন কয়েকটা শূন্য সারি বসে থাকত, আর যেদিন
                 * সত্যিই কিছু আটকাত সেদিন সেটা ওই শূন্যগুলোর ভিড়ে
                 * হারাত। তালিকাটা খালি থাকাই তখন সবচেয়ে পরিষ্কার
                 * বার্তা: কিছু আটকে নেই।
                 *
                 * ⚠️ `!== '0'` লিখে হত না। টাকার উইজেট মান পাঠায়
                 * **সাজানো অবস্থায়** — `0.00`, বা হাজারের কমা সহ।
                 * ৩ সেপ্টেম্বর ২০২৬-এ হিসাবের পর্দায় "করণীয়" ঘরে
                 * তিনটা `0.00` সারি বসে ছিল, ঠিক এই কারণে। তাই
                 * সংখ্যাটা **সংখ্যা হিসেবেই** মাপা হয়; আর যেটা
                 * সংখ্যাই নয় (তারিখ, নাম) সেটা শূন্য নয়, তাই থাকে।
                 */
                if ($user?->can($widget->permission) && ! self::readsAsZero($widget->value)) {
                    $out[] = $widget;
                }
            }
        }

        usort($out, fn ($a, $b) => $a->sort <=> $b->sort);

        return $out;
    }

    /**
     * সাজানো মানটা আসলে শূন্য কি না।
     *
     * `0` · `0.00` · `০.০০` · `০ / ০` — সবগুলোই শূন্য।
     * `1,200` · `0 / 5` — কোনোটাই নয়।
     *
     * ── কেন "ভেতরের সব সংখ্যা শূন্য", "পুরোটা শূন্য" নয় ──────────────
     * এইচআরের "আজ উপস্থিত" মান পাঠায় `০ / ৫` আকারে — এসেছে কতজন,
     * মোট কতজন। **`০ / ৫` একটা আসল করণীয়**: পাঁচজন আছেন, একজনেরও
     * হাজিরা বসেনি। কিন্তু `০ / ০` মানে কর্মীই নেই, আর তখন কিছু
     * করারও নেই।
     *
     * তাই নিয়মটা: **যতগুলো সংখ্যা আছে সবগুলোই শূন্য হলে** সারিটা
     * করণীয় নয়। একটাও শূন্য নয় এমন সংখ্যা থাকলে থাকে।
     *
     * সংখ্যা একটাও না থাকলে (যেমন "কখনো নয়") সারিটা থাকে — লেখা আর
     * শূন্য এক কথা নয় (৩ সেপ্টেম্বর ২০২৬)।
     */
    private static function readsAsZero(mixed $value): bool
    {
        $text = trim((string) $value);

        if ($text === '') {
            return true;
        }

        $text = strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        // হাজারের কমা সরানো, নাহলে "1,200" দুইটা সংখ্যা হয়ে যেত
        $text = preg_replace('/(?<=\d),(?=\d)/', '', $text) ?? $text;

        if (preg_match_all('/\d+(?:\.\d+)?/', $text, $numbers) === 0) {
            return false;
        }

        foreach ($numbers[0] as $number) {
            if ((float) $number !== 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * যে সংখ্যাগুলোর চাবি নেই, সেগুলো ঢাকা — বাদ নয়।
     *
     * ── কেন বাদ দেওয়া হয় না ─────────────────────────────────────────
     * বাদ দিলে পর্দাটা একেকজনের কাছে একেক আকারের হত, আর কেউ বুঝতেন
     * না কিছু লুকানো আছে কি না। ঢেকে রাখলে **কাঠামোটা এক থাকে**, আর
     * যিনি দেখছেন তিনি জানেন সংখ্যাটা আছে, তাঁর জন্য নয়।
     *
     * @param  list<Stat>  $stats
     * @return list<Stat>
     */
    private function allowed(array $stats, ?User $user): array
    {
        return array_map(
            fn (Stat $stat): Stat => $stat->permission === null || $user?->can($stat->permission)
                ? $stat
                : $stat->masked(),
            $stats,
        );
    }
}
