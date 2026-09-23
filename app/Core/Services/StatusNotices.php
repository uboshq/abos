<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * স্ট্যাটাস বারের নোটিশ — এখন কী নজর দেওয়া দরকার।
 *
 * বারটায় আগে কোম্পানি ও শাখার নাম লেখা থাকত, অথচ ওই দুইটা টপবারেই বড়
 * করে দেখা যায়। একই তথ্য দুই জায়গায় থাকা মানে একটা জায়গা নষ্ট, আর
 * পর্দার নিচের পুরো একটা সারি নষ্ট করার মতো তথ্য ওটা নয়।
 *
 * এখন ওই জায়গায় বসে সেই একটা কথা যেটা এখন সত্যি এবং যেটা না জানলে
 * কাজ আটকায়: ব্যাকআপ হয়নি, খসড়া ভাউচার পড়ে আছে, হস্তান্তর গ্রহণের
 * অপেক্ষায়। কিছুই না থাকলে বারটা চুপ থাকে — "সব ঠিক আছে" লিখে জায়গা
 * ভরাট করার কোনো মানে নেই।
 *
 * প্রতিটা নোটিশ ক্লিকযোগ্য (নিয়ম ১): যে জায়গায় কাজটা করতে হবে সেখানেই
 * নিয়ে যায়। না নিলে ব্যবহারকারী জানতেন সমস্যা আছে, কিন্তু কোথায় গিয়ে
 * ঠিক করবেন তা নয়।
 */
final class StatusNotices
{
    /**
     * এক মিনিটের ক্যাশ।
     *
     * প্রতিটা পাতায় দুইটা COUNT চালানোর মানে হয় না — সংখ্যাগুলো মিনিটে
     * মিনিটে বদলায় না। আবার বেশিক্ষণ ধরে রাখলে ব্যবহারকারী ভাউচার পোস্ট
     * করার পরেও নোটিশটা থেকে যেত, আর সেটা বিরক্তিকর।
     */
    private const TTL = 60;

