<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * পেছনে পাঠানো কাজ চালানোর মতো কেউ আছে তো?
 *
 * ── কেন এই পরীক্ষাটা লাগল ────────────────────────────────────────────
 * ৩ সেপ্টেম্বর ২০২৬-এ মাপা হলো, আর ছবিটা এইরকম:
 *
 *     QUEUE_CONNECTION      database        ← সংযোগ ঘোষিত
 *     jobs টেবিল             মাইগ্রেশন আছে   ← ঘর তৈরি
 *     লাইভে worker           নেই            ← চালানোর কেউ নেই
 *     jobs / failed_jobs     ০ / ০          ← তাই কোনোদিন কিছু যায়নি
 *     ShouldQueue            ০টা            ← আর কেউ পাঠায়ওনি
 *
 * অর্থাৎ **ব্যবস্থাটা আজ সৎ**: কিছু কিউ হয় না, তাই কিছু হারায়ও না।
 * [[EventRegistry]]-র মন্তব্যে সিদ্ধান্তটা লেখাই আছে, আর কারণটা ভালো —
 * *"worker, supervisor — অফিসের একটা মেশিনে যেটা একদিন চুপচাপ বন্ধ হয়ে
 * থাকত, আর কেউ টের পেত না।"*
 *
 * ── তাহলে বিপদটা কোথায় ──────────────────────────────────────────────
 * **একটা লাইন লিখলেই ভারসাম্যটা ভাঙে।** কেউ যেদিন একটা শ্রোতা বা কাজে
 * `implements ShouldQueue` বসাবেন, সেদিন থেকে ওই কাজটা `jobs` টেবিলে
 * গিয়ে বসে থাকবে — **আর কোনো ত্রুটি দেখা যাবে না**। পর্দা বলবে "হয়ে
 * গেছে", সারিটা জমতে থাকবে, আর ইমেইল/SMS/রিপোর্ট কোনোদিন যাবে না।
 *
 * ⚠️ **আর এটা ক্রেতার নিজের সার্ভারে আরও খারাপ।** মালিকের সিদ্ধান্ত
 * (৩ সেপ্টেম্বর): ABOS দুইভাবেই বিক্রি হবে — আমাদের সার্ভারে, আর
 * ক্রেতার নিজের সার্ভারে। ক্রেতার ঘরে **টের পাওয়ার কেউ নেই**।
 *
 * ── আন্তর্জাতিক ধারা কী বলে ─────────────────────────────────────────
 * চারটা বড় ERP-ই পেছনের কাজকে **পণ্যের ভেতরেই দৃশ্যমান** রাখে — SAP-এর
 * job overview, Oracle-এর concurrent manager, D365-এর batch job status,
 * Odoo-র scheduled actions। **কেউই একটা অদৃশ্য OS-প্রক্রিয়ার উপর ভরসা
 * করে না**, কারণ যে জিনিস দেখা যায় না তার বন্ধ হয়ে থাকাও দেখা যায় না।
 *
 * তাই নিয়মটা সহজ: **কিউ ব্যবহার করতে চাইলে চালানোর ব্যবস্থাও একই
 * পরিবর্তনে আসতে হবে** — পরে নয়।
 */
class WorkSentToTheBackgroundHasSomethingToRunItTest extends TestCase
{
    /**
     * ডিপ্লয়ে worker-এর ব্যবস্থা আছে কি না, এই চিহ্নগুলো দেখে বোঝা যায়।
     *
     * নামগুলো ইচ্ছে করে চওড়া — launchd, systemd, supervisor, Horizon,
     * যেটাই হোক। **পরীক্ষাটা পদ্ধতি ঠিক করে দিচ্ছে না, কেবল দাবি করছে
     * চালানোর কেউ একজন আছে।**
     */
    private const PROVISION_MARKS = [
        'queue:work',
        'queue:listen',
        'horizon',
        'queue-worker',
    ];

    public function test_nothing_is_queued_unless_the_deploy_runs_a_worker(): void
    {
        $queued = [];

        foreach (File::allFiles(base_path('app')) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $source = File::get($file->getPathname());

            /*
             * মন্তব্য ছেঁটে নেওয়া হয় — [[EventRegistry]] ঠিক এই শব্দটা
             * তার ব্যাখ্যায় লিখেছে ("যেদিন সত্যিই লাগবে, শ্রোতাটা
             * ShouldQueue বাস্তবায়ন করলেই হবে")। ব্যাখ্যা অপরাধ নয়।
             */
            $source = (string) preg_replace('#/\*.*?\*/#su', '', $source);
            $source = (string) preg_replace('#//[^\n]*#', '', $source);

            if (preg_match('/\bimplements\b[^{]*\bShouldQueue\b/', $source) === 1) {
                $queued[] = $file->getFilename();
            }
        }

        if ($queued === []) {
            /*
             * আজকের অবস্থা — আর এটাই সৎ: কিছু কিউ হয় না, তাই কিছু
             * হারায় না। পরীক্ষাটা এখানে থেমে যায়, কিন্তু **চুপচাপ
             * নয়**: নিচের assertion বলে দেয় সে সত্যিই দেখেছে।
             */
            $this->assertSame([], $queued);

            return;
        }

        $deploy = File::exists(base_path('infra/deploy.sh'))
            ? File::get(base_path('infra/deploy.sh'))
            : '';

        $infra = '';

        foreach (File::allFiles(base_path('infra')) as $file) {
            $infra .= File::get($file->getPathname());
        }

        $haystack = $deploy.$infra;

        $found = false;

        foreach (self::PROVISION_MARKS as $mark) {
            if (str_contains($haystack, $mark)) {
                $found = true;

                break;
            }
        }

        $this->assertTrue($found, implode("\n", array_merge(
            ['এই ফাইলগুলো কাজ পেছনে পাঠায় (`implements ShouldQueue`):'],
            array_map(fn (string $f): string => "  · {$f}", $queued),
            [
                '',
                'কিন্তু `infra/`-তে কোথাও worker চালানোর ব্যবস্থা নেই।',
                'ফলে কাজগুলো `jobs` টেবিলে গিয়ে বসে থাকবে, আর **কোনো',
                'ত্রুটি দেখা যাবে না** — পর্দা বলবে "হয়ে গেছে"।',
                '',
                'যেকোনো একটা যথেষ্ট: '.implode(' · ', self::PROVISION_MARKS),
                '',
                '⚠️ আর ক্রেতার নিজের সার্ভারে টের পাওয়ার কেউ নেই — তাই',
                'চালানোর ব্যবস্থাটা একই পরিবর্তনে আসতে হবে, পরে নয়।',
            ],
        )));
    }
}
