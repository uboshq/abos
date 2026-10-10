<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Concerns\IsAudited;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tests\TestCase;

/**
 * যে সারি বদলাতে পারে, সে মনে রাখে কে বদলেছে।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ২ সেপ্টেম্বর ২০২৬-এ গোনা হলো: ১১৯টা মডেলের **১০১টায়** [[IsAudited]],
 * বাকি ১৮টায় নেই। বেশিরভাগ বাদ পড়াই ঠিক — খতিয়ান ও চলাচল কখনো বদলায়
 * না, আর অডিটের খাতা নিজেকে অডিট করলে চক্র হত।
 *
 * কিন্তু ওই ১৮টার ভেতরে দুইটা ছিল যেগুলো **প্রতিদিন বদলায়**:
 *
 * ```
 * Setting  — একটা সেটিং কে বদলাল, কবে, কী থেকে কীসে : কোথাও লেখা ছিল না
 * Company  — BIN, TIN, আইনি নাম, মুদ্রা, আর is_active : একই
 * ```
 *
 * `Setting`-টা তাত্ত্বিক নয়। এই রিপোর নিজের খাতায় লেখা আছে (Findings,
 * ৩০ আগস্ট) — *"এক ট্যাব সংরক্ষণ করায় ৩৪টা সেটিং নীরবে বন্ধ"*। কে
 * বন্ধ করল তা বের করতে গোটা সন্ধ্যা গেছে, **কারণ জিজ্ঞেস করার মতো
 * কোনো খাতা ছিল না।**
 *
 * ── কেন এই পাহারাটা তালিকা ধরে চলে ───────────────────────────────────
 * "সব মডেলে অডিট" নিয়মটা ভুল হত — কিছু বাদ পড়া সত্যিই দরকার। কিন্তু
 * "কিছু বাদ পড়ে" আর "যা খুশি বাদ পড়তে পারে" এক জিনিস নয়।
 *
 * তাই তালিকাটা এখানে, **কারণসহ**। নতুন কোনো মডেল অডিট ছাড়া এলে
 * পাহারাটা লাল হবে, আর সবুজ করার একমাত্র উপায় হবে **এখানে এসে কারণটা
 * লিখে দেওয়া** — অর্থাৎ সিদ্ধান্তটা একবার অন্তত ভাবতে হবে।
 */
