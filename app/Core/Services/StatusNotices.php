<?php

declare(strict_types=1);

namespace App\Core\Services;

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

        return Cache::remember(
            "abos.notice.{$companyId}",
            self::TTL,
            fn () => array_values(array_filter([
                $this->backupNotice(),
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
        if (! Schema::hasTable('approvals')) {
            return null;
        }

        $count = DB::table('approvals')
            ->where('company_id', CompanyContext::id())
            ->where('status', 'pending')
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'text' => trans_choice('core.notice.awaiting_decision', $count, ['count' => $count]),
            'url' => null,
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
