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

        /*
         * ⛔ এটা সত্যিই একটা ছাড়, আর গোনায় আসা উচিত — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ তিনটা কোয়েরি কোম্পানির ছাঁকনি ছাড়াই চলে, আর তিনটারই কারণ
         * লেখা (ইমেইলের অনন্যতা গোটা ব্যবস্থার, এককালীন টোকেন, আর
         * বাদ-দেওয়ার তালিকা)। ⚠️ কারণ থাকা মানেই ছাড়টা ছাড় নয় — এমন নয়।
         *
         * ⭐ উপরের `EXEMPT` ফাইল ধরে ছাড় দিত, তাই একটা ফাইল = এক সারি।
         * ⓘ এটা কোয়েরি ধরে, তাই তিন — আর সেটাই সৎ: তিনটা আলাদা
         * সিদ্ধান্ত, তিনটা আলাদা সারি, তিনবার গোনা।
         */
        'EveryUserListAsksWhichCompanyTest::EXEMPT_QUERY',
        'MoneyIsNeverAFloatTest::FLOAT_IS_DELIBERATE',
        'MoneyNeverLandsOnAGroupAccountTest::GROUPS_BELONG_HERE',
        'OnlyTheEngineWritesToTheLedgerTest::WRITES_ONLY_THE_SEAL',
        'NoDatabaseDumpRidesAlongInACommitTest::FINE',
        'NoIndexNameStandsAtTheEdgeTest::AT_THE_EDGE',
        'NoSensitiveFieldIsPrintedInTheOpenTest::OPEN_ON_PURPOSE',
        'TheBranchWallStandsOnEveryDocumentTest::NO_WALL_ON_PURPOSE',
        'ThePortalDoesNotCallThemDealersAgainTest::ALLOWED',
    ];

    /**
     * চাহিদা ও তথ্য — এগুলো ছাড় নয়, তাই গোনায় নেই।
     *
     * ⓘ `NOT_REALLY_A_LIST`-এর ২৬টা সারি **গঠনগত**, অলসতা নয় — একটাই
     * POST-ওয়ালা ফর্ম, গাছ, দেয়ালে ঝোলানো বোর্ড, আর স্থির সংখ্যার
     * তালিকা (বারোটা মাস, দশটা নীতি)। ⚠️ পাতা ভাগ করলে ভরা ঘর হারাত।
     * ⛔ ২৭তম সারিটা (`finance.hand_loan.index`) সরিয়ে
     * `STILL_BEING_DONE`-এ নেওয়া হয়েছে: ওর সারি **ব্যবসার সাথে বাড়ে**,
     * আর যোগফলটা আলাদা কোয়েরিতে নিলেই পাতা ভাগ করা যায়।
     *
     * ⓘ `NO_COMPANY_COLUMN` একটা **তথ্য**: ঐ টেবিলগুলোয় কোম্পানির ঘর
     * সত্যিই নেই, আর সেটা কমানো যায় না। ⚠️ `AS_SHIPPED` একটা ভিত্তিরেখা,
     * আর বাকি দুইটা মালিকের চাওয়া — ওগুলো **বাড়াই উচিত**।
     *
     * @var list<string>
     */
    private const NOT_EXCUSES = [
        'EveryListScreenPaginatesTest::NOT_REALLY_A_LIST',
        'EveryRawQueryNamesItsCompanyTest::NO_COMPANY_COLUMN',
        'EveryUserListAsksWhichCompanyTest::MUST_ASK',
        'MoneyMovementHasEveryFieldTheOwnerAskedForTest::FIELDS',
        'TheOtherNineLooksWereNotTouchedTest::AS_SHIPPED',

        /*
         * ⭐ এটা ছাড় নয়, তার উল্টো — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ তালিকাটা বলে **কোন সহায়কগুলো ভাষা ধরে ঘর বদলায়**
         * (`productName()`, `warehouseName()` …), আর প্রতিটা নাম
         * পাহারাটাকে আরও একটা জায়গা দেখতে বলে।
         *
         * ⚠️ নাম মুছলে পাহারা **কম** দেখে, বেশি নয় — তাই ছাড়ের
         * ছাদে গোনা হলে সংখ্যাটা উল্টো কথা বলত: চোখ বাড়ানোকে
         * "আরেকটা অজুহাত" হিসেবে লিখত।
         *
         * ⓘ আর তালিকাটার নিজের পাহারা আছে — ঐ ফাইলের শেষ দাবিটা
         * গোনে সহায়কগুলো সত্যিই কোডে আছে কি না।
         */
        'EveryGroupedReportGroupsByWhatItSelectsTest::BILINGUAL',

        /*
         * ⭐ এটাও ছাড় নয় — ২২ সেপ্টেম্বর ২০২৬।
         *
         * [[OnlyTheEngineWritesToTheLedgerTest]] বলে খতিয়ানে লেখে
         * কেবল ইঞ্জিন। `THE_ENGINE` সেই ইঞ্জিনের **নাম**।
         *
         * ⛔ নামটা মুছলে পাহারা শক্ত হয় না — সে নিজের
         * নকশার বিরুদ্ধেই লাল হয়, আর কাউকে ফেরত বসাতে হয়।
         * ⓘ তার লেখাগুলো ওই পরীক্ষার **নিয়ন্ত্রণ সারি**ও।
         *
         * ⚠️ আসল ছাড়টা আলাদা তালিকায় — `WRITES_ONLY_THE_SEAL`,
         * আর সেটা উপরে গোনা হয়েছে।
         */
        'OnlyTheEngineWritesToTheLedgerTest::THE_ENGINE',
    ];

    /**
     * আজকের বাস্তবতা — ২২ সেপ্টেম্বর ২০২৬-এ মেপে বসানো।
     *
     * ⭐ মালিকের ratchet নিয়ম: **কেবল কমবে**। বাড়াতে হলে এই লাইনটা
     * বদলাতে হয়, আর সেটা একটা সিদ্ধান্ত যা কমিটে চোখে পড়ে।
     */
    private const CEILING = 222;

    /*
     * ── ⚠️ ২২১ → ২২২, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────
     * একটা সারি: `EveryRouteIsGuardedTest::ANY_SIGNED_IN_USER`-এ
     * `licence.show`।
     *
     * ⓘ লাইসেন্সের পর্দাটা তালার বাইরে থাকতেই **হয়** — নাহলে কাগজ
     * না থাকলে ব্যবস্থাটা নিজের বন্ধ হওয়ার কারণটাই দেখাতে পারত না,
     * আর মেরামতের পথও থাকত না। ⚠️ `can:` বসালে দুষ্টচক্র: অনুমতি
     * পড়তে ডাটাবেজ লাগে, আর ঐ পথটাই তখন বন্ধ।
     *
     * ⭐ তবু এটা ছাড়ের ঘরেই গোনা হচ্ছে, আর সেটা ঠিক: কারণ থাকা মানে
     * ছাড়টা ছাড় নয় — এমন নয়। ⓘ কেউ যদি একদিন তালাটা তুলে দেন,
     * এই সারিটাও তুলে ছাদ ২২১-এ ফেরত নেওয়া উচিত।
     */

    /*
     * ── ⓘ ২১৯ → ২২১, ২২ সেপ্টেম্বর ২০২৬ ───────────────────
     * একটা সারি: `OnlyTheEngineWritesToTheLedgerTest::WRITES_ONLY_THE_SEAL`
     * — [[LedgerChain]] ইঞ্জিন এড়িয়ে খতিয়ানে লেখে, তবে কেবল
     * সিলের ঘর।
     *
     * ⭐ নতুন পাহারাটা দুইটা তালিকা নিয়ে এসেছে, কিন্তু এখানে
     * গোনা হয়েছে **একটা**। ⓘ `THE_ENGINE` ছাড় নয় — সে নিয়মটার
     * সংজ্ঞা, আর তার নাম মুছলে পাহারা শক্ত হয় না।
     *
     * ⚠️ সিদ্ধান্তটা ইচ্ছাকৃত: এক তালিকায় দুইটা নাম রাখলে হিসাবটা
     * উল্টো কথা বলত — ইঞ্জিনকে একটা অজুহাত লিখত।
     *
     * ⓘ `LedgerChain` যদি কখনো `debit`/`credit`-ও ছোঁয়, তখন এই
     * সারিটা একটা গর্ত হয়ে যাবে — আর সেই দিনটার জন্যই এটা
     * এখানে গোনা হলো।
     *
     * ── ⚠️ দ্বিতীয় সারিটা আমার নয়, আর তবু গোনা ───────────
     * হিসাব করে পেয়েছিলাম ২২০ (২১৯ + আমার একটা), মেপে এল
     * ২২১। ⛔ অনুমান করে ছাদ বসানো মানে একটা সংখ্যা লেখা
     * যার কারণ কেউ জানে না, তাই প্রতিটা তালিকার আকার
     * গুনে দেখা হয়েছে।
     *
     * ⓘ বাড়তি সারিটা `EveryListScreenPaginatesTest::STILL_BEING_DONE`-এ:
     * `approval.flow.index`, একজন সহকর্মীর staged কাজ। ⭐ সংখ্যাটা
     * **মাপা**, অনুমান করা নয়।
     */

    /*
     * ── ⚠️ ২১৬ → ২১৯, ২২ সেপ্টেম্বর ২০২৬ — আর এটা একটা সিদ্ধান্ত ─────
     * তিনটা সারি যোগ হয়েছে `EveryUserListAsksWhichCompanyTest::EXEMPT_QUERY`
     * থেকে, আর **একটাও নতুন ছাড় নয়** — আগের একটা ছাড়কে ভেঙে তিনটা করা
     * হয়েছে।
     *
     * ⓘ আগে `ProfileController.php` আর `LoginHistoryController.php`
     * **ফাইল ধরে** ছাড় পেত, তাই গোনায় আসত দুইটা সারি। ⛔ কিন্তু
     * ফাইল-ছাড় ঐ ফাইলের **ভবিষ্যতের ভুলগুলোও** ঢেকে দিত।
     *
     * ⭐ এখন ছাড়টা কোয়েরি ধরে, তাই তিনটা আলাদা কোয়েরি তিনবার গোনা হয়।
     * ⚠️ সংখ্যাটা বেড়েছে, অথচ **পাহারা শক্ত হয়েছে** — আর সেটাই এই
     * ছাদটার একটা সীমা: সে ছাড়ের **সংখ্যা** গোনে, **সূক্ষ্মতা** নয়।
     *
     * ⓘ যদি কেউ পরে ঐ তিনটা কোয়েরি সরিয়ে দেন, ছাদটা ২১৬-এ ফেরত নেওয়া
     * উচিত — আর ছাড়ের নিজের পাহারা (`test_every_exemption_still_belongs
     * _to_a_real_query`) সেদিন লাল হয়ে মনে করিয়ে দেবে।
     */

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
