<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\HandLoanAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * তারিখ পেরোনোর আগে খবরটা পৌঁছায় — অর্থের মানচিত্র §১৪ক ও §১৪খ।
 *
 * ── ⛔ কী ভাঙা ছিল, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * দুইটা পর্দা তারিখগুলো **গুনত**: জমার "মেয়াদ আসছে" ট্যাব আর হাতধারের
 * "মনে করিয়ে দেওয়া" ট্যাব। ⚠️ কিন্তু গোনাটা দেখা যেত কেবল পাতাটা
 * কেউ খুললে — আর যে তারিখটা ফসকে যায়, সেটা ঠিক ওই দিনগুলোতেই ফসকায়
 * যেদিন কেউ পাতাটা খোলেননি।
 *
 * ⭐ FDR-এর মেয়াদ ফসকানো মানে ব্যাংক আপনা থেকে নতুন মেয়াদে টাকাটা
 * আটকে দেয়, প্রায়ই কম হারে — অর্থাৎ ভুলটার দাম আছে, আর সেটা নীরব।
 *
 * ── ⓘ কেন সপ্তাহে একবার, রোজ নয় ─────────────────────────────────────
 * একই FDR-এর জন্য ত্রিশ দিন ধরে রোজ একটা করে খবর মানে ত্রিশটা খবর, আর
 * তারপর মানুষ ঘণ্টাটা দেখা বন্ধ করে দেন — [[NotificationService]]-এর
 * মাথায় লেখা ঠিক সেই রোগটা। ⛔ তাই একই কাগজের খবর সাত দিনে একবার;
 * পাঠানো খবরগুলোই মনে রাখে কবে শেষ গিয়েছিল, নতুন কোনো টেবিল নেই।
 */
final class DueNotices
{
    /** ⓘ একই কাগজের খবর এতদিনে একবারের বেশি নয় */
    public const QUIET_DAYS = 7;

    /** কত দিন আগে থেকে খবর যায় — পর্দার ডিফল্ট জানালার সমান */
    public const WINDOW_DAYS = 30;

    public const MATURING = 'finance.deposit_maturing';

    public const HAND_LOAN_DUE = 'finance.hand_loan_due';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * চলতি কোম্পানির দুইটা খবরই পাঠায়।
     *
     * @return array{maturing: int, hand_loans: int} কয়টা খবর গেল
     */
    public function sendAll(): array
    {
        return [
            'maturing' => $this->maturingDeposits(),
            'hand_loans' => $this->handLoansDue(),
        ];
    }

    /**
     * §১৪ক — যে জমার মেয়াদ সামনের ত্রিশ দিনে শেষ।
     */
    public function maturingDeposits(): int
    {
        $rows = Deposit::query()
            // ⓘ ঠিকানাটা ধরনের ইস্যুকারী ধরে বসে — নাহলে প্রতি সারিতে একটা কোয়েরি
            ->with('kind')
            ->open()
            ->whereNotNull('matures_on')
            ->whereDate('matures_on', '<=', now()->addDays(self::WINDOW_DAYS)->toDateString())
            ->orderBy('matures_on')
            ->get();

        $sent = 0;

        foreach ($rows as $deposit) {
            $url = route('finance.deposit.show', [
                'issuer' => $deposit->kind->issuer,
                'deposit' => $deposit->id,
            ]);

            $sent += $this->tell(
                self::MATURING,
                'finance.deposit.view',
                $url,
                __('finance::message.notice_maturing', [
                    'document' => $deposit->document_no,
                    'institution' => $deposit->institution,
                ]),
                __('finance::message.notice_maturing_body', [
                    'date' => $deposit->matures_on?->translatedFormat('j M Y') ?? '—',
                    'days' => $this->daysLeft($deposit->matures_on),
                ]),
            );
        }

        return $sent;
    }