    /**
     * এখন যা যা নজরে আনার মতো।
     *
     * ক্রমটা জরুরি অনুযায়ী, সংখ্যা অনুযায়ী নয়। ব্যাকআপ না থাকা সবার
     * আগে: বাকি সব সমস্যার সমাধান আছে, ডিস্ক ফেল করার পর কিছুই আর
     * করার থাকে না।
     *
     * @return list<array{text: string, url: ?string, tone: string}>
     */
    public function all(): array
    {
        $companyId = CompanyContext::id();

        if ($companyId === null) {
            return [];
        }

        /*
         * ক্যাশের চাবিতে ব্যবহারকারীও।
         *
         * অনুমোদনের নোটিশটা এখন "আপনার সিদ্ধান্তের অপেক্ষায় কয়টা" —
         * অর্থাৎ একই কোম্পানির দুইজন দুইটা আলাদা সংখ্যা দেখেন। শুধু
         * কোম্পানি ধরে ক্যাশ করলে যিনি আগে পাতা খুলতেন তাঁর সংখ্যাটাই
         * পরের জনের ঘণ্টায় বসত — আর সেটা কেবল ভুল নয়, ফাঁসও: যাঁর
         * সিদ্ধান্তের অধিকার নেই তিনিও দেখে ফেলতেন কিছু ঝুলে আছে।
         */
        return Cache::remember(
            "abos.notice.{$companyId}.".(auth()->id() ?? 0),
            self::TTL,
            fn () => array_values(array_filter([
                /*
                 * ব্যাকআপের দুইটা বার্তা কখনো একসাথে নয়।
                 *
                 * ── কী ভাঙা ছিল ─────────────────────────────────────
                 * ব্যাকআপ বাসি হলে **দুইটাই** ফুটারে বসত:
                 *
                 *   "দুই দিনের বেশি ব্যাকআপ নেই…"
                 *   "ব্যাকআপ আর বই একই ডিস্কে…"
                 *
                 * মালিক ২৫ আগস্ট ২০২৬-এ ওটাকে ডুপ্লিকেট রেন্ডার বলে
                 * ধরেছেন — আর পড়াটা ন্যায্য: দুইটাই লাল, দুইটাই
                 * ব্যাকআপ নিয়ে, পাশাপাশি।
                 *
                 * ── কেন একটাই দেখানো হয় ────────────────────────────
                 * প্রথমটা সত্যি হলে দ্বিতীয়টার কোনো মানে নেই: **যে
                 * ব্যাকআপ নেই, তার দ্বিতীয় কপি হবে কীসের?** মিররের
                 * প্রশ্নটা ওঠে কেবল ব্যাকআপ থাকলে।
                 *
                 * এক সমস্যা, এক বার্তা — আর সেটাই সবচেয়ে বড়টা। দুইটা
                 * লাল বার্তা পাশাপাশি রাখলে মানুষ দুইটাকেই একটা ভেবে
                 * একটাই পড়েন, আর তখন ভুলটা কোনটা তা আর বোঝা যায় না।
                 */
                /*
                 * ⭐ মালিকের নিজের নোটিশ — সবার আগে, ২২ সেপ্টেম্বর ২০২৬।
                 *
                 * ⓘ নিচের বাকিগুলো **যন্ত্রের কথা** (ব্যাকআপ বাসি, খসড়া
                 * পড়ে আছে)। ⚠️ এটা **মানুষের কথা**, আর মানুষ যখন কিছু
                 * বলার জন্য নোটিশ লেখেন তখন সেটা যন্ত্রের রোজকার
                 * সতর্কতার পিছনে পড়ে থাকা উচিত নয়।
                 *
                 * ⛔ সব নোটিশ বারে আসে না — কেবল যেগুলোয় লেখক নিজে টিক
                 * দিয়েছেন। ⓘ সবগুলো পাঠালে জরুরি কথাটা ভিড়ে হারাত, আর
                 * বারটা আবার "কেউ পড়ে না" অবস্থায় ফিরত।
                 */
                ...$this->ownNotices(),

                $backupNotice = $this->backupNotice(),
                $backupNotice === null ? $this->mirrorNotice() : null,
                $this->approvalNotice(),
                $this->draftNotice(),
                $this->transferNotice(),

                /*
                 * ⭐ মেয়াদ ফুরিয়ে আসছে — ২৪ সেপ্টেম্বর ২০২৬।
                 *
                 * ⓘ খসড়া বা ঝুলে থাকা কাগজের পরে, কারণ ওগুলো **আজকের
                 * কাজ**। ⚠️ মেয়াদ কালকের ক্ষতি, কিন্তু ক্ষতিটা বড়:
                 * খসড়া কাগজ কাল পোস্ট করা যায়, মেয়াদ পেরোনো মাল কাল
                 * আর ফেরত পাঠানো যায় না।
                 */
                $this->expiryNotice(),
                // প্রতিষ্ঠানের নিজের নোটিশ সবার শেষে, কিন্তু বারে সবচেয়ে
                // বেশি জায়গা নেয় — ওটা নিয়ম, ক্ষণিকের অবস্থা নয়, তাই
                // সিস্টেমের সতর্কতাগুলো আগে চোখে পড়া উচিত
                $this->companyNotice(),
            ])),
        );
    }

    /**
     * সবচেয়ে জরুরি একটা — যেখানে এক সারির বেশি জায়গা নেই।
     *
     * @return array{text: string, url: ?string, tone: string}|null
     */
    public function current(): ?array
    {
        return $this->all()[0] ?? null;
    }

    /**
     * প্রতিষ্ঠানের নিজের লেখা নোটিশ।
     *
     * সিস্টেমের কোনো অবস্থা নয় — ব্যবসার সিদ্ধান্ত ("বাকি দেওয়া নিষেধ")।
     * লেখার জায়গা System Management → Control Panel, দেখার জায়গা প্রতিটা
     * পাতা।
     *
     * @return array{text: string, url: ?string, tone: string}|null
     */
    private function companyNotice(): ?array
    {
        $text = trim((string) app(SettingsService::class)->get('system.notice', ''));

        if ($text === '') {
            return null;
        }

        return [
            'text' => $text,
            'url' => null,
            'tone' => 'danger',
        ];
    }

