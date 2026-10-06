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
        // ⓘ দেখায়, কিন্তু ইচ্ছা করে গোটা কোম্পানি — শাখার নিয়ম পাশ কাটানো, তাই ছাড় (1847bb6c, গোনা শুরু ৫ অক্টোবর ২০২৬)
        'EveryLedgerReaderSaysWhetherItShowsOrChecksTest::WHOLE_BY_DECISION',
        // ⓘ নিয়মের বাইরে রাখা সারি — তালিকা নয় (1fd5e3ed), যোগ অর্থহীন (9d79fff7), শাখা না-মানা পক্ষের খোঁজ (42ec6cef); গোনা শুরু ৫ অক্টোবর ২০২৬
        'EveryListPageHasTheToolbarTest::NOT_A_LIST',
        // ⓘ যোগফলের পট্টি ছাড়া তালিকা — গ্রাহকের পোর্টাল (নিজের লেআউট), আর পাশের সেশনের অকমিটেড নতুন পাতা (৫ অক্টোবর ২০২৬)
        'EveryListCarriesItsTotalsBarTest::EXEMPT',
        // ⓘ EveryListCarriesItsTotalsBarTest::PENDING এখন খালি (দামের বইয়ের পাতায় পট্টি বসেছে, ৫ অক্টোবর) — খালি ধ্রুবক চাবিওয়ালা
        //   তালিকা হিসেবে চেনা যায় না, তাই এখানে নাম রাখলে "খুঁজে পাওয়া যাচ্ছে না" মিথ্যা লাল (ec, ৬ অক্টোবর ২০২৬)।
        //   আবার সারি বসলে নামটা ফেরান, CEILING-ও সেই মাপে বাড়ান।
        'EveryMoneyListShowsAGrandTotalTest::NOT_A_SUM',
        'EveryPartyListFollowsTheViewedBranchTest::EXCUSED',
        'ACodeMadeFromANameCanComeOutEmptyTest::HANDLED',
        'ASkippedTestReadsAsAPassingOneTest::STEPPING_ASIDE_FOR_NOW',
        'AnAuditedModelMustSayWhoseBooksItBelongsToTest::EXEMPT',
        'EveryAlpineHandlerIsActuallyWiredTest::NOT_WIRED_ON_PURPOSE',
        'EveryChangeableRowRemembersWhoChangedItTest::EXEMPT',
        'EveryListScreenPaginatesTest::STILL_BEING_DONE',
        'EveryMasterNamesItsDuplicateGuardTest::EXEMPT',
        'EveryPolicyRuleIsActuallyReachedTest::REACHED_WITHOUT_A_ROUTE',
        'EveryPortalScreenAsksTheNarrowPathTest::HANDLED',
        // ⚠️ কোম্পানি না-ছাঁকা `exists` — Accounts-এর বাইরে বাকি, সংখ্যা কেবল কমে (চূড়ান্ত অডিট ⛔১০)
        'EveryExistsRuleNamesItsCompanyTest::NOT_YET',
        'EveryRawQueryNamesItsCompanyTest::DECLARED',
        // ⚠️ দেয়াল ছাড়া চলা রিপোর্ট — সারিগুলো কোনো শাখার নয় (অডিট ২৭ সেপ্টেম্বর, §৩)
        'EveryReportStandsBehindTheBranchWallTest::SAME_FOR_EVERYONE',
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
        // ⓘ +১১ ডিলারের দেয়ালের ছাড় (⛔১৬): বাঁধনটা নিজে, লিড ও সুযোগ (নিজেরটা নিজে দেখা), প্রমোশনের চারটা
        //   যাচাইয়ের সারি, আর চালান-ধরা পাঁচটা লাইন/ঘটনা যা দেয়ালঘেরা কাগজের ভিতর দিয়েই পৌঁছায়
        'EveryDealerPaperStandsBehindTheDealerWallTest::EXCUSED',
        // ⓘ ThePortalDoesNotCallThemDealersAgainTest::ALLOWED এখন খালি (পাহারা কেবল দেখা লেখা গোনে, ৬ অক্টোবর ২০২৬) —
        //   খালি ধ্রুবক চেনা যায় না, তাই নাম সরানো আর CEILING থেকে তার ৩টা সারি বাদ
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
        // ⓘ উল্টো দিকের তালিকা: এখানে নাম মানে ডিলারের দেয়ালে আটকানো বিক্রয়কর্মীর কাছে রিপোর্টটা **বন্ধ** (⛔১৬, ৬ অক্টোবর ২০২৬)
        'EveryDealerPaperStandsBehindTheDealerWallTest::REFUSED_TO_THE_WALLED',
        // ⓘ চাহিদা: অন্য কোম্পানিতে ঢোকার প্রতিটা দরজা তালিকায় থাকতে হবে, আর বেশিরভাগ নিজেই canInCompany() ডাকে (৫ অক্টোবর ২০২৬)
        'EveryDoorIntoAnotherCompanyAsksTheKeyThereTest::DOORS',
        // ⓘ চাহিদা: খাতা পড়া প্রতিটা ফাইল দেখায় নাকি যাচাই করে (শাখা-দেখা, ২৯ সেপ্টেম্বর ২০২৬)
        'EveryLedgerReaderSaysWhetherItShowsOrChecksTest::SHOWS',
        'EveryLedgerReaderSaysWhetherItShowsOrChecksTest::CHECKS',
        // ⓘ উল্টো দিকের তালিকা: এখানে নাম মানে শাখায় আটকানো মানুষের কাছে রিপোর্টটা **বন্ধ** (অডিট ২৭ সেপ্টেম্বর, §৩)
        'EveryReportStandsBehindTheBranchWallTest::REFUSED',
        // ⓘ চাহিদা: চালান পাকা করার প্রতিটা ডাক আর তার দরজা — ছাড় নয়, যা থাকতেই হবে (ধাপ ৫)
        'EveryChallanConfirmAsksHowTheGoodsTravelTest::KNOWN',
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
         * ⭐ এটা ছাড় নয়, তার উল্টো — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ তালিকাটা বলে **কোন কাজগুলোতে টাকা নড়ে** (পরিশোধ,
         * উত্তোলন, আদায়, বছর বন্ধ …), আর প্রতিটা নাম
         * [[EveryModuleSaysWhereMoneyMovesTest]]-কে আরও একটা জায়গা
         * মিলিয়ে দেখতে বলে।
         *
         * ⚠️ নাম মুছলে পাহারা **কম** দেখে, বেশি নয় — তাই ছাড়ের
         * ছাদে গোনা হলে সংখ্যাটা উল্টো কথা বলত।
         *
         * ⓘ আর তালিকাটা হাতে লেখা ইচ্ছাকৃত: রেজিস্ট্রি থেকে
         * পড়লে দাবিটা নিজেকেই মেলাত ([[never-supply-the-name-yourself]])।
         */
        'EveryModuleSaysWhereMoneyMovesTest::MONEY',

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
    /*
     * ── ⚠️ ২৪০ → ২৪৬, ২৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * ছয়টা সারি আগেই কমিট হয়ে গিয়েছিল, ছাদ না বদলে — তাই পাহারা লাল ছিল।
     * ⓘ মাপা, git ref ধরে প্রতিটা তালিকা (23faba74-এ ২৩৭ → HEAD-এ ২৪৬):
     *
     *   +২  EveryRouteIsGuardedTest::OPEN_TO_THE_WORLD
     *       `health` (bb08ab72) — বাইরের নজরদারি লগইন ছাড়াই ডাকে;
     *       `api.app.version` (271d754d) — ফোন লগইনের আগেই জানতে চায় সে পুরনো কি না।
     *       ব্যবসার ডেটা দেয় না কোনোটাই। সরানো যায় না।
     *   +৬  EveryRouteIsGuardedTest::TOKEN_SYNC
     *       `api.dashboard.today` (c83f34b3), `api.reports.index` (84e2fa6b) —
     *       প্রতিটা ঘর/সারি নিজের চাবি দেখে; এক দরজা-চাবি বসালে একটা ঘরের
     *       চাবিওয়ালাও আটকাতেন। সরানো যায় না।
     *       `api.reports.show` (84e2fa6b), `api.reports.export`,
     *       `api.documents.pdf`, `api.documents.papers` (8520617b) — পাহারা
     *       আছে, পদ্ধতির ভেতরে `abort_unless`-এ, গার্ডের চেনা আকারে নয়।
     *       ⏳ **চালুর পরে Gate/policy-তে সরানো হবে** (`authorize()`), তখন
     *       এই চারটা তালিকা থেকে ওঠে আর ছাদ ৪ কমে। ⓘ ফাঁক নেই, মাপা: চারটা
     *       দরজাতেই একই মানুষ চাবি ছাড়া ৪০৩, চাবিসহ ২০০
     *       (TheReportsNeverReachedThePhoneTest, ThePhoneCouldNotPrintWhatItSawTest),
     *       আর `abort_unless` লাইনটা সরালে চারটা দাবিই লাল।
     *   +১  EveryRawQueryNamesItsCompanyTest::DECLARED
     *       `InterCompanyService.php` (22b21c44) — কারণ ওখানেই লেখা।
     *   −৩  EveryChangeableRowRemembersWhoChangedItTest::EXEMPT
     *       (3179e390, 5d0aac7a, 70c7f3c9) — তিনটা মডেল নিরীক্ষায় ফিরেছে।
     *
     * ⚠️ নয়টা নতুন সারির আটটা abos-26/8f-এর নিজের, ঘোষণা ছাড়া বসানো —
     * ratchet ঠিক এটাই ধরতে বানানো।
     */
    /*
     * ── ⚠️ ২৩৭ → ২৪০, ২৮ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * তিনটা সারি: `EveryReportStandsBehindTheBranchWallTest::SAME_FOR_EVERYONE`
     * — অফারের তালিকা আর নোটিশের দুইটা রিপোর্ট, যাদের সারি কোনো শাখার নয়,
     * তাই শাখার দেয়াল ছাড়াই চলে (অডিট ২৭ সেপ্টেম্বর, §৩)।
     * ⓘ একই দিনের ২০টা **ফেরানো** রিপোর্ট (`REFUSED`) গোনায় নেই — ওগুলো
     * ছাড় নয়, বন্ধ দরজা।
     */
    /*
     * ── ⚠️ ২২৪ → ২৩৭, ২৪ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * চার সেশনের একদিনের কাজে তেরোটা সারি যোগ হয়েছে। ⛔ ratchet-টা
     * ঠিক এই মুহূর্তটার জন্যই আছে: বাড়াটা **একটা কমিটে চোখে পড়ে**,
     * আর সেটাই তার কাজ।
     *
     * ── ⓘ কোথা থেকে, মেপে দেখা ───────────────────────────────────────
     * `git diff 9eb168e7..HEAD` (যে কমিটে ২২৪ বসানো হয়েছিল):
     *
     *   +৬  EveryChangeableRowRemembersWhoChangedItTest::EXEMPT
     *       নোটিশের ছয়টা ঘটনার সারি — ওগুলো খাতা, বদলায় না
     *   +৬  EveryRouteIsGuardedTest::*
     *       নোটিশের ছয়টা API দরজা — ব্যবসার ডেটা দেয় না, নিজের নোটিশ দেয়
     *   −১  EveryListScreenPaginatesTest::STILL_BEING_DONE
     *       `system_admin.role.index` গঠনগত তালিকায় সরেছে, আর ঐ তালিকা
     *       গোনায় আসে না
     *
     * ⚠️ অর্থাৎ হিসাবে দাঁড়ায় ২৩৫, আর মেপে আসে **২৩৭** — দুইটার উৎস
     * ঐ diff-এ পাওয়া যায়নি। ⛔ তাই সংখ্যাটা **মাপা**, হিসাব করা নয়:
     * অনুমান করে ২৩৫ বসালে পাহারাটা কাল লাল হত এমন একটা কারণে যার সাথে
     * কারও নতুন কাজের সম্পর্ক নেই।
     *
     * ⭐ দুইটার উৎস খুঁজে বের করা পরের জনের কাজ, আর এই মন্তব্যটাই তার
     * শুরুর বিন্দু। ⓘ মাপার উপায়: `EXCUSES`-এর প্রতিটা তালিকার আকার
     * ছাপিয়ে আজকের সাথে মেলানো — আজকের সবচেয়ে বড় ছয়টা ছিল
     * `ANY_SIGNED_IN_USER` ৩৪ · `EXEMPT` ৩০ · `OPEN_TO_THE_WORLD` ১৬ ·
     * `EveryMasterNamesItsDuplicateGuard::EXEMPT` ১৫ ·
     * `FLOAT_IS_DELIBERATE` ১৫ · `STEPPING_ASIDE_FOR_NOW` ১৪।
     */
    /*
     * ── ⚠️ ২৪৬ → ২৪৯, ৩০ সেপ্টেম্বর ২০২৬ (abos-69) ──────────────────────
     * মালিকের QR (*"ekta qr add korbe …"*, *"ekoi code dilar scane kore …"*): তিনটা নতুন দরজা,
     * প্রত্যেকটা কারণসহ [[EveryRouteIsGuardedTest]]-এ —
     *   +১  OPEN_TO_THE_WORLD `sales.scan` — নিজে কিছু দেখায়/বদলায় না, কে এসেছেন দেখে পাঠায়
     *   +২  CUSTOMER_PORTAL `sales.portal.scan`, `.scan.received` — কেবল নিজের চালান, অন্যেরটা ৪০৪
     */
    /*
     * ── ⚠️ ২৪৯ → ২৫১, একই দিন ──────────────────────────────────────────
     *   +২  MoneyIsNeverAFloat::FLOAT_IS_DELIBERATE — PaperLook আর থার্মালের partial: কাগজের মাপ
     *       (মিমি, pt), টাকা নয়; নতুন নকশার A5 আর থার্মাল রূপ ভগ্নাংশে ছোট হয়
     */
    /*
     * ── ⚠️ ২৫১ → ২৬৫, ৩০ সেপ্টেম্বর ২০২৬ (abos-10) ─────────────────────
     *   +১৪  EveryExistsRuleNamesItsCompanyTest::NOT_YET — নতুন পাহারা (চূড়ান্ত অডিট ⛔১০) যে ১৪টা
     *        ফাইলে কোম্পানি না-ছাঁকা `exists` আগে থেকেই পেয়েছে। নতুন ফাঁক নয় — পুরনো ফাঁক প্রথমবার
     *        গোনা; Accounts-এর সবগুলো সারানো, বাকিগুলো সারালে সংখ্যা আর এই ছাদ দুইটাই নামবে।
     */
    /*
     * ── ⚠️ ২৬৫ → ২৮৮, ৫ অক্টোবর ২০২৬ (abos-95, কোঅর্ডিনেটর abos-63-এর সিদ্ধান্ত "ক") ─────────────
     * ছাদ না বদলেই ২৩টা সারি কমিট হয়েছিল, তাই পাহারা লাল ছিল। ⓘ মাপা, প্রতিফলনে, git ধরে (aef435a2-এ ২৬৫ → HEAD-এ ২৮৮);
     * কারণ প্রতিটা সারির নিজের ফাইল থেকে, নতুন বানানো নয়:
     *   +৮  EveryRouteIsGuardedTest::CUSTOMER_PORTAL — পোর্টালে নিজের DO (index, create, store, show, submit), নিজের
     *       বিক্রির দাগ (tracking, tracking.show), নিজের দাবির স্লিপ; সবগুলো নিজের গ্রাহক-id ধরে, অন্যেরটায় ৪০৩
     *   +৬  EveryRouteIsGuardedTest::GUARDED_INSIDE_THE_METHOD — বিক্রির দাগ (ওয়েব ও ফোন, তালিকা ও একটা):
     *       sales.delivery.view অথবা sales.order.view, mayTrack()-এ; DO-র অনুমোদিত পরিমাণ (ওয়েব ও ফোন):
     *       ApprovalEngine::canDecide()
     *   +৩  EveryRouteIsGuardedTest::TOKEN_SYNC (৪ যোগ, ১ বাদ) — নিজের ফোনের FCM টোকেন, নিজের কোম্পানি/শাখা বদল,
     *       ফোনের ড্যাশবোর্ড তালিকা ও মডিউল (প্রতিটা ঘর নিজের চাবিতে, মডিউলে $this->authorize())
     *   +২  EveryRouteIsGuardedTest::OPEN_TO_THE_WORLD — সই-করা কাগজের QR (নিজে কিছু দেখায় না, sales.scan-এ পাঠায়);
     *       টোকেন নবায়ন (refresh টোকেন নিয়ামক নিজে যাচাই করে)
     *   +২  EveryRouteIsGuardedTest::ANY_SIGNED_IN_USER — রিপোর্ট সেন্টার (প্রতিটা সারি নিজের মেনু-চাবিতে ছাঁকা);
     *       হোমের সাজ home.layout (কেবল নিজের users.home_layout, c738174c)
     *   +১  EveryChangeableRowRemembersWhoChangedItTest::EXEMPT — RecentPaper: কে কোন কাগজ শেষ কবে খুলেছেন, কেউ
     *       সম্পাদনা করে না
     *   +১  EveryReportStandsBehindTheBranchWallTest::SAME_FOR_EVERYONE — governance.periods: মাস বন্ধের তালা গোটা
     *       কোম্পানির (period_locks-এ branch_id নেই)
     */
    /*
     * ── ⚠️ ২৮৮ → ২৯০, একই দিন ─────────────────────────────────────────────────────────────────
     *   +২  EveryLedgerReaderSaysWhetherItShowsOrChecksTest::WHOLE_BY_DECISION — নতুন গোনা তালিকা (1847bb6c), আগে
     *       কোনো দিকেই বসানো ছিল না: কয়েক কোম্পানির একসাথে লাভ-ক্ষতি (GroupLedgerService — শাখার আইডি কেবল চলতি
     *       কোম্পানির); বাজেট (BudgetService — `fin_budgets`-এ শাখা নেই)
     */
    /*
     * ── ⚠️ ২৯০ → ৩১০, একই দিন — তিনটা তালিকা আগে কোনো দিকেই বসানো ছিল না, এখন গোনা শুরু ──────────────
     *   +৫   EveryListPageHasTheToolbarTest::NOT_A_LIST — তালিকা নয় এমন পাতা: বিক্রয়ের ড্যাশবোর্ড, বছর সমাপনী, খাতা
     *        মেলানোর যাচাই, পুরনো খাতা থেকে আনা, রান্নাঘরের বোর্ড
     *   +১১  EveryMoneyListShowsAGrandTotalTest::NOT_A_SUM — যোগ মিথ্যা বা অর্থহীন: নানা ধরনের সই, খাতের গাছ, বছর-শেষের
     *        পূর্বরূপ, সইয়ের সীমা, মাসের স্থিতি, চলমান স্থিতি, দুই দিকের স্থিতি, দুই দামের তালিকা, একক খরচ, ড্যাশবোর্ড
     *   +৪   EveryPartyListFollowsTheViewedBranchTest::EXCUSED — গ্রাহক-পোর্টালের লগইন, নাম ধরে খোঁজা (find, exists),
     *        কেবল মন্তব্যে নাম
     */
    /*
     * ── ⚠️ ৩১০ → ৩১৫, ৫ অক্টোবর ২০২৬ (abos-2c, DO+SO মেশানো, ধাপ ৯) ─────────────────────────────────────────
     *   +৫  EveryRouteIsGuardedTest::ANY_SIGNED_IN_USER — পোর্টালে গ্রাহকের নিজের বিক্রয় আদেশ (তালিকা, ফর্ম, লেখা,
     *       দেখা, জমা): গ্রাহকের কোনো চাবি থাকে না — DO-র পাঁচ দরজার হুবহু যমজ, দেয়াল [[CustomerPapers]]-এর সরু পথ
     */
    /*
     * ── ⚠️ ৩১৫ → ৩১৬, ৫ অক্টোবর ২০২৬ (abos-af, Inventory অডিট ম১) ─────────────────────────────────────────────
     *   +১  NoSensitiveFieldIsPrintedInTheOpenTest::OPEN_ON_PURPOSE — গণনার পাতার বাড়তির দর (count/form), stock/adjust-এর
     *       যমজ: খালি ইনপুট, কোনো সঞ্চিত দর ছাপা হয় না। ⛔ খরচের চাবির পেছনে লুকালে চাবিহীন গণনাকারী দরই দিতে পারতেন না,
     *       আর স্তর-ছাড়া বাড়তি আবার আটকাত (ম১-এর ভুলটাই)। ⚠️ ঘরের নাম বদলে পাহারা এড়ানো যেত — সেটা ফাঁকি, তাই ছাড়।
     */
    /*
     * ── ⚠️ ৩১৬ → ৩১৯, ৫ অক্টোবর ২০২৬ (abos-63, প্রতিটা তালিকায় যোগফলের পট্টি) ──────────────────────────────────
     *   +২  EveryListCarriesItsTotalsBarTest::EXEMPT — গ্রাহকের পোর্টালের DO আর আদেশের তালিকা: নিজের লেআউট, পট্টি
     *       আঁকার খোলসই নেই; ঘোষণা করলে কিছুই আঁকা হত না আর পাহারা মিথ্যা সবুজ হত
     *   +১  EveryListCarriesItsTotalsBarTest::PENDING — pricing-এর অকমিটেড দামের বইয়ের পাতা; পট্টি বসলেই নামটা কাটতে
     *       হয় (লাল), তাই সংখ্যাটা ফিরে ৩১৮-এ নামবে
     */
    /*
     * ── ⚠️ ৩১৯ → ৩১৮, ৫ অক্টোবর ২০২৬ (দর তালিকা, ধাপ ৩) ─────────────────────────────────────────────────────────
     *   −১  EveryListCarriesItsTotalsBarTest::PENDING — দামের বইয়ের পাতায় পট্টি বসল, নামটা কাটা হলো
     */
    /*
     * ── ⚠️ ৩২১ → ৩৩৪, ৬ অক্টোবর ২০২৬ (abos-ec, পাহারার মিথ্যা লাল — fe-র নির্দেশ) ─────────────────────────────────
     *   +৪  MoneyIsNeverAFloatTest::FLOAT_IS_DELIBERATE — লগইনের জায়গার অক্ষাংশ/দ্রাঘিমাংশ, দুইটা কেবল-তুলনা, আর কাউন্টারের
     *       পর্দার JS-এ পাঠানো দেখানোর সংখ্যা; কোনোটাই টাকা জমা বা গোনে না
     *   +২  MoneyNeverLandsOnAGroupAccountTest::GROUPS_BELONG_HERE — ছক আমদানির যমজ-খোঁজ আর খাতা-মেলানোর রিপোর্ট;
     *       কোনোটাই খাত বাছে না
     *   +৭  EveryPartyListFollowsTheViewedBranchTest::EXCUSED — একজনকে চাবি ধরে খোঁজা (পাঁচটা), আর আগেই শাখায় ছাঁকা
     *       আইডির নাম (দুই চার্ট); প্রতিটা লাইন পড়ে মেলানো
     */
    /*
     * ── ⚠️ +৩, ৬ অক্টোবর ২০২৬ (abos-2c, ফোনের ঘণ্টা) ─────────────────────────────────────────────────────────────
     *   +৩  EveryRouteIsGuardedTest::TOKEN_SYNC — api.notifications.index / read / read-all: নিজের খবর, ওয়েবের
     *       notifications.open আর read-all-এর মতোই চাবিহীন; মালিকানা NotificationService::markRead()-এ, অন্যেরটায় ৪০৪
     */
    /*
     * ── ⚠️ ৩৩৪ → ৩৩১, ৬ অক্টোবর ২০২৬ (abos-ec) ───────────────────────────────────────────────────────────────────
     *   −৩  ThePortalDoesNotCallThemDealersAgainTest::ALLOWED — পাহারা কেবল দেখা লেখা গোনে; তিনটা ছাড়ের শব্দই মন্তব্যে
     *       বা শনাক্তকারীতে ছিল, তাই তালিকা খালি
     */
    private const CEILING = 342;

    /*
     * ── ⚠️ ২২২ → ২২৪, ২৩ সেপ্টেম্বর ২০২৬ ───────────────────────────
     * দুইটা সারি: `EveryRouteIsGuardedTest::ANY_SIGNED_IN_USER`-এ
     * `system_admin.notice.index` আর `system_admin.notice.show`।
     *
     * ⛔ নোটিশের পড়ার দরজায় `can:` বসানো **যায় না**, আর কারণটা
     * ফিচারটার নিজের সংজ্ঞায়: নোটিশ যাঁদের জন্য লেখা তাঁরাই পড়বেন।
     * ⚠️ চাবি চাইলে ঠিক তাঁরাই বাদ পড়তেন, আর যে নোটিশ প্রাপক পড়তে
     * পারেন না সেটা নোটিশই নয়।
     *
     * ── ⓘ তাহলে পাহারা কোথায় ─────────────────────────────────────
     * শ্রোতায়। ⓘ `show()` নিজে মেলায় নোটিশটা এই মানুষটার ভূমিকার
     * কি না, আর না হলে **৪০৪** — ৪০৩ নয়, যাতে অচেনা কেউ নোটিশটার
     * অস্তিত্বও জানতে না পারেন। ⚠️ `$this->authorize()` দিয়ে লিখলে
     * ওটা ৪০৩ হত, আর ঐ পার্থক্যটা এখানে আসল।
     *
     * ⓘ `index`-এ মেলানোর কিছু নেই — যাঁর চাবি নেই তিনি কেবল
     * **নিজের** নোটিশগুলোই দেখেন ([[NoticeBoard::forUser()]])।
     *
     * ⭐ তবু দুইটাই ছাড়ের ঘরে গোনা হচ্ছে, আর সেটাই ঠিক: কারণ থাকা
     * মানে ছাড়টা ছাড় নয় — এমন নয়।
     */

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