class EveryChangeableRowRemembersWhoChangedItTest extends TestCase
{
    /**
     * ইচ্ছাকৃতভাবে অডিটের বাইরে — প্রতিটার কারণ পাশে।
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        // ── খাতাটা নিজে ─────────────────────────────────────────────
        'App\Models\AuditTrail' => 'অডিটের খাতা নিজেকে অডিট করলে প্রতিটা সারি অসীম সারি জন্ম দিত',
        'App\Models\AuditFieldChange' => 'একই কারণ — এটা অডিট সারির ঘর-ধরে-ধরে অংশ',

        // ── শুধু যোগ হয়, কখনো বদলায় না (ভুল হলে উল্টো সারি বসে) ─────
        // ── নোটিশের ঘটনা-সারি — ২৪ সেপ্টেম্বর ২০২৬ ─────────
        //
        // ⓘ চারটাই একটা ঘটনার স্মৃতি: পড়েছি, সরিয়েছি, মেনেছি,
        // তাগাদা গেছে, লক্ষ্য বসেছে। ⚠️ এগুলো কেউ **সম্পাদনা করে না** —
        // হয় বসে, নয় বসে না।
        //
        // ⛔ অডিট বসালে প্রতিটা পাতা-খোলা একটা অডিট সারি জন্ম দিত,
        // আর অডিটের খাতা দিনে হাজার সারিতে ভরত — যে খাতা বাস্তবে
        // দরকার হয় বছরে একবার।
        //
        // ⓘ আর কে কখন করল তা সারিতেই লেখা আছে (`user_id`,
        // `dismissed_at`, `acknowledged_at`, `sent_at`) — অডিট দ্বিতীয় কপি হত।

        // ── ⭐ `NoticeRead` এখান থেকে তোলা হলো — ২৫ সেপ্টেম্বর ২০২৬ ──
        //
        // ⓘ ২৩ সেপ্টেম্বর ওটাকে `IsAudited` দেওয়া হয়েছে, আর কারণটা
        // মডেলেই লেখা: অডিট ছাড়া "এই নোটিশটা কে কাদের জন্য করেছিলেন,
        // আর কখন বদলেছিলেন?" — এই প্রশ্নের উত্তর নেই।
        //
        // ⚠️ সারিটা তবু তালিকায় রয়ে গিয়েছিল, আর **সেটাই বিপদ**: ⛔ একটা
        // ছাড়ের তালিকা যদি এমন নাম রাখে যার আর ছাড় লাগে না, তবে পরের
        // বার কেউ অডিটটা তুলে নিলে তালিকাটা তাকে চুপচাপ পাস করিয়ে দিত।
        'App\Models\NoticeAck' => 'সইয়ের স্মৃতি — IP ও যন্ত্রসহ সারিতেই লেখা',
        'App\Models\NoticeDismissal' => 'সরিয়ে দেওয়ার স্মৃতি — একজন একবারই',
        'App\Models\NoticeReminder' => 'তাগাদা গেছে — সময়ের কাজের সারি, মানুষের নয়',
        'App\Models\NoticeTarget' => 'লক্ষ্যের চাবি — পুরোটা মুছে বসে, সারি বদলায় না',

        // ── নোটিশের সংস্করণ — অডিটের বিকল্প, অডিটের বিষয় নয় ──
        //
        // ⓘ `notice_versions` নিজেই একটা ইতিহাসের খাতা: প্রকাশিত
        // নোটিশ বদলালে পুরনো লেখাটা এখানে বসে। ⚠️ এর উপর আবার
        // অডিট বসানো মানে ইতিহাসের ইতিহাস।
        'App\Models\NoticeVersion' => 'সংস্করণই ইতিহাস — বসে, কখনো বদলায় না',

        'App\Models\LedgerEntry' => 'খতিয়ান শুধু যোগের — সারি বদলায় না, মোছে না',
        'App\Modules\Inventory\Models\StockMovement' => 'মজুদের চলাচলও তা-ই',
        'App\Modules\Inventory\Models\CostLayer' => 'খরচের স্তর চলাচল থেকেই জন্মায়',
        'App\Modules\Inventory\Models\CostLayerUse' => 'কোন স্তর থেকে কতটা গেল — শুধু যোগের',
        'App\Models\IssuedNumber' => 'একবার দেওয়া নম্বর ফেরত নেওয়া হয় না',

        // ── ⭐ `ApprovalDecision`-ও এখান থেকে তোলা — ২৫ সেপ্টেম্বর ২০২৬ ──
        //
        // ⓘ কারণটা এখনও সত্য — সিদ্ধান্ত একটা ঘটে যাওয়া ঘটনা, আর
        // মডেলেই `updating` বন্ধ করা। ⚠️ কিন্তু সে `IsAudited` ব্যবহার
        // করেই — সারিটা **বসার** দাগটা রাখার জন্য।
        //
        // ⛔ তাই ছাড়ের তালিকায় নামটা রাখা মানে তালিকাটা মিথ্যা বলা।
        'App\Models\LoginAttempt' => 'চেষ্টার ইতিহাস — নিজেই একটা লগ',
        'App\Models\ExportLog' => 'কে কী রপ্তানি করল — নিজেই একটা লগ',
        'App\Models\ErrorEvent' => 'ভুলের খাতা — ব্যবস্থাটা নিজে লেখে, মানুষ নয়',
        'App\Models\Notification' => 'পড়া/না-পড়া ছাড়া কিছু বদলায় না',
        // ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১ (১০ অক্টোবর ২০২৬)
        'App\Models\NotificationEvent' => 'খবর একটা ঘটনার প্রতিধ্বনি — আসল ঘটনা নিজের জায়গায় নিরীক্ষিত, খবরের ওপর মানুষের কাজ notification_audit_logs-এ',
        'App\Models\NotificationAuditLog' => 'নিজেই নিরীক্ষার খাতা, আর কখনো বদলায় না',
        'App\Models\NotificationJob' => 'যন্ত্রের পৌঁছানোর খাতা — হাতে আবার চেষ্টা আর বাতিল notification_audit_logs-এ',
        'App\Models\NotificationDeliveryAttempt' => 'প্রতিটা চেষ্টার একবার-লেখা সারি, কখনো বদলায় না',
        'App\Models\NotificationSubscription' => 'ব্যক্তির নিজের ব্রাউজার — চালু আর বন্ধ notification_audit_logs-এ',
        'App\Models\DocumentDelivery' => 'কাগজ বেরোনোর ঘটনা — একবার ঘটে, কেউ বদলায় না (২০ সেপ্টেম্বর ২০২৬)',

        // ── ব্যক্তির নিজের সুবিধা, ব্যবসার তথ্য নয় ──────────────────
        'App\Models\RecentPaper' => 'কে কোন কাগজ শেষ কবে খুলেছেন — Ctrl+K-এর সুবিধা, কেউ সম্পাদনা করে না (২ অক্টোবর ২০২৬)',
        'App\Models\SavedView' => 'নিজের তালিকার নিজের ছাঁকনি',
        'App\Models\LookSkinVersion' => 'পর্দার রূপ — হিসাবের কিছু নয়',

        /*
         * ⓘ কে কোন খবর পেতে চান — ব্যক্তির পছন্দ, ব্যবসার তথ্য নয়, আর
         * সারিটার কোনো কোম্পানিও নেই (`user_id` ধরে)। ⚠️ অডিট বসালে
         * ইঞ্জিন কোম্পানি না পেয়ে নীরবে ফিরে যেত — অর্থাৎ খাতায় কিছুই
         * লেখা হত না, অথচ কোড দেখে মনে হত লেখা হচ্ছে।
         *
         * ⛔ এতদিন তালিকায় ছিল না, কারণ পাহারাটা `final class` দেখতই না
         * (২১ সেপ্টেম্বর ২০২৬)।
         */
        'App\Models\NotificationChoice' => 'ব্যক্তির নিজের পছন্দ, আর সারিটার কোনো কোম্পানি নেই',

