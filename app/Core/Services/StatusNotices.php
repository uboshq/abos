<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
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
                $this->backupNotice(),
                $this->mirrorNotice(),
                $this->approvalNotice(),
                $this->draftNotice(),
                $this->transferNotice(),
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

        $count = app(ApprovalEngine::class)->pendingFor($user)->count();

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
            || Carbon::createFromTimestamp(filemtime($latest))->lt(now()->subDays(2));

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

        $mirrored = $backups->latestMirror();

        /*
         * এখানেও দুই দিন — মূল ব্যাকআপের নোটিশের মতোই।
         *
         * একই সীমা ইচ্ছাকৃত: দুইটা আলাদা হলে একদিন একটা বলত "ঠিক আছে"
         * আর অন্যটা "পুরনো", আর কোনটা বিশ্বাস করতে হবে সেটা কেউ জানত না।
         */
        $stale = $mirrored === null
            || Carbon::createFromTimestamp(filemtime($mirrored))->lt(now()->subDays(2));

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
