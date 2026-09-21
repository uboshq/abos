<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * ছাড় কেবল কমে — আর কতগুলো আছে, সেটা একটা সংখ্যা হয়ে থাকে।
 *
 * ── ⭐ কেন এটা দরকার, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * স্থাপত্যের প্রতিটা পাহারার নিজের একটা ছাড়ের তালিকা আছে, আর প্রতিটা
 * সারি একটা করে **নিয়ম যা আর খাটে না**। ⓘ ওগুলো কেবল বাড়ে, কারণ যোগ
 * করা এক লাইনের কাজ আর সরানো আসল কাজ।
 *
 * ⚠️ একটা তালিকা ছয় কমিটে ১৪ থেকে ২৪ নামে বেড়েছে, আর সেটা কারো চোখে
 * পড়েনি — কারণ **প্রতিটা যোগ আলাদা করে যুক্তিসঙ্গত ছিল**। ⛔ মোট
 * সংখ্যাটা কেউ কোনোদিন দেখেনি, তাই বেড়ে যাওয়াটা কোথাও লাল হয়নি।
 *
 * ── ⓘ কেন গোনাটা হাতে শ্রেণিবদ্ধ, আর সেটাই ঠিক ──────────────────────
 * সব keyed ধ্রুবক ছাড় নয়। ⭐ কিছু **চাহিদা** — মালিক যে ঘরগুলো চেয়েছেন
 * ([[MoneyMovementHasEveryFieldTheOwnerAskedForTest]]), বা যে মডেলগুলো
 * ছাঁকতেই হবে। ⛔ সব একসাথে গুনলে কেউ একটা **চাহিদা যোগ করলে** পাহারাটা
 * লাল হত — অর্থাৎ ভালো কাজ শাস্তি পেত, আর সংখ্যাটা অর্থহীন হত।
 *
 * ⚠️ তাই প্রতিটা তালিকা নিচে নাম ধরে শ্রেণিবদ্ধ, আর **অশ্রেণিবদ্ধ নতুন
 * তালিকা মানেই লাল** — সিদ্ধান্তটা এড়ানো যায় না।
 */
final class EveryExcuseWeGrantedIsCountedTest extends TestCase
{
    /**
     * ছাড় — যে সারিগুলো একটা নিয়মকে পাশ কাটাতে দেয়।
     *
     * @var list<string>
     */
    private const EXCUSES = [
        'ACodeMadeFromANameCanComeOutEmptyTest::HANDLED',
        'ASkippedTestReadsAsAPassingOneTest::STEPPING_ASIDE_FOR_NOW',
        'AnAuditedModelMustSayWhoseBooksItBelongsToTest::EXEMPT',
        'EveryAlpineHandlerIsActuallyWiredTest::NOT_WIRED_ON_PURPOSE',
        'EveryChangeableRowRemembersWhoChangedItTest::EXEMPT',
        'EveryListScreenPaginatesTest::NOT_REALLY_A_LIST',
        'EveryListScreenPaginatesTest::STILL_BEING_DONE',
        'EveryMasterNamesItsDuplicateGuardTest::EXEMPT',
        'EveryPolicyRuleIsActuallyReachedTest::REACHED_WITHOUT_A_ROUTE',
        'EveryPortalScreenAsksTheNarrowPathTest::HANDLED',
        'EveryRawQueryNamesItsCompanyTest::DECLARED',
        'EveryRightWeHandOutStopsSomethingTest::NOT_YET_CHECKED',
        'EveryRouteIsGuardedTest::ANY_SIGNED_IN_USER',
        'EveryRouteIsGuardedTest::CUSTOMER_PORTAL',
        'EveryRouteIsGuardedTest::GUARDED_INSIDE_THE_METHOD',
        'EveryRouteIsGuardedTest::OPEN_TO_THE_WORLD',
        'EveryRouteIsGuardedTest::TOKEN_SYNC',
        'EveryUserListAsksWhichCompanyTest::EXEMPT',
        'MoneyIsNeverAFloatTest::FLOAT_IS_DELIBERATE',
        'MoneyNeverLandsOnAGroupAccountTest::GROUPS_BELONG_HERE',
        'NoDatabaseDumpRidesAlongInACommitTest::FINE',
        'NoIndexNameStandsAtTheEdgeTest::AT_THE_EDGE',
        'NoSensitiveFieldIsPrintedInTheOpenTest::OPEN_ON_PURPOSE',
        'TheBranchWallStandsOnEveryDocumentTest::NO_WALL_ON_PURPOSE',
        'ThePortalDoesNotCallThemDealersAgainTest::ALLOWED',
    ];