        // ── ফোনের সাথে কথা বলার বইখাতা (২ সেপ্টেম্বর ২০২৬) ──────────
        //
        // চারটাই যন্ত্রের নিজের হিসাব, মানুষের সিদ্ধান্ত নয়। একটা
        // সিঙ্ক পাসে এই সারিগুলো কয়েকশোবার লেখা ও বদলায় — অডিটে
        // তুললে `audit_trails` **এদের দিয়েই ভরে যেত**, আর আসল
        // ব্যবসার বদলগুলো তার নিচে চাপা পড়ত। অডিটের খাতা তখন যা করার
        // জন্য বানানো ঠিক সেটাই আর করতে পারত না।
        //
        // আর যেটা সত্যিই অডিটে লাগে — কে কী পাঠাল, কখন, আর তার কী
        // হলো — সেটা `sync_changes`-এ **নিজেই** পুরোটা লেখা থাকে।
        'App\Models\SyncDevice' => 'একটা হ্যান্ডসেটের পরিচয় ও শেষ যোগাযোগের সময় — প্রতিটা কলে বদলায়',
        'App\Models\SyncState' => 'ডিভাইসের ওয়াটারমার্ক — যন্ত্রের নিজের বুকমার্ক, প্রতিটা সিঙ্কে এগোয়',
        'App\Models\SyncChange' => 'ফোন যা পাঠাল তার নিজেরই খাতা — শুধু যোগের, আর এটাই অডিট',
        'App\Models\SyncConflict' => 'দ্বন্দ্বের ঘটনা; নিষ্পত্তির সময় resolved_by ও resolved_at সারিতেই লেখা থাকে',

        // ── মূল সারির সাথেই লেখা হয়, তাই আলাদা করে নয় ───────────────
        // ⭐ `ApprovalFlowStep` এখান থেকে তোলা — অডিট §৩, ২৭ সেপ্টেম্বর ২০২৬।
        // ⛔ কারণ হিসেবে লেখা ছিল "ছক সংরক্ষণে স্তরগুলো পুরোটা বদলে বসে;
        // ApprovalFlow নিজে অডিটে আছে" — ⚠️ কিন্তু ছকের অডিট কেবল ছকের ঘর
        // দেখে, **কে সই দেবেন** তা নয়। ⓘ অর্থাৎ অনুমোদনকারী বদলানো ছিল
        // গোটা ব্যবস্থার একমাত্র খাতাহীন ক্ষমতা-বদল।
        'App\Modules\Accounts\Models\LoanInstalment' => 'কিস্তি ঋণ থেকেই তৈরি হয়, আর Loan নিজে অডিটে আছে',

