<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Services\DataScope;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Finance\Models\RentalContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * ⭐ ভাড়ার ঘণ্টির খবর — অর্থ-মডিউলের পরিকল্পনা, অংশ ৫ঘ (৬ অক্টোবর ২০২৬): চুক্তি শেষের ৬০ আর ৩০ দিন আগে, আর বকেয়া ভাড়া।
 * রোজ সকালের তাগাদার সাথে চলে ([[DueNotices::sendAll()]], `abos:money-due`)।
 *
 * ── কে, কতবার ──────────────────────────────────────────────────────────────
 *   · যাঁর ভাড়ার পাতা দেখার অধিকার আছে (`finance.rental.view`, `can()` ধরে — ব্যক্তির ব্যতিক্রমসহ)
 *   · ৬০ দিনের ধাপ — একবারই; ৩০ দিনের ধাপ (আর মেয়াদ পেরোলে) — সপ্তাহে একবার, শেষ না করা পর্যন্ত
 *   · বকেয়া ভাড়া — সপ্তাহে একবার, মাস না দেওয়া পর্যন্ত
 *
 * ⓘ হিসাব [[RentalDues]]-এর — ড্যাশবোর্ড আর ভাড়ার পাতা একই জায়গা থেকে পড়ে।
 */
final class RentalNotices
{
    public const ENDING = 'finance.rental_ending_';

    public const OVERDUE = 'finance.rent_overdue';

    /**
     * ⭐ বর্ষপূর্তি — প্রতি বছর একবার (মালিক, প্র১, ৬ অক্টোবর ২০২৬)।
     *
     * ⛔ আগে ধরনটাই বছর ধরে আলাদা ছিল (`…_2026`, `…_2027`) — "প্রতি বছর একবার" সেটা দিয়েই হত। কিন্তু খবর বন্ধ রাখা, চিঠির
     * পছন্দ আর নিয়ম — তিনটাই ধরন হুবহু মেলায় ([[NotificationService::wants()]], [[NotificationKinds::all()]]), তাই এই খবর কেউ
     * কোনোদিন বন্ধ করতে পারতেন না (২৪ অক্টোবর ২০২৬)। ⭐ এখন ধরন একটাই; বছরে একবার আসে চাবি থেকে (বর্ষপূর্তির তারিখ ধরে) আর
     * [[ANNIVERSARY_QUIET_DAYS]] থেকে।
     */
    public const ANNIVERSARY = 'finance.rental_anniversary';

    /** ⓘ আগের বছর-ধরা ধরন — কেবল "আগে বলা হয়েছে কি" দেখতে, যাতে বদলের দিনে একই বর্ষপূর্তি আবার না বাজে */
    public const ANNIVERSARY_BY_YEAR = 'finance.rental_anniversary_';

    /**
     * ⓘ একই চুক্তির বর্ষপূর্তির খবর এর মধ্যে আবার নয় — খবর যায় বর্ষপূর্তির ৩০ দিন আগে থেকে দিন পর্যন্ত, তাই পরের বছরের
     * প্রথম দিন আগেরটার অন্তত ৩৩৫ দিন পরে; ৩০০ দুইয়ের মাঝখানে (একই বছরে আবার নয়, পরের বছর আটকায় না)।
     */
    public const ANNIVERSARY_QUIET_DAYS = 300;

    /** বর্ষপূর্তির কত দিন আগে */
    public const ANNIVERSARY_DAYS = 30;

