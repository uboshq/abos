<?php

declare(strict_types=1);

namespace App\Modules\Sales\Widgets;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Core\Widgets\WidgetSummary;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Sales\Reports\SalespersonReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিক্রয়কর্মীদের আজকের বোর্ড — ফোনের হোম-স্ক্রিন উইজেট `sr_board` (মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * ⭐ নিজে কিছু গোনে না: "কার বিক্রি" আর অঙ্ক ওয়েবের "বিক্রয়কর্মী ধরে বিক্রি" রিপোর্টের ([[SalespersonReports]], দেয়াল চালু
 * থাকলে বিলের দিনে বাঁধা জন, বন্ধ থাকলে বিল যিনি কেটেছেন; নিট = বিক্রি − ফেরত, ভ্যাট বাদ)। আজকের দিন, হেডারের শাখা — রিপোর্টের
 * নিজের ইঞ্জিন ([[ReportEngine::run()]]) যেভাবে ছাঁকে। বাঁধনহীন ডিলারের সারি ("কারও বাঁধা নয়") কোনো মানুষ নয়, তাই ক্রমে নেই।
 *
 * ── ⛔ দুই রূপ ────────────────────────────────────────────────────────────────
 *  - মালিক/ম্যানেজার (দেয়ালের বাইরে **আর** রিপোর্টের চাবি `sales.report` আছে): উপরের ৮ জন, ক্রম, শাখা, অঙ্ক।
 *  - বিক্রয়কর্মী (দেয়ালের ভিতরে, [[DealerScope::walled()]], **বা** `sales.report` নেই — ওয়েবে তিনি কারও সারিই দেখেন না):
 *    কেবল নিজের ক্রম, নিজের অঙ্ক আর মোট কতজন। ⛔ `rows` ঘরটাই নেই; অন্য কারও নাম বা অঙ্ক উত্তরে কখনো যায় না।
 *
 * ⚠️ বিক্রয়কর্মীর ক্রম গোটা দলের মধ্যে, তাই কেবল ক্রম গোনার জন্য রিপোর্টের একই কোয়েরি ডিলারের দেয়াল ছাড়া চলে
 * ([[unwalled()]]) — শাখার সীমা তাঁর নিজেরই থাকে (ইঞ্জিনের ছাঁকা ফিল্টার)। ⓘ সেখান থেকে বাইরে আসে কেবল তিনটা সংখ্যা।
 *
 * ⓘ চাবি `sales.order.view`: রিপোর্টের চাবি `sales.report` মাঠের ছাঁচে (Field Sales) নেই, অথচ বিক্রয়কর্মীকে নিজের ক্রম
 * দেখতে হবে; অর্ডার দেখা মাঠের প্রতিটা ছাঁচের (Field Sales, ASM, RSM, DSM) দৈনিক চাবি। কারা পুরো তালিকা পান, সেটা আলাদা
 * করে `sales.report` আর দেয়াল ঠিক করে — ওয়েবের রিপোর্টের একই দুই তালা।
 */
final class SrBoardSummary implements WidgetSummary
{
    /** বড় মাপের উইজেটে সর্বোচ্চ সারি */
    public const ROWS = 8;

    /** ওয়েবের রিপোর্টের চাবি — পুরো তালিকা কেবল যাঁর আছে */
    public const REPORT_KEY = 'sales.report';

    public function key(): string
    {
        return 'sr_board';
    }

    public function module(): string
    {
        return 'sales';
    }

    public function permission(): ?string
    {
        return 'sales.order.view';
    }

    public function data(User $user): array
    {
        $today = Carbon::today()->toDateString();
        $range = ['from' => $today, 'to' => $today];
        $link = $user->can(self::REPORT_KEY)
            ? route('sales.report.show', ['slug' => 'by-salesperson'] + $range, false)
            : null;

        return $this->as($user, function () use ($user, $range, $link): array {
            $engine = app(ReportEngine::class);

            if ($this->seesOnlySelf($user)) {
                return [
                    'as_of' => now()->toIso8601String(),
                    'self' => $this->self($user, $engine, $range),
                    'link' => $link,
                ];
            }

            // ⓘ একটা বেশি — বাঁধনহীন ডিলারের সারি উপরের ৮-এ পড়লেও ৮ জন মানুষ থাকে
            $result = $engine->run(SalespersonReports::KEY, $range, 1, self::ROWS + 1);
            $people = array_values(array_filter(
                array_map(fn ($row): array => (array) $row, $result->rows),
                fn (array $row): bool => $row['seller_id'] !== null,
            ));
            $people = array_slice($people, 0, self::ROWS);
            $branches = $this->homeBranches(array_map(fn (array $row): int => (int) $row['seller_id'], $people));

            $rows = [];
            foreach ($people as $i => $row) {
                $rows[] = [
                    'rank' => $i + 1,
                    'name' => (string) $row['salesperson'],
                    'branch' => $branches[(int) $row['seller_id']] ?? null,
                    'amount' => self::money($row['net_sales']),
                ];
            }

            return [
                'as_of' => now()->toIso8601String(),
                'branch_label' => $this->branchLabel($user),
                'rows' => $rows,
                'link' => $link,
            ];
        });
    }

    /** ⛔ দেয়ালের ভিতরে, বা ওয়েবের রিপোর্টের চাবি নেই — তাহলে কেবল নিজের */
    private function seesOnlySelf(User $user): bool
    {
        return app(DealerScope::class)->walled($user) || ! $user->can(self::REPORT_KEY);
    }

    /**
     * নিজের ক্রম — রিপোর্টের একই কোয়েরি আর একই ক্রম (নিট বড় থেকে ছোট, তারপর আইডি), তাঁর নিজের শাখার সীমায়।
     *
     * @param  array{from: string, to: string}  $range
     * @return array{rank: ?int, amount: string, of: int}
     */
    private function self(User $user, ReportEngine $engine, array $range): array
    {
        // ⭐ ইঞ্জিনের ছাঁকা ফিল্টার — কোম্পানি, তারিখ, হেডারের শাখা, শাখার সীমা — ওয়েবের অবিকল
        $filters = $engine->run(SalespersonReports::KEY, $range, 1, 1)->filters;
        $query = $engine->get(SalespersonReports::KEY)->query;

        $sellers = $this->unwalled(fn () => ($query)($filters)->get())
            ->filter(fn ($row): bool => $row->seller_id !== null)
            ->values();

        $rank = null;
        $amount = '0.0000';
        foreach ($sellers as $i => $row) {
            if ((int) $row->seller_id === (int) $user->id) {
                $rank = $i + 1;
                $amount = self::money($row->net_sales);
                break;
            }
        }

        return ['rank' => $rank, 'amount' => $amount, 'of' => $sellers->count()];
    }

    /**
     * ⚠️ ডিলারের দেয়াল ছাড়া — কেবল ক্রম গোনার জন্য। দেয়াল দেখে লগইন করা মানুষকে ([[DealerScope::restrict()]]), তাই কোয়েরি
     * বানানোর মুহূর্তটুকু চাবিহীন, সংরক্ষণহীন একজন বসে (চাবি নেই মানে দেয়ালে নেই); তারপরেই আসল মানুষ ফেরেন।
     *
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    private function unwalled(callable $build): mixed
    {
        return $this->as(new User, $build);
    }

    /**
     * যাঁর নামে রিপোর্ট চলে — ইঞ্জিন `auth()->user()` দেখে। দরজায় তিনিই লগইন করা; অন্য পথে ডাকলে এক মুহূর্তের জন্য বসানো।
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function as(User $who, callable $fn): mixed
    {
        $guard = auth()->guard();
        $before = $guard->user();

        if ($before === $who) {
            return $fn();
        }

        $guard->setUser($who);

        try {
            return $fn();
        } finally {
            if ($before !== null) {
                $guard->setUser($before);
            } else {
                $guard->forgetUser();
            }
        }
    }

    /**
     * বিক্রয়কর্মীর নিজের শাখা — এই কোম্পানিতে তাঁর ডিফল্ট শাখা।
     *
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function homeBranches(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $home = DB::table('company_user')
            ->where('company_id', CompanyContext::id())
            ->whereIn('user_id', $userIds)
            ->whereNotNull('default_branch_id')
            ->pluck('default_branch_id', 'user_id');

        $names = Branch::query()->withoutGlobalScopes()->whereIn('id', $home->values()->all())->get()
            ->mapWithKeys(fn (Branch $branch): array => [(int) $branch->id => $branch->name()]);

        return $home->mapWithKeys(fn ($branchId, $userId): array => [(int) $userId => $names[(int) $branchId] ?? null])
            ->filter()
            ->all();
    }

    /** হেডারে একটা শাখা বাছা থাকলে তার নাম, নইলে "সব" — রিপোর্টের একই প্রশ্ন ([[DataScope::viewsOneBranch()]]) */
    private function branchLabel(User $user): string
    {
        $scope = app(DataScope::class);

        if (! $scope->viewsOneBranch($user)) {
            return 'সব';
        }

        $id = $scope->viewBranchIds($user)[0] ?? null;

        return Branch::query()->withoutGlobalScopes()->find($id)?->name() ?? 'সব';
    }

    /** টাকা স্ট্রিং, চার ঘর */
    private static function money(mixed $value): string
    {
        $value = (string) ($value ?? '0');

        return bcadd($value === '' ? '0' : $value, '0', 4);
    }
}