    /**
     * চাহিদা ও তথ্য — এগুলো ছাড় নয়, তাই গোনায় নেই।
     *
     * ⓘ `NO_COMPANY_COLUMN` একটা **তথ্য**: ঐ টেবিলগুলোয় কোম্পানির ঘর
     * সত্যিই নেই, আর সেটা কমানো যায় না। ⚠️ `AS_SHIPPED` একটা ভিত্তিরেখা,
     * আর বাকি দুইটা মালিকের চাওয়া — ওগুলো **বাড়াই উচিত**।
     *
     * @var list<string>
     */
    private const NOT_EXCUSES = [
        'EveryRawQueryNamesItsCompanyTest::NO_COMPANY_COLUMN',
        'EveryUserListAsksWhichCompanyTest::MUST_ASK',
        'MoneyMovementHasEveryFieldTheOwnerAskedForTest::FIELDS',
        'TheOtherNineLooksWereNotTouchedTest::AS_SHIPPED',
    ];

    /**
     * আজকের বাস্তবতা — ২২ সেপ্টেম্বর ২০২৬-এ মেপে বসানো।
     *
     * ⭐ মালিকের ratchet নিয়ম: **কেবল কমবে**। বাড়াতে হলে এই লাইনটা
     * বদলাতে হয়, আর সেটা একটা সিদ্ধান্ত যা কমিটে চোখে পড়ে।
     */
    private const CEILING = 242;

    public function test_the_excuses_only_go_down(): void
    {
        $lists = $this->keyedLists();
        $total = 0;

        foreach (self::EXCUSES as $name) {
            $total += $lists[$name] ?? 0;
        }

        $this->assertLessThanOrEqual(self::CEILING, $total, implode("\n", [
            "\n⛔ ছাড়ের মোট সংখ্যা {$total}, ছাদ ".self::CEILING.'।',
            '',
            'ⓘ প্রতিটা ছাড় একটা করে নিয়ম যা আর খাটে না। ⚠️ আলাদা করে',
            'প্রতিটা যোগ যুক্তিসঙ্গত মনে হয় — বেড়ে যাওয়াটা কেবল মোটেই',
            'দেখা যায়, আর সেজন্যই এই সংখ্যাটা আছে।',
            '',
            '⭐ ছাড় না বাড়িয়ে জিনিসটা সারানোই আসল উত্তর। সত্যিই বাড়াতে',
            '   হলে CEILING বদলান — কারণসহ, কমিটে।',
        ]));
    }

    /**
     * ⭐ অশ্রেণিবদ্ধ একটা তালিকা মানেই সিদ্ধান্ত নেওয়া হয়নি।
     *
     * ⛔ এটা না থাকলে পাহারাটা নিজেই ফাঁকি দেওয়ার পথ হত: নতুন ছাড়গুলো
     * একটা **নতুন নামের** তালিকায় বসালে গোনাটা বাড়ত না, আর ছাদটা
     * চিরকাল সবুজ থাকত। ⓘ অর্থাৎ পাহারা থাকত, ছাড় বাড়ত।
     */
    public function test_no_list_escapes_by_being_new(): void
    {
        $known = array_merge(self::EXCUSES, self::NOT_EXCUSES);

        foreach (array_keys($this->keyedLists()) as $name) {
            $this->assertContains($name, $known, implode("\n", [
                "\n⛔ নতুন একটা তালিকা পাওয়া গেছে: {$name}",
                '',
                'ⓘ এটা কি একটা **ছাড়** (নিয়ম পাশ কাটানোর সারি), নাকি একটা',
                '   **চাহিদা** (যা থাকতেই হবে)?',
                '',
                'ছাড় হলে EXCUSES-এ বসান আর CEILING-টা মিলিয়ে নিন।',
                'চাহিদা হলে NOT_EXCUSES-এ বসান — গোনায় আসবে না।',
                '',
                '⚠️ সিদ্ধান্তটা এড়ানো যায় না, আর সেটাই এই দাবির কাজ।',
            ]));
        }
    }