    /** সপ্তাহে একবার — [[DueNotices::QUIET_DAYS]]-এর একই */
    public const QUIET_DAYS = DueNotices::QUIET_DAYS;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly RentalDues $dues,
    ) {}

    /** @return array{ending: int, overdue: int, anniversary: int} কয়টা খবর গেল */
    public function sendAll(): array
    {
        return ['ending' => $this->ending(), 'overdue' => $this->overdue(), 'anniversary' => $this->anniversaries()];
    }

    /**
     * ⭐ বর্ষপূর্তির ঘণ্টা — চুক্তিতে বৃদ্ধির % লেখা থাকলে, বর্ষপূর্তির ৩০ দিন আগে, প্রতি বছর একবার (মালিক, প্র১, ৬ অক্টোবর ২০২৬:
     * "নিজে বাড়বে না, শুধু মনে করিয়ে দেবে")। ⓘ ভাড়া বদলায় না — মানুষ শর্ত বদলে লেখেন ([[RentalContractService::reviseTerms()]])।
     */
    public function anniversaries(): int
    {
        $sent = 0;

        foreach (RentalContract::query()->active()->where('increase_percent', '>', 0)->orderBy('id')->get() as $contract) {
            $next = $contract->nextAnniversary();

            if ($next === null || $next->gt(now()->startOfDay()->addDays(self::ANNIVERSARY_DAYS))) {
                continue;
            }

            $rent = (string) $contract->monthly_rent;
            $raised = bcdiv(bcmul($rent, bcadd('100', (string) $contract->increase_percent, 4), 4), '100', 2);

            $url = $this->urlOf($contract);

            $sent += $this->tell(
                self::ANNIVERSARY,
                $url,
                __('finance::rental_report.notice_anniversary', ['who' => $contract->counterparty, 'place' => (string) $contract->subject]),
                __('finance::rental_report.notice_anniversary_body', [
                    'date' => $next->translatedFormat('j M Y'), 'percent' => (string) $contract->increase_percent,
                    'rent' => Money::format($rent), 'next' => Money::format($raised),
                ]),
                self::ANNIVERSARY_QUIET_DAYS,
                // ⓘ নিয়মের মান (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩-এর অনুসরণ)
                ['party' => (string) $contract->counterparty, 'amount' => Money::format($raised), 'due_date' => $next->format('d/m/Y')],
                $contract->branch_id,
                about: $contract,
                // ⭐ এক বর্ষপূর্তি, এক ঘটনা — প্রত্যেকে একবারই, ক্রন যতবারই চলুক
                key: self::ANNIVERSARY.':'.sha1($url).':'.$next->toDateString(),
                alsoTold: [self::ANNIVERSARY_BY_YEAR.$next->year],
            );
        }

        return $sent;
    }

    public function ending(): int
    {
        $sent = 0;
        $first = RentalDues::WINDOWS[0];

        foreach ($this->dues->ending() as $contract) {
            $stage = RentalDues::stageOf($contract);
            $days = $contract->daysLeft();

            $sent += $this->tell(
                self::ENDING.$stage,
                $this->urlOf($contract),
                __('finance::rental_report.notice_ending', ['who' => $contract->counterparty, 'place' => (string) $contract->subject]),
                $days < 0
                    ? __('finance::rental_report.notice_ended_body', ['date' => $contract->ends_on->translatedFormat('j M Y'), 'days' => -$days])
                    : __('finance::rental_report.notice_ending_body', ['date' => $contract->ends_on->translatedFormat('j M Y'), 'days' => $days]),
                // ⓘ প্রথম ধাপ একবারই; শেষের ধাপ সপ্তাহে সপ্তাহে
                $stage === $first ? null : self::QUIET_DAYS,
                ['party' => (string) $contract->counterparty, 'due_date' => $contract->ends_on->format('d/m/Y'), 'days_left' => (string) $days],
                $contract->branch_id,
                about: $contract,
            );
        }

        return $sent;
    }

    public function overdue(): int
    {
        $sent = 0;

        foreach ($this->dues->overdue() as $row) {
            $sent += $this->tell(
                self::OVERDUE,
                $this->urlOf($row['contract']),
                __('finance::rental_report.notice_overdue', ['who' => $row['contract']->counterparty, 'place' => (string) $row['contract']->subject]),
                __('finance::rental_report.notice_overdue_body', [
                    'count' => count($row['months']),
                    'months' => implode(', ', array_map(fn ($m) => $m->translatedFormat('M Y'), $row['months'])),
                    'amount' => Money::format($row['amount']),
                ]),
                self::QUIET_DAYS,
                ['party' => (string) $row['contract']->counterparty, 'amount' => Money::format($row['amount']), 'status' => 'overdue'],
                $row['contract']->branch_id,
                about: $row['contract'],
            );
        }

        return $sent;
    }

    private function urlOf(RentalContract $contract): string
    {
        return route('finance.rental.show', $contract->id);
    }

    /**
     * যাঁদের ভাড়ার পাতা দেখার অধিকার আছে তাঁদের বলা — `$quietDays` দিনের মধ্যে একই খবর পেলে আবার নয়; `null` = কখনো আবার নয়।
     *
     * ⓘ [[DueNotices::tell()]]-এর একই নিয়ম, কেবল "একবারই" ধাপটা বাড়তি।
     * ⛔ চুক্তির শাখা যাঁর নাগালে নেই, তাঁকে নয় — ভাড়ার পাতা ঐ চুক্তি তাঁকে খোলে না ([[OpensOnlyInReach]]), অথচ খবরটা
     * পক্ষের নাম আর অঙ্ক নিয়ে যেত (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
     * ⛔ `$about` — কোন চুক্তির খবর (২৪ অক্টোবর ২০২৬): শাখা খবরে বসে, আর খোলার আগে চুক্তিটা আছে ও নাগালে কি না দেখা হয়
     * ([[NotificationService::mayOpen()]]); আগে খবরে কাগজ ছিল না, তাই পরে শাখা সরানো হলেও খুলত আর পাতা ৪০৪ দিত।
     *
     * @param  list<string>  $alsoTold  এই ধরনগুলোর আগের খবরও "বলা হয়েছে" ধরা হয়
     */
    private function tell(string $type, string $url, string $title, string $body, ?int $quietDays, array $data = [], int|string|null $branchId = null, ?Model $about = null, ?string $key = null, array $alsoTold = []): int
    {
        $sent = 0;
        $scope = app(DataScope::class);

        foreach ($this->whoCan('finance.rental.view') as $user) {
            if (! $scope->allows($user, UserDataScope::BRANCH, $branchId === null ? null : (int) $branchId)) {
                continue;
            }

            $told = Notification::query()->where('user_id', $user->id)->whereIn('type', [$type, ...$alsoTold])->where('url', $url)
                ->when($quietDays !== null, fn ($q) => $q->where('created_at', '>=', now()->subDays($quietDays)))
                ->exists();

            // ⭐ একই চুক্তির একই দিনের খবর একবারই (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)
            if (! $told && $this->notifications->send($user, $type, $title, $body, $url, key: $key ?? $type.':'.sha1($url).':'.now()->toDateString(), about: $about, data: $data) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @return Collection<int, User> */
    private function whoCan(string $permission): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->get()
            ->filter(fn (User $user) => $user->can($permission))
            ->values();
    }
}
