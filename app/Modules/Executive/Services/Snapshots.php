<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Services\DataScope;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * রাতের হিসাব — লেখা, তিন বছর রাখা, আর পড়া।
 *
 * ── ⓘ কার চোখে লেখা হয় ─────────────────────────────────────────────────
 * রাতে কেউ বসে নেই, অথচ মডিউলের সংখ্যা মানুষের সীমা পড়ে ([[DataScope]]) আর খরচের সংখ্যা চাবি
 * চায় ([[FieldSecurity]])। ⭐ তাই প্রতিটা কোম্পানিতে তার super_admin-এর চোখে — অর্থাৎ গোটা কোম্পানি,
 * সব শাখা, কোনো ঘর ঢাকা নয়। super_admin না থাকলে কোম্পানিটা বাদ, আর আদেশটা সেটা বলে দেয়।
 *
 * ── ⛔ পড়ার সময় সীমা ফেরে ─────────────────────────────────────────────
 * লেখা হয় গোটা কোম্পানির, কিন্তু পড়েন একজন মানুষ। তিনি কেবল নিজের কোম্পানি আর নিজের শাখার সারি
 * পান ([[CompanyLens]])। ⚠️ শাখায় বাঁধা কারও জন্য গোটা কোম্পানির সারি দেখানো হয় না — ওতে অন্য
 * শাখার যোগফল আছে।
 */
final class Snapshots
{
    public const TABLE = 'executive_snapshots';

    /** কত বছর রাখা — মালিকের উত্তর, প্রশ্ন ৩ */
    public const KEEP_YEARS = 3;

    /** গোটা কোম্পানির সারির `place` */
    public const WHOLE = 0;

    public function __construct(
        private readonly CompanyLens $lens,
        private readonly Figures $figures,
    ) {}

