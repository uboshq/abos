<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ প্রতিটা চলমান কোম্পানিতে দুই নতুন খাত — পাওনার সম্ভাব্য ক্ষতির ভাতা (১১১৯) আর পাওনার ক্ষতির খরচ (5228 — 5223 WPPF-এর)
 * (IFRS 9.5.5.15; ERP ১০০%-এর হিসাবের ফাঁক #১৮, ফ্রিজের পরের অংশ; fe, ২০ অক্টোবর ২০২৬)।
 *
 * <p>নতুন কোম্পানি ছক বসানোর সময়েই পায় ([[StandardChart::install()]])। এখানে কেবল এই দুই কোড ([[StandardChart::install()]]-এর
 * `$only`) — কোম্পানি যে খাত নিজে মুছেছে সেটা ফেরে না। ⓘ online: কেবল দুটো সারি যোগ প্রতি কোম্পানিতে, কোনো টেবিল বদলায় না।
 * ফেরত: দুই খাত মোছে, যদি তাতে খাতার কোনো সারি না থাকে (থাকলে রাখে — বসা টাকা মুছে যাবে না)।
 */
return new class extends Migration
{
    private const CODES = StandardChart::ECL_CODES;

    public function up(): void
    {
        $before = CompanyContext::id();

        try {
            foreach (Company::query()->withoutGlobalScopes()->orderBy('id')->pluck('id') as $companyId) {
                CompanyContext::set((int) $companyId, null);

                // ⓘ ছক যার একেবারেই নেই (খালি কোম্পানি), তাকে এখানে নয় — ছক বসানোর দিনে পুরোটাই পাবে
                if (! DB::table('accounts')->where('company_id', $companyId)->exists()) {
                    continue;
                }

                app(StandardChart::class)->install(self::CODES);
            }
        } finally {
            $before === null ? CompanyContext::clear() : CompanyContext::set($before);
        }
    }

    public function down(): void
    {
        // ⛔ MySQL refuses a DELETE on `accounts` with a subquery on `accounts` itself (error 1093 — the "no child" check);
        //   the ids are picked first, then deleted (Mac run of the 1.3.11 candidate, 24 Nov 2026: the rollback stopped here)
        $ids = DB::table('accounts')
            ->whereIn('code', self::CODES)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries')->whereColumn('ledger_entries.account_id', 'accounts.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('accounts as child')->whereColumn('child.parent_id', 'accounts.id'))
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            DB::table('accounts')->whereIn('id', $ids)->delete();
        }
    }
};
