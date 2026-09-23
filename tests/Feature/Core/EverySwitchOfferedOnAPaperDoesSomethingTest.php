<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PrintProfile;
use Tests\TestCase;

/**
 * যে সুইচ কিছুই করে না, সে সুইচ না থাকার চেয়ে খারাপ।
 *
 * ── ⛔ যে ফাঁকটা [[EveryPrintPartIsRealTest]] ধরে না ──────────────────
 * ⓘ সে জিজ্ঞেস করে: `PARTS`-এর প্রতিটা নাম **কোনো না কোনো** কাগজ চায়
 * কি না। ⚠️ কিন্তু নিয়ন্ত্রণের পর্দা সুইচগুলো আঁকে **কাগজ ধরে ধরে** —
 * আর একটা নাম এক কাগজে সত্যি হয়েও অন্য কাগজে মরা হতে পারে।
 *
 * ⭐ ২৩ সেপ্টেম্বর ২০২৬-এ ভাউচার যোগ করার সময় ঠিক সেটাই দাঁড়াত:
 * বিলের পাঁচটা কাগজ `print.document-body` বাড়ায়, আর সে সাতটা দেহ-অংশের
 * সবকটাই আঁকে। ⛔ ভাউচার নিজের ছাঁচে লেখা, আর তাতে ব্যান্ড বা আদায়ের
 * ছক বলে কিছু নেই — ঐ দুইটা সুইচ আঁকা হত, মালিক বদলে দেখতেন কিছুই হয় না।
 *
 * ── ⚠️ দামটা সুইচ দুইটার চেয়ে বড় ────────────────────────────────────
 * ⓘ একটা মরা সুইচ কেবল নিজে কাজ করে না — সে **গোটা ব্যবস্থাটার উপর
 * বিশ্বাস নষ্ট করে**। মালিক দুইবার চেষ্টা করে কিছু না পেলে ধরে নেন
 * ছাপার নিয়ন্ত্রণটাই কাজ করে না, আর বাকি তেরোটা সত্যিকারের সুইচও আর
 * ছোঁন না।
 *
 * ── ⓘ দাবিটা দুই দিকেই ───────────────────────────────────────────────
 * **কম** হলে: কাগজ একটা অংশ চায় অথচ সুইচ নেই — অংশটা চিরকাল চালু,
 * বন্ধ করার পথ নেই।
 * **বেশি** হলে: সুইচ আছে, কাগজ চায় না — মরা সুইচ।
 * ⭐ দুইটাই ভুল, তাই মিল **হুবহু** হতে হয়।
 */
final class EverySwitchOfferedOnAPaperDoesSomethingTest extends TestCase
{
    /**
     * কোন টার্গেট কোন কাগজে ছাপে।
     *
     * ── ⚠️ কেন এটা এখানে হাতে লেখা, আর সেটা কেন নিরাপদ ───────────────
     * ⓘ জোড়াটা থাকে কন্ট্রোলারে, `render(template: …, profile: …)`-এ —
     * আর বিক্রয়ের কন্ট্রোলার প্রোফাইলটা **চলতি সময়ে** বেছে নেয়
     * (`$this->profileFor(...)->target`), তাই সোর্স পড়ে জোড়াটা বের করা
     * যায় না।
     *
     * ⛔ হাতে লেখা তালিকা সাধারণত ফাঁদ — বাসি হয়ে যায় আর কেউ জানে না।
     * ⭐ এখানে সেটা আটকানো: নিচের প্রথম দাবিটা বলে **প্রতিটা** টার্গেটের
     * নাম এখানে থাকতেই হবে। ⓘ কেউ নতুন একটা কাগজ সুইচের আওতায় আনলে
     * সেদিনই এই পরীক্ষাটা লাল হবে, আর তাঁকে সিদ্ধান্তটা নিতে হবে।
     *
     * @var array<string, string>
     */
    private const PAPER_OF = [
        /*
         * ⓘ বিলের পাঁচটাই এক কাগজ বাড়ায় — সেটাই ইচ্ছাকৃত: একটা অংশ
         * যোগ করে চারটায় ভুলে যাওয়ার সুযোগ থাকে না।
         */
        'invoice' => 'document-body',
        'pos' => 'document-body',
        'challan' => 'document-body',
        'order' => 'document-body',
        'receipt' => 'document-body',

        /* ⚠️ ভাউচার আলাদা — ডেবিট-ক্রেডিটের ছাঁচ পণ্যের ছাঁচ নয়। */
        'voucher' => 'voucher',
    ];

    /** মাথা ও পাদ — সব কাগজই এটা বাড়ায়, তাই এর অংশগুলো সবার। */
    private const LAYOUT = 'layout';

