<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Sales\Models\SalesTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * লক্ষ্যমাত্রা বসানো, আর অর্জনের সাথে মেলানো।
 *
 * ── অর্জন কোথা থেকে গোনা হয়, আর কেন ─────────────────────────────────
 * নিশ্চিত ও সম্পন্ন বিল, লেনদেনের তারিখ ধরে, আর **ভ্যাট বাদ দিয়ে** —
 * ভ্যাট সরকারের টাকা, কারও বিক্রয় নয়। কে বিক্রি করলেন তা আসে বিলের
 * `created_by` থেকে, কারণ ওটাই একমাত্র জায়গা যেখানে লেখা থাকে কাগজটা
 * কে কেটেছিলেন।
 *
 * ── কেন খসড়া বিল গোনা হয় না ────────────────────────────────────────
 * খসড়া মানে বিক্রিটা এখনো হয়নি — টাকা কেউ দেয়নি, খাতায় কিছু বসেনি।
 * গুনলে মাসের শেষ দিনে কয়েকটা খসড়া বিল লিখে টার্গেট পূরণ দেখানো যেত,
 * আর সেটাই সবচেয়ে সহজ ফাঁকি।
 */
final class SalesTargetService
{
    /**
     * একটা মাসের টার্গেটগুলো বসানো — একবারে সবার।
     *
     * ── কেন শূন্য মানে "টার্গেট নেই", "শূন্য টাকার টার্গেট" নয় ──────
     * পর্দায় প্রতিটা মানুষের একটা করে ঘর থাকে, আর বেশিরভাগ ঘর খালি
     * থাকে। খালি ঘরকে শূন্য টার্গেট ধরলে প্রত্যেকের অর্জন সাথে সাথেই
     * অসীম শতাংশ দেখাত।
     *
     * @param  array<int, string>  $amounts  user_id => টাকা
     */
    public function setForMonth(Carbon|string $month, array $amounts): int
    {
        $first = SalesTarget::monthOf($month);

        return DB::transaction(function () use ($first, $amounts) {
            $saved = 0;

            foreach ($amounts as $userId => $amount) {
                $amount = trim((string) $amount);

                if ($amount === '' || bccomp($amount, '0', 4) <= 0) {
                    // খালি বা শূন্য মানে টার্গেট তুলে নেওয়া
                    SalesTarget::query()
                        ->where('user_id', (int) $userId)
                        ->whereDate('month', $first)
                        ->delete();

                    continue;
                }

                SalesTarget::query()->updateOrCreate(
                    [
                        'company_id' => CompanyContext::id(),
                        'user_id' => (int) $userId,
                        'month' => $first,
                    ],
                    [
                        'branch_id' => CompanyContext::branchId(),
                        'amount' => $amount,
                        'created_by' => auth()->id(),
                    ],
                );

                $saved++;
            }

            return $saved;
        });
    }

    /**
     * এক মাসের ছবি — কার টার্গেট কত, অর্জন কত, শতাংশ কত।
     *
     * ── কেন যাঁদের টার্গেট নেই তাঁরাও তালিকায় ───────────────────────
     * টার্গেট না বসানো মানে ওই মাসে তাঁর কাজ মাপা হচ্ছে না — কিন্তু
     * তিনি বিক্রি করেছেন কি না সেটা জানা দরকার। বাদ দিলে মালিক দেখতেন
     * না যে একজন সারা মাস বিক্রি করেছেন অথচ কেউ তাঁকে কোনো টার্গেটই
     * দেয়নি।
     *
     * @return list<array{user: User, target: ?string, achieved: string, percent: ?string}>
     */
    public function scoreboard(Carbon|string $month): array
    {
        $first = Carbon::parse(SalesTarget::monthOf($month));
        $last = $first->copy()->endOfMonth();

        $targets = SalesTarget::query()->forMonth($first)->get()->keyBy('user_id');
        $achieved = $this->achievedByUser($first, $last);

        $rows = [];

        foreach ($this->sellers() as $user) {
            $target = $targets->get($user->id)?->amount;
            $done = (string) ($achieved[$user->id] ?? '0');

            $rows[] = [
                'user' => $user,
                'target' => $target === null ? null : (string) $target,
                'achieved' => $done,
                'percent' => $this->percent($done, $target === null ? null : (string) $target),
            ];
        }

        return $rows;
    }