        // ── ব্যাকআপের নিজের খাতা (৩ সেপ্টেম্বর ২০২৬) ────────────────
        //
        // দুইটাই **কেবল যন্ত্র লেখে** — মেপে দেখা: `BackupRun::create()`
        // ও `BackupVerification::create()` ডাকে একমাত্র `BackupRunner`,
        // আর কোনো কন্ট্রোলার সারিগুলো বদলায় না। মোছার রুটও নেই
        // (ব্যাকআপ মডিউলের একমাত্র DELETE রুট গন্তব্যের জন্য)।
        //
        // অর্থাৎ "কে বদলাল" প্রশ্নটার উত্তর সবসময়ই "ব্যবস্থা নিজে",
        // আর সেটা সারিতেই লেখা: `triggered_by` বলে রাতের কাজ না
        // কারও চাপা বোতাম, `started_at` বলে কখন।
        //
        // ⚠️ আর যেটা মানুষ সত্যিই বদলায় — **গন্তব্য** — সেটা অডিটে
        // আছেই (`BackupDestination` ব্যবহার করে `IsAudited`)। ওখানেই
        // প্রশ্নটার মানে থাকে: একটা গন্তব্য মুছে ফেললে **কপি যাওয়া
        // নীরবে বন্ধ হয়ে যায়**, আর তখন কে মুছল সেটা জানা দরকার।
        // ⓘ `BackupRun` **দুই জায়গায়** ঘোষণা করতে হয়: এখানে, আর
        // `app/Modules/Backup/module.php`-এর `audit_exempt`-এ — কারণ
        // `AuditTest` মডিউলের নিজের ঘোষণাটা পড়ে, এই তালিকাটা নয়।
        // পুরো কারণটা এখানে; ওখানে কেবল এই ফাইলের দিকে ইশারা।
        'App\Modules\Backup\Models\BackupRun' => 'রাতের কাজ নিজে লেখে; কে চালাল তা triggered_by-তেই আছে',
        'App\Modules\Backup\Models\BackupVerification' => 'ফিরিয়ে আনার পরীক্ষার ফল — যন্ত্রের নিজের খাতা, শুধু যোগের',
    ];

    /**
     * অডিটহীন মডেলের তালিকা আর উপরের তালিকা — হুবহু এক।
     */
    public function test_no_model_quietly_leaves_the_audit_trail(): void
    {
        /*
         * ⭐ "মডেল কি না" — প্রশ্নটা এখন PHP-কে করা হয়, লেখার চেহারাকে নয়।
         *
         * ── ⛔ কী ভাঙা ছিল — অডিট §৬, ২৭ সেপ্টেম্বর ২০২৬ ─────────────
         * নোঙরটা ছিল `extends .*Model` — লেখায় "Model" শব্দটা খোঁজা। ⚠️
         * তাই `class User extends Authenticatable` কোনোদিন **মডেল বলেই
         * গোনা হয়নি**: কেউ [[User]] থেকে `use IsAudited;` তুলে দিলে পাহারাটা
         * সবুজ থাকত — আর যে সারি (কে কাকে কোন চাবি দিল, কাকে বন্ধ করল)
         * সবচেয়ে বেশি খোঁজা হয়, সেটাই নিঃশব্দে খাতার বাইরে যেত।
         *
         * ⓘ আগের দুই ফাঁকও একই রোগের: `\nclass` নোঙর `final class` দেখত
         * না (২১ সেপ্টেম্বর, [[VoucherBillShare]] — টাকার টেবিল, অডিট
         * ছাড়া), আর `str_contains('use IsAudited;')` মন্তব্য-করা ট্রেইটও
         * গুনত। ⛔ তিনবারই প্রশ্নটা লেখার চেহারাকে, আর তিনবারই চেহারা বদলেছে।
         *
         * ⭐ এখন: ফাইল থেকে কেবল ক্লাসের নাম (PSR-4), বাকিটা রিফ্লেকশন —
         * `Model`-এর যেকোনো বংশধর (Authenticatable, Pivot, আরেক মডেল
         * থেকে জন্মানো), গোটা `app/` জুড়ে। ট্রেইট `class_uses_recursive()`
         * দিয়ে: মন্তব্য গোনে না, আর মা-ক্লাস থেকে পাওয়া অডিটও গোনে।
         */
        $unaudited = array_values(array_filter(
            $this->modelClasses(),
            fn (string $class) => $this->leavesNoTrail($class),
        ));

        sort($unaudited);
        $expected = array_keys(self::EXEMPT);
        sort($expected);

        $newlyMissing = array_diff($unaudited, $expected);
        $goneFromList = array_diff($expected, $unaudited);

        $this->assertSame([], array_values($newlyMissing), implode("\n", [
            'এই মডেলগুলোয় অডিট নেই, আর তালিকাতেও নেই:',
            '',
            implode("\n", $newlyMissing),
            '',
            'হয় `use IsAudited;` বসান, নাহলে এই পরীক্ষার EXEMPT তালিকায়',
            'কারণসহ যোগ করুন। কারণটা লেখাই এখানে আসল কাজ।',
        ]));

        $this->assertSame([], array_values($goneFromList), implode("\n", [
            'তালিকায় আছে অথচ এখন অডিটে ঢুকে গেছে (বা মডেলটাই নেই):',
            '',
            implode("\n", $goneFromList),
            '',
            'EXEMPT তালিকা থেকে সারিটা তুলে দিন — নাহলে তালিকাটা মিথ্যা বলে।',
        ]));
    }

    /**
     * আর যে দুইটার জন্য এই পাহারাটা লেখা হলো, সেগুলো সত্যিই ঢুকেছে।
     *
     * উপরের পরীক্ষাটা তালিকা মেলায়, তাই কেউ `Setting`-কে EXEMPT-এ বসিয়ে
     * দিলে সেটাও সবুজ থাকত। এই দুইটা নাম তাই আলাদা করে লেখা।
     */
    public function test_settings_and_companies_are_audited(): void
    {
        foreach ([Setting::class, Company::class] as $model) {
            $this->assertContains(
                IsAudited::class,
                class_uses_recursive($model),
                "{$model} অডিটের বাইরে চলে গেছে।",
            );
        }
    }

    /**
     * ⭐ "কিছু পেয়েছি" — পাহারাটা সত্যিই মডেলগুলো দেখছে (অডিট §৬, ২৭ সেপ্টেম্বর ২০২৬)।
     *
     * ⚠️ উপরের দাবি তালিকা মেলায়। খোঁজাটা একদিন কিছুই না পেলে "অডিটহীন"
     * তালিকা খালি হত — আর তখন লাল হত কেবল EXEMPT-এর দিকটা, *"তালিকায়
     * আছে অথচ অডিটে"* বলে: ⛔ ভুল বার্তা, আর মানুষ ছাড়ের তালিকাই মুছে দিতেন।
     *
     * ⭐ তাই সংখ্যা আর নাম, দুইটাই: ২৭ সেপ্টেম্বর ২০২৬-এ ২০৩টা মডেল।
     * ⓘ নামগুলোর মধ্যে [[User]] ইচ্ছাকৃত — সে-ই একমাত্র `extends
     * Authenticatable`, অর্থাৎ ঠিক যাকে পুরনো খোঁজা দেখত না।
     */
    public function test_the_guard_actually_sees_the_models(): void
    {
        $models = $this->modelClasses();

        $this->assertGreaterThan(150, count($models),
            'মডেল পাওয়া গেল মাত্র '.count($models).'টা (২৭ সেপ্টেম্বর ছিল ২০৩) — খোঁজাটা ভেঙেছে।');

        foreach ([User::class, Setting::class, Company::class] as $named) {
            $this->assertContains($named, $models,
                "{$named} মডেলের তালিকায় নেই — পাহারাটা তাকে দেখছেই না।");
        }
    }

    /**
     * ⛔ ইচ্ছাকৃত ভুল নমুনা — লগইনের মডেল, অডিট ছাড়া। এটা ধরা পড়তেই হবে।
     *
     * ⓘ নমুনাটা যায় **ঠিক সেই দুই প্রশ্ন দিয়ে** যা পাহারা নিজে করে
     * ([[isAModel()]], [[leavesNoTrail()]]) — আলাদা করে লেখা নকল দিয়ে নয়,
     * নাহলে নকলটা কামড়াত আর আসলটা ঘুমাত।
     *
     * ⚠️ উল্টো দিকও: অডিটওয়ালা একই গড়নের নমুনা **ছাড়া** পেতে হবে —
     * নাহলে "সবই ধরে" পাহারাও এখানে সবুজ থাকত।
     */
    public function test_a_login_model_without_the_trail_is_caught(): void
    {
        $bare = get_class(new class extends Authenticatable {});

        $this->assertTrue($this->isAModel($bare),
            '`extends Authenticatable` আবার মডেল বলে গোনা হচ্ছে না — অডিট §৬-এর ফাঁকটা ফিরেছে।');
        $this->assertTrue($this->leavesNoTrail($bare),
            'অডিট ছাড়া লগইন-মডেল পাহারা পেরিয়ে গেছে।');

        $audited = get_class(new class extends Authenticatable
        {
            use IsAudited;
        });

        $this->assertFalse($this->leavesNoTrail($audited),
            'অডিটওয়ালা মডেলকেও অডিটহীন বলছে — পাহারাটা চোখ বুজে সব ধরছে।');

        $this->assertFalse($this->isAModel(self::class), 'মডেল নয় এমন ক্লাসও মডেল বলে গোনা হচ্ছে।');

        // ⓘ ফাইল-ছাঁকনিও একই নমুনায়: `final`/`readonly` ক্লাস চোখে পড়ে, অ্যারে-ফেরানো ফাইল পড়ে না
        $this->assertTrue($this->declaresAClass("<?php\nnamespace X;\n\nfinal class Y extends Authenticatable\n{\n}\n"));
        $this->assertTrue($this->declaresAClass("<?php\nnamespace X;\n\nreadonly final class Y extends Z\n{\n}\n"));
        $this->assertFalse($this->declaresAClass("<?php\n\nreturn ['x' => Y::class];\n"));
    }

    /**
     * প্রতিটা মডেল — গোটা `app/`, নাম ফাইল থেকে (PSR-4), "মডেল কি না" PHP থেকে।
     *
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $models = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }

            $src = (string) file_get_contents($f->getPathname());

            /*
             * ⚠️ আগে দেখা: ফাইলটা ক্লাস ঘোষণা করে কি না।
             *
             * ⓘ `module.php`, রুট আর ভাষার ফাইল অ্যারে ফেরায়। ⛔ ওদের নাম
             * ধরে `class_exists()` ডাকলে অটোলোডার ফাইলটা `require` করে
             * **চালিয়ে দিত** — পাহারা পড়ার কথা, চালানোর নয়।
             */
            if (! $this->declaresAClass($src) || ! preg_match('/^namespace ([^;]+);/m', $src, $ns)) {
                continue;
            }

            $class = $ns[1].'\\'.$f->getBasename('.php');

            if (class_exists($class) && $this->isAModel($class)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    /** ⓘ লাইনের শুরুতে `class X` — `final`, `abstract`, `readonly` যেকোনো ক্রমে; মন্তব্যের `* class` নয়। */
    private function declaresAClass(string $source): bool
    {
        return preg_match('/^[ \t]*(?:(?:final|abstract|readonly)\s+)*class\s+\w+/m', $source) === 1;
    }

    /** ⭐ মডেল = `Model`-এর যেকোনো বংশধর, বিমূর্ত নয় (বিমূর্ত নিজে কোনো সারি রাখে না)। */
    private function isAModel(string $class): bool
    {
        $reflection = new \ReflectionClass($class);

        return $reflection->isSubclassOf(Model::class) && ! $reflection->isAbstract();
    }

    /** ⓘ অডিট নেই — নিজের, মা-ক্লাসের বা ট্রেইটের ভেতরের, কোথাও। */
    private function leavesNoTrail(string $class): bool
    {
        return ! in_array(IsAudited::class, class_uses_recursive($class), true);
    }
}