    /**
     * ⛔ প্রতিটা টার্গেটের কাগজ এখানে নাম ধরে লেখা আছে।
     *
     * ⓘ এটাই উপরের তালিকাটাকে বাসি হতে দেয় না।
     */
    public function test_every_target_names_its_paper(): void
    {
        $missing = array_values(array_diff(PrintProfile::TARGETS, array_keys(self::PAPER_OF)));

        $this->assertSame([], $missing, implode("\n", [
            '⛔ এই কাগজগুলো সুইচের আওতায় এসেছে, অথচ কোন টেমপ্লেটে ছাপে তা এখানে লেখা নেই:',
            '',
            ...array_map(fn (string $t) => "  · {$t}", $missing),
            '',
            'ⓘ `PAPER_OF`-এ নামটা বসান। ⚠️ তারপর নিচের দাবিটা বলবে ঐ কাগজে',
            'কোন সুইচগুলো সত্যিই কাজ করে — আর কোনগুলো `PARTS_NOT_ON`-এ যাওয়া উচিত।',
        ]));

        $stale = array_values(array_diff(array_keys(self::PAPER_OF), PrintProfile::TARGETS));

        $this->assertSame([], $stale,
            '⛔ এই নামগুলো আর টার্গেট নয়, তালিকা থেকে সরান: '.implode(', ', $stale));
    }

    /**
     * ⭐ প্রতিটা কাগজে যে সুইচগুলো দেওয়া হয়, সেগুলোই কাগজটা আঁকে — কম নয়, বেশি নয়।
     */
    public function test_the_switches_a_paper_offers_are_exactly_the_ones_it_draws(): void
    {
        $head = $this->partsDrawnBy(self::LAYOUT);

        $this->assertNotSame([], $head, implode("\n", [
            '⛔ মাথার কাগজটা একটাও অংশ চায় না।',
            '',
            'ⓘ তাহলে খোঁজার নিয়মটাই ভেঙেছে, আর নিচের তুলনাগুলো অর্থহীন।',
        ]));

        foreach (PrintProfile::TARGETS as $target) {
            $paper = self::PAPER_OF[$target] ?? null;

            if ($paper === null) {
                continue;   // উপরের দাবিটা এটা আলাদা করে বলে
            }

            $drawn = array_unique([...$head, ...$this->partsDrawnBy($paper)]);
            $offered = PrintProfile::partsFor($target);

            sort($drawn);
            $sortedOffered = $offered;
            sort($sortedOffered);

            $this->assertSame($sortedOffered, $drawn, implode("\n", [
                "⛔ `{$target}` কাগজে সুইচ আর আঁকা অংশ মেলে না।",
                '',
                'সুইচ আছে অথচ আঁকা হয় না (মরা সুইচ): '
                    .(implode(', ', array_diff($sortedOffered, $drawn)) ?: '—'),
                'আঁকা হয় অথচ সুইচ নেই (বন্ধ করার পথ নেই): '
                    .(implode(', ', array_diff($drawn, $sortedOffered)) ?: '—'),
                '',
                'ⓘ মরা সুইচ হলে `PrintProfile::PARTS_NOT_ON`-এ কারণসহ লিখুন।',
                'ⓘ সুইচ না থাকলে `PARTS`-এ নামটা আছে কি না দেখুন।',
                '',
                '⚠️ একটা মরা সুইচ কেবল নিজে কাজ করে না — মালিক দুইবার চেষ্টা',
                'করে কিছু না পেলে বাকি সবগুলোও আর ছোঁন না।',
            ]));
        }
    }

    /**
     * ⛔ আর পাহারাটা সত্যিই তাকায়।
     *
     * ── ⚠️ এই দাবিটা ছাড়া উপরেরটা মিথ্যা সবুজ হতে পারত ──────────────
     * ⓘ [[partsDrawnBy()]] ভুল ফোল্ডারে তাকালে বা রেগেক্সটা না মিললে সে
     * **সবার জন্য খালি তালিকা** ফেরত দিত। ⛔ তখন উপরের তুলনাটা হত
     * "খালি বনাম খালি" — সব টার্গেটে সবুজ, কিছু না দেখেই।
     *
     * ⭐ তাই একটা নাম-না-থাকা কাগজ চেয়ে দেখা: খালি না এলে খোঁজাটাই ভাঙা।
     */
    public function test_the_guard_notices_when_it_is_looking_at_nothing(): void
    {
        $this->assertSame([], $this->partsDrawnBy('a_paper_that_does_not_exist'),
            '⛔ নেই এমন একটা কাগজও অংশ ফেরত দিল — খোঁজাটা যা খুশি মেলাচ্ছে।');

        $this->assertContains('totals', $this->partsDrawnBy('document-body'), implode("\n", [
            '⛔ বিলের কাগজটাও `totals` চায় না বলছে।',
            '',
            'ⓘ অর্থাৎ খোঁজাটা কিছুই দেখছে না, আর উপরের সব তুলনা অর্থহীন।',
        ]));
    }

    /**
     * এই কাগজটা `$profile->shows('…')` দিয়ে যে অংশগুলো চায়।
     *
     * @return list<string>
     */
    private function partsDrawnBy(string $paper): array
    {
        $path = resource_path('views/print/'.$paper.'.blade.php');

        if (! is_file($path)) {
            return [];
        }

        preg_match_all(
            "/shows\(\s*'([a-z_]+)'\s*\)/",
            (string) file_get_contents($path),
            $found,
        );

        return array_values(array_unique($found[1]));
    }
}