    /**
     * §১৪খ — যে হাতধারের তারিখ পেরিয়েছে বা ত্রিশ দিনের ভিতরে।
     *
     * ⛔ তারিখহীন ধার বাদ — *"যখন পারো দিও"* ধরনের ধারে মনে করিয়ে
     * দেওয়ার কিছু নেই, আর ওগুলো ঢুকলে সত্যিকারের তাগাদাগুলো চোখ
     * এড়াত। ⓘ পর্দাটাও ঠিক এই নিয়মেই গোনে ([[HandLoanController::index()]])।
     */
    public function handLoansDue(): int
    {
        $soon = now()->addDays(self::WINDOW_DAYS)->toDateString();

        $rows = HandLoanAccount::query()
            // ⓘ বার্তায় মানুষটার নাম বসে — নাহলে প্রতি সারিতে একটা কোয়েরি
            ->with('person')
            ->where(fn ($q) => $q->whereNotNull('next_due_on')->orWhereNotNull('due_on'))
            ->get()
            ->filter(function (HandLoanAccount $account) use ($soon) {
                $due = $account->next_due_on ?? $account->due_on;

                return $due !== null && $due->toDateString() <= $soon;
            });

        $sent = 0;

        foreach ($rows as $account) {
            /*
             * ⚠️ চুকে যাওয়া খাতা বাদ — হিসাবটা খতিয়ান থেকে, সারিতে
             * রাখা কোনো সংখ্যা থেকে নয় ([[HandLoanService::standing()]])।
             * ⛔ শোধ হয়ে যাওয়া ধারের তাগাদা যায় একবার, আর তারপর
             * মানুষ বাকিগুলোও বিশ্বাস করেন না।
             */
            if (bccomp($this->balanceOf($account), '0', 4) === 0) {
                continue;
            }

            $due = $account->next_due_on ?? $account->due_on;

            $sent += $this->tell(
                self::HAND_LOAN_DUE,
                'finance.hand_loan.view',
                route('finance.hand_loan.show', $account->id),
                __('finance::message.notice_hand_loan', [
                    // ⓘ পর্দায় যে নামটা লেখা থাকে, হুবহু সেটাই ([[hand-loan/show]])
                    'person' => $account->person?->name() ?? '—',
                ]),
                __('finance::message.notice_hand_loan_body', [
                    'date' => $due->translatedFormat('j M Y'),
                    'days' => $this->daysLeft($due),
                ]),
            );
        }

        return $sent;
    }

    /**
     * যাঁদের পর্দাটা দেখার অধিকার আছে, তাঁদের বলা — আর যাঁকে এই সপ্তাহে
     * এই কাগজের খবর দেওয়া হয়েছে তাঁকে আবার নয়।
     *
     * @return int কয়জনের কাছে সত্যিই গেল
     */
    private function tell(string $type, string $permission, string $url, string $title, string $body): int
    {
        $sent = 0;

        foreach ($this->whoCan($permission) as $user) {
            if ($this->toldRecently($user->id, $type, $url)) {
                continue;
            }

            if ($this->notifications->send($user, $type, $title, $body, $url) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * এই কোম্পানির যে ব্যবহারকারীদের এই অনুমতি আছে।
     *
     * ── ⚠️ কেন `can()` ধরে, ভূমিকার তালিকা ধরে নয় ───────────────────
     * অনুমতিটা ভূমিকা থেকেও আসে, আবার ব্যক্তির ব্যতিক্রম থেকেও
     * ([[PermissionOverrides]]) — শেষেরটা কেবল `can()` জানে। ⛔ ভূমিকা
     * ধরে খুঁজলে যাঁর ব্যক্তিগত ব্যতিক্রমে অধিকারটা **কেড়ে নেওয়া**
     * হয়েছে, তিনিও খবর পেতেন।
     *
     * @return Collection<int, User>
     */
    private function whoCan(string $permission): Collection
    {
        $companyId = CompanyContext::id();

        return User::query()
            ->where('is_active', true)
            ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
            ->get()
            ->filter(fn (User $user) => $user->can($permission))
            ->values();
    }

    /**
     * ⓘ "সম্প্রতি বলা হয়েছে" — পাঠানো খবরগুলোই মনে রাখে।
     */
    private function toldRecently(int $userId, string $type, string $url): bool
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('url', $url)
            ->where('created_at', '>=', now()->subDays(self::QUIET_DAYS))
            ->exists();
    }

    /** পেরিয়ে গেলে ঋণাত্মক — বার্তাটাই ঠিক করে কোন কথাটা লেখা হবে */
    private function daysLeft(?Carbon $date): int
    {
        return $date === null ? 0 : (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
    }

    private function balanceOf(HandLoanAccount $account): string
    {
        return (string) app(HandLoanService::class)->balanceOf($account);
    }
}