    /**
     * কার নামে কত বিক্রয় — এক কোয়েরিতে।
     *
     * @return array<int, string>
     */
    public function achievedByUser(Carbon $from, Carbon $to): array
    {
        /*
         * `selectRaw` + `get`, `pluck(DB::raw(...))` নয়।
         *
         * pluck কাঁচা এক্সপ্রেশনকে কলামের **নাম** ধরে নেয়, তাই
         * "SUM(il.amount - il.tax)" খুঁজতে গিয়ে `$row->{'tax)'}` পড়ার
         * চেষ্টা করত আর পর্দাটা ৫০০ দিত। ভুলটা লিখতে সহজ, কারণ কোডটা
         * পড়তে ঠিকই লাগে।
         */
        $rows = DB::table('sal_invoices as i')
            ->join('sal_invoice_lines as il', 'il.sales_invoice_id', '=', 'i.id')
            ->where('i.company_id', CompanyContext::id())
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->whereBetween('i.trx_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('i.created_by')
            ->groupBy('i.created_by')

            // ভ্যাট বাদ — ওটা সরকারের টাকা, কারও বিক্রয় নয়
            ->selectRaw('i.created_by as seller_id, SUM(il.amount - il.tax) as achieved')
            ->get();

        $byUser = [];

        foreach ($rows as $row) {
            $byUser[(int) $row->seller_id] = (string) $row->achieved;
        }

        return $byUser;
    }

    /**
     * যাঁদের টার্গেট থাকতে পারে।
     *
     * ── কেন অনুমতি ধরে, রোলের নাম ধরে নয় ────────────────────────────
     * রোল এখন কোম্পানির নিজের বানানো সারি — কেউ "salesman" নাম না দিয়ে
     * "মাঠকর্মী" বানাতে পারেন। নাম ধরে খুঁজলে ওই ডিপোতে তালিকাটা খালি
     * আসত। প্রশ্নটা আসলে "কে বিল কাটতে পারেন", আর ওটার উত্তর অনুমতিতে।
     *
     * @return Collection<int, User>
     */
    public function sellers()
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->can('sales.invoice.create'))
            ->values();
    }

    /**
     * অর্জনের শতাংশ — টার্গেট না থাকলে কিছুই নয়।
     *
     * শূন্য দিয়ে ভাগ করলে PHP চুপ করে থাকে না, কিন্তু bcdiv-ও উত্তর
     * দেয় না; আর "টার্গেট নেই" আর "০% হয়েছে" দুইটা আলাদা কথা।
     */
    public function percent(string $achieved, ?string $target): ?string
    {
        if ($target === null || bccomp($target, '0', 4) <= 0) {
            return null;
        }

        return bcdiv(bcmul($achieved, '100', 6), $target, 1);
    }

    /**
     * মাসটা পড়া — আজে-বাজে লেখা এলে চলতি মাস।
     *
     * ভাঙার কোনো কারণ নেই: পুরনো বুকমার্ক বা হাতে বদলানো ঠিকানার জন্য
     * একটা পর্দা ৫০০ দেখানো অতিরিক্ত।
     */
    public function readMonth(?string $raw): Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return Carbon::today()->startOfMonth();
        }

        try {
            return Carbon::parse($raw)->startOfMonth();
        } catch (\Throwable) {
            return Carbon::today()->startOfMonth();
        }
    }

    /** টার্গেট বসানোর আগে মাসটা খোলা অর্থবছরে পড়ে কি না। */
    public function assertMonthIsSane(Carbon $month): void
    {
        if ($month->greaterThan(Carbon::today()->startOfMonth()->addYears(2))) {
            throw ValidationException::withMessages([
                'month' => __('sales::validation.target_month_too_far'),
            ]);
        }
    }
}