    /**
     * সিদ্ধান্তের অপেক্ষায় থাকা অনুমোদন।
     *
     * এটা সবচেয়ে বেশি আটকে থাকা কাজ: কেউ একটা ছাড় চেয়ে বসে আছে, আর
     * অনুমোদনকারী জানেনই না। ইমেইল নেই, তাই না দেখালে অপেক্ষাটা
     * অনির্দিষ্টকাল চলে।
     *
     * @return array{text: string, url: ?string, tone: string}|null
     */
    private function approvalNotice(): ?array
    {
        $user = auth()->user();

        if ($user === null || ! Schema::hasTable('approvals')) {
            return null;
        }

        /*
         * "আমার সিদ্ধান্তের অপেক্ষায়", কোম্পানির মোট নয়।
         *
         * আগে এখানে কোম্পানির সব অপেক্ষমাণ অনুরোধ গোনা হত। ফলে যিনি
         * কোনো ছকেই নেই তিনিও রোজ "৩টি সিদ্ধান্তের অপেক্ষায়" দেখতেন
         * আর কিছুই করতে পারতেন না — আর যিনি সত্যিই সিদ্ধান্ত দেবেন
         * তিনিও বুঝতেন না কয়টা তাঁর।
         *
         * যে সংখ্যা দেখে কিছু করার নেই, মানুষ সেটা দেখা বন্ধ করে দেয় —
         * আর তারপর যেদিন সংখ্যাটা তাঁরই, সেদিনও দেখে না।
         */
        if ($user->cannot('approval.decide')) {
            return null;
        }

        /*
         * গোনাটা ডাটাবেজে — সারি না তুলে।
         *
         * আগে ছিল `pendingFor($user)->count()`, অর্থাৎ প্রতিটা অপেক্ষমাণ
         * অনুরোধ (আর প্রতিটার অনুরোধকারী) মেমরিতে তুলে তারপর গোনা।
         * ⚠️ আর এই পট্টিটা **প্রায় প্রতিটা পাতায়** বসে, তাই জটে পড়া
         * একটা কোম্পানিতে ওটাই হত সবচেয়ে দামি কোয়েরি — একটা সংখ্যার
         * জন্য, যার পাশে কোনো সারিই দেখানো হয় না।
         */
        $count = app(ApprovalEngine::class)->pendingQueryFor($user)->count();

        if ($count === 0) {
            return null;
        }

        return [
            'text' => trans_choice('core.notice.awaiting_decision', $count, ['count' => $count]),

            /*
             * লিংকটা এখন আছে।
             *
             * নোটিশটা লেখা হয়েছিল অনুমোদনের পর্দা তৈরি হওয়ার আগে, তাই
             * url ছিল null — অর্থাৎ "তিনটা ঝুলে আছে" জানা যেত, কিন্তু
             * কোথায় গিয়ে সিদ্ধান্ত দিতে হবে তা নয়।
             */
            'url' => Route::has('approval.inbox.index') ? route('approval.inbox.index') : null,
            'tone' => 'pending',
        ];
    }

    /**
     * ⭐ প্রতিষ্ঠানের নিজের লেখা নোটিশগুলো — বারের জন্য।
     *
     * ── ⓘ কেন এখানে কোনো নিয়ম লেখা হয়নি ────────────────────────────
     * *"কে কোন নোটিশ দেখবেন"* প্রশ্নের উত্তর একটাই জায়গায়
     * ([[NoticeBoard]])। ⚠️ এখানে দ্বিতীয়বার লিখলে একদিন বারে এমন
     * একটা নোটিশ ঘুরত যেটা বোর্ডে খুলে পড়াই যেত না — আর ঐ পার্থক্যটা
     * **নীরব** হত।
     *
     * ⛔ লগইন করা কেউ না থাকলে খালি। ⓘ বারটা তখন আঁকাই হয় না, কিন্তু
     * কনসোল বা কিউ থেকে ডাকা হলে যেন ছুঁড়ে না ফেলে।
     *
     * @return list<array{text: string, url: ?string, tone: string}>
     */
    private function ownNotices(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(NoticeBoard::class)->forTicker($user)
            ->map(fn (Notice $notice): array => [
                'text' => (string) $notice->title,

                /*
                 * ⓘ id আর সরানো যায় কি না — বারের ক্রসটার জন্য।
                 *
                 * ⚠️ যন্ত্রের বার্তাগুলোতে এই দুইটা ঘর থাকে না, আর সেটাই
                 * ঠিক: ⛔ ব্যাকআপ হয়নি বলে সতর্কতা সরানো গেলে সমস্যাটা
                 * সরত না, কেবল খবরটা সরত।
                 */
                'id' => $notice->id,
                'can_dismiss' => $notice->priority?->canBeDismissed() ?? true,

                /*
                 * ⓘ ক্লিক করলে পুরো নোটিশটা — নিয়ম ১। ⚠️ শিরোনামটা
                 * বারে ধরে, বিস্তারিত ধরে না; পথটা না থাকলে মানুষ
                 * জানতেন কিছু একটা হয়েছে, কী হয়েছে তা নয়।
                 */
                'url' => Route::has('system_admin.notice.show')
                    ? route('system_admin.notice.show', $notice->id)
                    : null,

                // ⓘ নীল — এটা সতর্কতা নয়, খবর।
                'tone' => 'info',
            ])
            ->all();
    }