    /**
     * ⭐ আর ঘোষিত তালিকাগুলো সত্যিই আছে — গোয়েন্দা অন্ধ নয়।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরের দুইটা অর্থহীন ──────────────────
     * ⛔ `keyedLists()` খালি ফিরলে যোগফল হয় শূন্য (ছাদের নিচে, সবুজ) আর
     * লুপটা শূন্যবার চলে (সবুজ)। ⓘ অর্থাৎ **প্রতিফলন ভেঙে গেলে পাহারাটা
     * সবচেয়ে জোরে সবুজ বলে** — ঠিক সেই ছাঁচ যেটা আজ রাতে আটটা পাহারায়
     * পাওয়া গেছে।
     *
     * ⚠️ তালিকাটা ধরে ধরে মেলানো হয়, গোনা মিলিয়ে নয়: গোনা মিললেও
     * **অন্য** কোনো তালিকা উধাও হতে পারত।
     */
    #[DataProvider('declaredLists')]
    public function test_a_declared_list_is_really_found(string $name): void
    {
        $this->assertArrayHasKey($name, $this->keyedLists(), implode("\n", [
            "\n⛔ ঘোষিত তালিকাটা আর খুঁজে পাওয়া যাচ্ছে না: {$name}",
            '',
            'ⓘ তালিকাটা সত্যিই মুছে ফেলা হয়েছে? তবে EXCUSES বা NOT_EXCUSES',
            '   থেকেও নামটা সরান — আর ছাড় হলে CEILING কমান।',
            '',
            '⛔ নয়তো প্রতিফলনটাই ভেঙেছে, আর তখন এই গোটা পাহারা শূন্য গুনে',
            '   "সব ঠিক আছে" বলবে।',
        ]));
    }

    /** @return list<array{0: string}> */
    public static function declaredLists(): array
    {
        return array_map(
            static fn (string $name) => [$name],
            array_merge(self::EXCUSES, self::NOT_EXCUSES),
        );
    }

    /**
     * স্থাপত্যের পাহারাগুলোর প্রতিটা চাবিওয়ালা ধ্রুবক, আর তার আকার।
     *
     * ── ⓘ খোঁজা নয়, গঠন ──────────────────────────────────────────────
     * ⚠️ `grep` দিয়ে ধ্রুবক খুঁজলে মন্তব্যে লেখা উদাহরণও ধরা পড়ত, আর
     * সারি গুনতে গেলে কমা গুনতে হত। ⭐ প্রতিফলনে সংখ্যাটা **আসল**।
     *
     * ⓘ কেবল **চাবিওয়ালা** তালিকা: ছাড় আর চাহিদা দুইটাতেই পাশে কারণ
     * লেখা থাকে, তাই চাবি থাকে। ⛔ শব্দভাণ্ডার (`ENTRY_POINTS`,
     * `LANGUAGES`) সরল তালিকা, আর ওগুলো গোনার জিনিস নয়।
     *
     * @return array<string, int>
     */
    private function keyedLists(): array
    {
        $found = [];

        foreach (glob(base_path('tests/Feature/Architecture/*.php')) ?: [] as $file) {
            $class = __NAMESPACE__.'\\'.basename($file, '.php');

            if (! class_exists($class) || $class === self::class) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $value = $constant->getValue();

                if (! is_array($value) || $value === []) {
                    continue;
                }

                foreach (array_keys($value) as $key) {
                    if (! is_string($key)) {
                        continue 2;
                    }
                }

                $found[basename($file, '.php').'::'.$constant->getName()] = count($value);
            }
        }

        return $found;
    }
}