    /**
     * আজ রাতের হিসাব — প্রতিটা চালু কোম্পানির প্রতিটা চালু শাখা আর গোটা কোম্পানি।
     *
     * ⓘ দুইবার চালালে দ্বিতীয়বার একই সারিগুলো নতুন করে লেখে, নতুন সারি বসায় না।
     *
     * @return array{rows: int, skipped: list<string>}
     */
    public function take(): array
    {
        $day = Carbon::today()->toDateString();
        $monthStart = Carbon::today()->startOfMonth()->toDateString();
        $rows = 0;
        $skipped = [];

        foreach (Company::query()->where('is_active', true)->orderBy('id')->get() as $company) {
            $viewer = $this->viewerFor((int) $company->id);

            if ($viewer === null) {
                $skipped[] = (string) $company->code;

                continue;
            }

            $places = [null, ...array_column($this->lens->branches($viewer, (int) $company->id), 'id')];

            foreach ($places as $branchId) {
                $values = $this->lens->within($viewer, (int) $company->id, $branchId, fn () => [
                    Figures::SALES => $this->figures->value($viewer, Figures::SALES, $day, $day),
                    Figures::COLLECTIONS => $this->figures->value($viewer, Figures::COLLECTIONS, $day, $day),
                    Figures::RECEIVABLE => $this->figures->value($viewer, Figures::RECEIVABLE, $day, $day),
                    Figures::PAYABLE => $this->figures->value($viewer, Figures::PAYABLE, $day, $day),
                    Figures::FUND => $this->figures->value($viewer, Figures::FUND, $day, $day),
                    Figures::STOCK => $this->figures->value($viewer, Figures::STOCK, $day, $day),
                    Figures::PROFIT => $this->figures->value($viewer, Figures::PROFIT, $monthStart, $day),
                    // ⓘ অনুমোদনের সারিতে শাখা নেই — কেবল গোটা কোম্পানির সারিতে
                    Figures::SIGNATURES => $branchId === null ? $this->figures->value($viewer, Figures::SIGNATURES, $day, $day) : null,
                ]);

                // ⓘ বাইরের কী কেবল প্রথম লেখায় বসে — দ্বিতীয়বার চালালে বদলায় না (হালনাগাদের তালিকায় নেই)
                DB::table(self::TABLE)->upsert([[
                    'public_id' => (string) Str::uuid7(),
                    'company_id' => (int) $company->id,
                    'branch_id' => $branchId,
                    'place' => $branchId ?? self::WHOLE,
                    'taken_on' => $day,
                    ...$values,
                    Figures::SIGNATURES => $values[Figures::SIGNATURES] === null ? null : (int) $values[Figures::SIGNATURES],
                    'taken_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]], ['company_id', 'place', 'taken_on'], [...Figures::KEYS, 'branch_id', 'taken_at', 'updated_at']);

                $rows++;
            }
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * তিন বছরের পুরনো সারি সরানো — কোম্পানি ধরে ধরে।
     */
    public function prune(): int
    {
        $before = Carbon::today()->subYears(self::KEEP_YEARS)->toDateString();
        $gone = 0;

        foreach (Company::query()->orderBy('id')->pluck('id') as $companyId) {
            $gone += DB::table(self::TABLE)
                ->where('company_id', (int) $companyId)
                ->where('taken_on', '<', $before)
                ->delete();
        }

        return $gone;
    }

    /**
     * এক দিনের হিসাব, এই মানুষটার চোখে — যে কোম্পানি ও শাখা তিনি দেখতে পান, কেবল সেগুলো।
     *
     * @param  list<array{id: int, name: string, only?: ?int}>  $companies
     * @return list<array{company_id: int, company_name: string, branch_id: ?int, name: string, values: array<string, ?string>}>
     */
    public function on(User $user, array $companies, string $date): array
    {
        $out = [];

        foreach ($companies as $company) {
            $branches = $this->lens->branches($user, $company['id']);

            if (($company['only'] ?? null) !== null) {
                $branches = array_values(array_filter($branches, fn (array $b) => $b['id'] === $company['only']));
            }

            $rows = DB::table(self::TABLE)
                ->where('company_id', $company['id'])
                ->where('taken_on', $date)
                ->get()
                ->keyBy('place');

            foreach ($branches as $branch) {
                if (isset($rows[$branch['id']])) {
                    $out[] = ['company_id' => $company['id'], 'company_name' => $company['name'], 'branch_id' => $branch['id'],
                        'name' => $branch['name'], 'values' => $this->values($rows[$branch['id']])];
                }
            }

            if ($this->seesWholeCompany($user, $company) && isset($rows[self::WHOLE])) {
                $out[] = ['company_id' => $company['id'], 'company_name' => $company['name'], 'branch_id' => null,
                    'name' => __('executive::today.company_total', ['company' => $company['name']]), 'values' => $this->values($rows[self::WHOLE])];
            }
        }

        return $out;
    }

    /**
     * একটা সংখ্যার ধারা — রাতের হিসাব থেকে, দিনে দিনে, গোটা কোম্পানির সারিগুলোর যোগ।
     *
     * @param  list<array{id: int, name: string, only?: ?int}>  $companies
     * @return array<string, string> তারিখ => যোগ
     */
    public function series(User $user, array $companies, string $key, string $from, string $to): array
    {
        $out = [];

        foreach ($companies as $company) {
            $place = ($company['only'] ?? null) ?? ($this->seesWholeCompany($user, $company) ? self::WHOLE : null);

            if ($place === null) {
                continue;
            }

            foreach (DB::table(self::TABLE)
                ->where('company_id', $company['id'])
                ->where('place', $place)
                ->whereBetween('taken_on', [$from, $to])
                ->whereNotNull($key)
                ->orderBy('taken_on')
                ->get(['taken_on', $key]) as $row) {
                $day = Carbon::parse($row->taken_on)->toDateString();
                $out[$day] = bcadd($out[$day] ?? '0', (string) $row->{$key}, 4);
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * এক জায়গার এক সংখ্যা, এক দিনে — তুলনার পাতার জের-এর "তখন"। না থাকলে `null`।
     */
    public function valueOn(User $user, int $companyId, ?int $branchId, string $key, string $date): ?string
    {
        if ($branchId === null && ! $this->seesWholeCompany($user, ['id' => $companyId])) {
            return null;
        }

        $value = DB::table(self::TABLE)
            ->where('company_id', $companyId)
            ->where('place', $branchId ?? self::WHOLE)
            ->where('taken_on', $date)
            ->value($key);

        return $value === null ? null : bcadd((string) $value, '0', 4);
    }

    /**
     * গোটা কোম্পানির সারি কেবল তাঁর জন্য, যাঁর ঐ কোম্পানিতে শাখার সীমা নেই।
     *
     * @param  array{id: int}  $company
     */
    private function seesWholeCompany(User $user, array $company): bool
    {
        return CompanyContext::forCompany($company['id'], fn () => app(DataScope::class)->idsFor($user, UserDataScope::BRANCH)) === null;
    }

    /**
     * কোম্পানির super_admin — রাতের হিসাব যাঁর চোখে লেখা হয়। চালু, আর ঐ কোম্পানির সদস্য।
     */
    private function viewerFor(int $companyId): ?User
    {
        $id = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('company_user', fn ($j) => $j->on('company_user.user_id', '=', 'model_has_roles.model_id')
                ->where('company_user.company_id', '=', $companyId)
                ->where('company_user.is_active', '=', true))
            ->where('model_has_roles.company_id', $companyId)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.name', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->orderBy('model_has_roles.model_id')
            ->value('model_has_roles.model_id');

        return $id === null ? null : User::query()->whereKey($id)->where('is_active', true)->first();
    }

    /**
     * @return array<string, ?string>
     */
    private function values(object $row): array
    {
        $out = [];

        foreach (Figures::KEYS as $key) {
            $out[$key] = $row->{$key} === null ? null : bcadd((string) $row->{$key}, '0', 4);
        }

        return $out;
    }
}