    /**
     * শেষ ব্যাকআপ কবে।
     *
     * দুই দিনের বেশি পুরনো হলে বলা হয়। "গতকাল হয়নি" বললে ছুটির দিনে
     * প্রতি সোমবার একটা মিথ্যা সতর্কতা আসত; দুই দিন দিলে সেটা এড়ানো
     * যায় অথচ সত্যিকারের ব্যর্থতা লুকায় না।
     *
     * @return array{text: string, url: ?string, tone: string}|null
     */
    private function backupNotice(): ?array
    {
        $latest = app(BackupService::class)->latest();

        $stale = $latest === null
            || Carbon::createFromTimestamp(filemtime($latest), config('app.timezone'))->lt(now()->subDays(2));

        if (! $stale) {
            return null;
        }

        return [
            'text' => __('core.notice.backup_stale'),
            // ব্যাকআপের কোনো পর্দা নেই — এটা কমান্ড লাইনের কাজ, তাই
            // লিংকও নেই। যে লিংক কোথাও নিয়ে যায় না সেটা না দেওয়াই ভালো।
            'url' => null,
            'tone' => 'danger',
        ];
    }

    /**
     * ব্যাকআপটা কি একই ডিস্কে পড়ে আছে?
     *
     * ── কেন এই পাহারাটা লেখা ছিল, অথচ ছিল না ────────────────────────
     * `config/abos.php`-এ দ্বিতীয় গন্তব্যের পাশে লেখা আছে:
     * *"খালি রাখলে সতর্কবার্তা আসে, কাজ থামে না"*। কথাটা সত্যি ছিল না —
     * **সতর্কবার্তাটা কোনোদিন বানানোই হয়নি**। মন্তব্যটা একটা পাহারার
     * দাবি করত যা কোথাও নেই, আর এই প্রকল্পে ঠিক এই ভুলটাই বারবার ফেরে।
     *
     * ধরা পড়েছে ২২ আগস্ট, চালু সার্ভারে: `ABOS_BACKUP_MIRROR` বসানো নেই,
     * আর মেশিনে একটাই ডিস্ক (`disk3`)। অর্থাৎ ডাটাবেজ ও প্রতিটা ডাম্প
     * একই থালায় — যেই একটা ক্ষেত্রে ব্যাকআপ সবচেয়ে বেশি দরকার (ডিস্ক
     * নষ্ট), ঠিক সেখানেই সেটা নেই।
     *
     * ── দুইটা আলাদা ব্যর্থতা, দুইটা আলাদা বার্তা ────────────────────
     * এক · গন্তব্যই বসানো নেই — কেউ কোনোদিন বসায়নি।
     * দুই · বসানো আছে, কিন্তু ওখানে টাটকা কিছু নেই — পেনড্রাইভ খুলে
     *        নেওয়া হয়েছে, নেটওয়ার্ক ড্রাইভ আর মাউন্ট হয় না, বা ডিস্ক
     *        ভরে গেছে। তিনটাই নীরব, কারণ ব্যতিক্রমটা কেবল রাতের লগে
     *        থাকে আর সকালে কেউ লগ পড়ে না।
     *
     * দুইটাকে এক বার্তায় মিলিয়ে দিলে দ্বিতীয়টা প্রথমটার মতো দেখাত, আর
     * যিনি "বসানোই তো আছে" জানেন তিনি বার্তাটা উপেক্ষা করতেন।
     *
     * @return array{text: string, url: ?string, tone: string}|null
     */
    private function mirrorNotice(): ?array
    {
        $backups = app(BackupService::class);

        if ($backups->mirrorPath() === null) {
            return [
                'text' => __('core.notice.backup_no_mirror'),
                'url' => null,
                'tone' => 'danger',
            ];
        }

        /*
         * ফোল্ডারটা দেখা হয় না — শেষ সফল কপির লেখা কাগজটা পড়া হয়।
         *
         * ── কেন সরাসরি দেখা চলে না ──────────────────────────────────
         * মিরর হতে পারে একটা নেটওয়ার্ক ড্রাইভ, আর মাউন্ট না থাকলে
         * `is_dir()` কয়েক সেকেন্ড ঝুলে থাকে। এই পাহারাটা বসে
         * **প্রতিটা পাতার ফুটারে** — অর্থাৎ ড্রাইভ উধাও হলে গোটা ERP
         * ধীর হয়ে যেত, আর কারণটা কেউ ধরতে পারত না, কারণ ভুল কিছু
         * ঘটছে না; কেবল অপেক্ষা।
         *
         * দূরের পথটা তাই ছোঁয়া হয় কেবল রাতে, ব্যাকআপ নেওয়ার সময়।
         * এখানে কেবল নিজের ডিস্কের একটা ছোট কাগজ পড়া হয়।
         */
        $mirrored = $backups->mirroredAt();

        /*
         * এখানেও দুই দিন — মূল ব্যাকআপের নোটিশের মতোই।
         *
         * একই সীমা ইচ্ছাকৃত: দুইটা আলাদা হলে একদিন একটা বলত "ঠিক আছে"
         * আর অন্যটা "পুরনো", আর কোনটা বিশ্বাস করতে হবে সেটা কেউ জানত না।
         */
        $stale = $mirrored === null || $mirrored->lt(now()->subDays(2));
        if (! $stale) {
            return null;
        }

        return [
            'text' => __('core.notice.backup_mirror_stale'),
            'url' => null,
            'tone' => 'danger',
        ];
    }

