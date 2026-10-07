<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ খাত বলে কোন পক্ষ রাখে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১২; সমন্বয়কের সিদ্ধান্ত ৭ অক্টোবর ২০২৬;
 * [[Account::holdsParty()]], [[AnAccountSaysWhichPartiesItHoldsTest]])।
 *
 * ⓘ প্রতিটা কোম্পানিতে দুই উৎস, কেবল যোগ:
 *   · প্রমিত পরিবার ([[StandardChart::PARTY_HOLDERS]]) — পুরো বংশ;
 *   · আজকের খাতা — যে খাতে আজ পক্ষসহ সারি আছে, সেই সারিগুলোর পক্ষ-ধরন।
 * কোন খাত কী পেল লগে। পুরনো সারি ছোঁয়া হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'party_types')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->json('party_types')->nullable()->after('money_kind');
            });
        }

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $given = CompanyContext::forCompany((int) $company->id, fn () => app(StandardChart::class)->markPartyHolders());

            $seen = DB::table('ledger_entries')
                ->where('company_id', $company->id)
                ->whereNotNull('party_type')
                ->where('party_type', '<>', '')
                ->select(['account_id', 'party_type'])
                ->distinct()
                ->get()
                ->groupBy('account_id');

            foreach ($seen as $accountId => $rows) {
                $account = DB::table('accounts')->where('company_id', $company->id)->where('id', $accountId)->first(['id', 'code', 'party_types']);

                if ($account === null) {
                    continue;
                }

                $had = json_decode((string) ($account->party_types ?? '[]'), true) ?: [];
                $merged = array_values(array_unique([...$had, ...$rows->pluck('party_type')->map(fn ($t) => (string) $t)->all()]));
                sort($merged);

                if ($merged !== $had) {
                    DB::table('accounts')->where('id', $account->id)->update(['party_types' => json_encode($merged)]);
                    $given[(string) $account->code] = $merged;
                }
            }

            Log::info('Accounts: which parties each account holds', ['company' => $company->code, 'given' => $given]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounts', 'party_types')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->dropColumn('party_types');
            });
        }
    }
};
