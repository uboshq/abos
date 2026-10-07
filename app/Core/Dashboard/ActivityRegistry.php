<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use App\Core\Module\ModuleRegistry;
use App\Models\User;

/**
 * সদ্য যা হয়েছে — সব মডিউল মিলিয়ে, সময় ধরে।
 *
 * ── কেন এটা করণীয় তালিকার পাশে বসে ──────────────────────────────────
 * করণীয় বলে **কী আটকে আছে**; এটা বলে **কী হয়ে গেছে**। দিনের শুরুতে
 * মালিকের প্রথম প্রশ্নটা দ্বিতীয়টাই — "আমি না থাকতে কী কী হলো"।
 */
class ActivityRegistry
{
    /**
     * পর্দায় কয়টা সারি।
     *
     * চারটা, কারণ পাশের করণীয় তালিকাটাও চার-পাঁচ সারির — দুইটা কার্ড
     * সমান উঁচু না হলে পর্দাটা একদিকে হেলে থাকে। আর যে পঞ্চম ঘটনাটা
     * কেউ দেখল না, সেটা তালিকায় থাকা আর না থাকা সমান।
     */
    public const SHOWN = 4;

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * এই ব্যবহারকারী যা দেখতে পারেন — সাম্প্রতিকতমটা আগে।
     *
     * ── কেন অনুমতি এখানে ছাঁকা হয়, প্রতিটা মডিউলে নয় ────────────────
     * তালিকায় টাকার অঙ্ক থাকে। প্রতিটা সরবরাহকারীকে নিজে ছাঁকতে বললে
     * একদিন কেউ ভুলত, আর ডেলিভারিম্যানের হোম পর্দায় বিলের অঙ্ক ভেসে
     * উঠত। উইজেটে ঠিক একই কারণে একই ব্যবস্থা।
     *
     * @return list<Happening>
     */
    public function forUser(?User $user, int $limit = self::SHOWN): array
    {
        $rows = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->activity as $provider) {
                /*
                 * প্রতিটা মডিউলের কাছে দরকারের চেয়ে বেশি চাওয়া হয়।
                 *
                 * চারটা চাইলে আর তার তিনটাই অনুমতির বাইরে হলে পর্দায়
                 * একটা সারি বসত — অথচ ওই মডিউলে দেখানোর মতো আরও ছিল।
                 * বেশি চেয়ে পরে ছাঁটাই একমাত্র উপায় যাতে ছাঁকনির পরেও
                 * তালিকাটা ভরা থাকে।
                 */
                foreach ($provider::activity($limit * 3) as $happening) {
                    if ($user?->can($happening->permission) !== true) {
                        continue;
                    }

                    $rows[] = $happening;
                }
            }
        }

        usort($rows, fn (Happening $a, Happening $b) => $b->when <=> $a->when);

        return array_slice($rows, 0, $limit);
    }
}