    /**
     * @return array{text: string, url: ?string, tone: string}|null
     */
    private function draftNotice(): ?array
    {
        if (! Schema::hasTable('vouchers')) {
            return null;
        }

        $count = DB::table('vouchers')
            ->where('company_id', CompanyContext::id())
            ->where('status', DocumentStatus::DRAFT)
            ->whereNull('deleted_at')
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'text' => trans_choice('accounts::message.draft_vouchers', $count, ['count' => $count]),
            'url' => Route::has('accounts.voucher.index')
                ? route('accounts.voucher.index', ['type' => 'journal'])
                : null,
            'tone' => 'pending',
        ];
    }

    /**
     * @return array{text: string, url: ?string, tone: string}|null
     */
    /**
     * ⭐ যত লটের মেয়াদ ফুরিয়ে আসছে — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ রিপোর্টটা ছিল, তবু মাল নষ্ট হত ─────────────────────────────
     * মেয়াদের রিপোর্ট (`inventory.expiring`) আগে থেকেই আছে, দিন-গোনা
     * সহ। ⚠️ কিন্তু **কেউ ওটা নিজে থেকে খোলে না** — খোলে তখনই, যখন
     * কেউ বলে খুলতে। ⓘ ফল: রিপোর্টটা নিখুঁতভাবে জানাত মাল মেয়াদ
     * পেরিয়ে গেছে, আর ফেরত পাঠানোর সময়টা ততক্ষণে চলে গেছে।
     *
     * ⭐ এটা ঠিক সেই **অনুপস্থিত জোড়া**: হিসাবটা ছিল, খবরটা যেত না।
     *
     * ── ⚠️ দিনের সংখ্যাটা প্রতিষ্ঠানের, কোডের নয় ──────────────────────
     * ৯০ দিন ওষুধে ঠিক, ⛔ দুধে অর্থহীন। ⓘ তাই `expiry_alert_days`
     * সেটিং থেকে, আর শূন্য মানে সতর্কতা বন্ধ।
     *
     * ── ⛔ আর তারিখটা অ্যাপের ঘড়ি ধরে, ডাটাবেজের নয় ──────────────────
     * ⚠️ `CURDATE()` উত্তর দেয় **ডাটাবেজ সার্ভারের** ঘড়ি ধরে। ⓘ ২৫
     * আগস্ট ২০২৬-এ লাইভে দুইটা সত্যিই আলাদা ছিল, আর মেয়াদের হিসাবে
     * এক দিনের ভুল মানে ফেরত পাঠানোর সুযোগ হাতছাড়া। ⭐ একই কারণ
     * মেয়াদের রিপোর্টের গায়েও লেখা আছে।
     *
     * @return array{text: string, url: ?string, tone: string}|null
     */
    private function expiryNotice(): ?array
    {
        if (! Schema::hasTable('inv_batches') || ! Schema::hasTable('inv_stock_movements')) {
            return null;
        }

        $days = (int) app(SettingsService::class)->get('inventory.expiry_alert_days', 0);

        if ($days <= 0) {
            return null;
        }

        $today = Carbon::today();

        /*
         * ⛔ শূন্য বা ঋণাত্মক লট বাদ — তালিকাটা কাজের জিনিস, ইতিহাস নয়।
         *
         * ⚠️ এটা না থাকলে গত বছরের ফুরিয়ে যাওয়া প্রতিটা লট গোনা হত,
         * আর সংখ্যাটা এত বড় হত যে কেউ আর পড়ত না।
         */
        $count = DB::table('inv_batches as b')
            ->leftJoin('inv_stock_movements as m', 'm.batch_id', '=', 'b.id')
            ->where('b.company_id', CompanyContext::id())
            ->whereNull('b.deleted_at')
            ->whereNotNull('b.expiry_date')
            ->whereDate('b.expiry_date', '<=', $today->copy()->addDays($days)->toDateString())
            ->groupBy('b.id')
            ->havingRaw('COALESCE(SUM(m.floor_change), 0) > 0')

            /*
             * ⛔ কেবল যে কলামটা ধরে ভাগ করা হয়েছে — `select *` নয়।
             *
             * ── ⚠️ এটা প্রথম চেষ্টায় ভুল ছিল, আর মেপে ধরা পড়েছে ───────
             * ⓘ ডিফল্টে বিল্ডার `select *` পাঠায়, আর `group by b.id`-র
             * সাথে সেটা `ONLY_FULL_GROUP_BY`-তে ৫০০ দেয়:
             * *"Expression #15 of SELECT list is not in GROUP BY
             * clause"*। ⛔ লাইভ MySQL-এ ওই মোডটা চালু, তাই গোটা
             * অ্যাপের **প্রতিটা পাতা** ভাঙত — নিচের বারটা সব পাতায় বসে।
             *
             * ⚠️ গুনতিটা এখানেই হয়, `count()` কোয়েরি দিয়ে নয়: ⓘ
             * `having` সহ গুনতে গেলে বিল্ডার ভিতরের কোয়েরিটাকে
             * সাব-কোয়েরি বানায়, আর তাতে একই মোডে আবার ফাঁদ।
             */
            ->select('b.id')
            ->get()
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'text' => trans_choice('core.notice.expiring_batches', $count, [
                'count' => $count,
                'days' => $days,
            ]),

            /*
             * ⓘ রিপোর্টটা আগে থেকেই আছে, তাই নতুন কোনো পর্দা নয় —
             * ⚠️ বার্তাটা কেবল মানুষকে ওখানে **নিয়ে যায়**, আর সেটাই
             * এতদিন অনুপস্থিত ছিল।
             */
            'url' => Route::has('inventory.report.show')
                ? route('inventory.report.show', ['slug' => 'expiring'])
                : null,

            /*
             * ⚠️ `warning`, `danger` নয়। ⓘ মাল এখনো নষ্ট হয়নি — এটা
             * এখনো কিছু করার মতো সময় থাকার খবর। ⛔ লাল করলে ওটা
             * ব্যাকআপ-নেই বার্তার সমান জরুরি দেখাত, আর তখন দুইটাই
             * উপেক্ষিত হত।
             */
            'tone' => 'warning',
        ];
    }

    private function transferNotice(): ?array
    {
        if (! Schema::hasTable('money_transfers')) {
            return null;
        }

        $count = DB::table('money_transfers')
            ->where('company_id', CompanyContext::id())
            ->where('status', DocumentStatus::DRAFT)
            ->whereNull('deleted_at')
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'text' => trans_choice('accounts::message.pending_transfers', $count, ['count' => $count]),
            'url' => Route::has('accounts.transfer.index')
                ? route('accounts.transfer.index')
                : null,
            'tone' => 'info',
        ];
    }
}
