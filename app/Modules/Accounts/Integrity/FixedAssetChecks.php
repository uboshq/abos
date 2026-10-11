<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Integrity;

use App\Core\Contracts\ChecksItsOwnBooks;
use App\Core\Integrity\IntegrityCheck;
use App\Core\Integrity\IntegrityFinding;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Reports\FixedAssetReports;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ সম্পদের খাতা আর হিসাবের খাতা এক কথা বলে — স্থায়ী সম্পদ ধাপ ৫ (মালিক, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ নিবন্ধনে আজ খাতায় থাকা সম্পদের দামের যোগফল = সম্পদের খাতের জের; সঞ্চিত ক্ষয়ের যোগফল = সঞ্চিত ক্ষয়ের খাতের জমা জের।
 * খাত ধরে মেলানো হয় — এক খাতে কয়েকটা শ্রেণি থাকতে পারে। ⚠️ না মিললে কারণ সাধারণত তিনটার একটা: হাতে লেখা জাবেদা
 * সম্পদের খাতে, "আগেই খাতায় আছে" বলে তোলা সম্পদ যার দাম খাতায় কখনো বসেনি, বা সম্পদের সারি হাতে বদলানো।
 */
final class FixedAssetChecks implements ChecksItsOwnBooks
{
    public static function checks(): array
    {
        return [self::registerAgreesWithTheLedger()];
    }

    public static function registerAgreesWithTheLedger(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'accounts.asset_register_vs_ledger',
            label: __('accounts::integrity.asset_register'),
            question: __('accounts::integrity.asset_register_q'),
            whenBroken: __('accounts::integrity.asset_register_broken'),
            permission: 'accounts.report',
            run: function (): array {
                $today = now()->toDateString();
                $register = DB::query()->fromSub(FixedAssetReports::asOf(['company_id' => CompanyContext::id()], $today), 'x')
                    ->join('acc_fixed_assets as fa', 'fa.id', '=', 'x.id')
                    ->where('fa.company_id', CompanyContext::id())
                    ->whereRaw('x.in_books = 1')
                    ->get(['fa.asset_account_id', 'fa.accumulated_account_id', 'x.cost_on', 'x.accumulated_on']);

                $want = [];

                foreach ($register as $row) {
                    $want[(int) $row->asset_account_id]['cost'] = bcadd($want[(int) $row->asset_account_id]['cost'] ?? '0', (string) $row->cost_on, 4);
                    $want[(int) $row->accumulated_account_id]['acc'] = bcadd($want[(int) $row->accumulated_account_id]['acc'] ?? '0', (string) $row->accumulated_on, 4);
                }

                // ⓘ সম্পদের খাত আর সঞ্চিত ক্ষয়ের খাত — নিবন্ধনে না থাকলেও যে খাতগুলো কোনো সম্পদ বা শ্রেণির নামে বাঁধা
                $accounts = collect(array_keys($want))
                    ->merge(FixedAsset::acrossBranches()->withTrashed()->pluck('asset_account_id'))
                    ->merge(FixedAsset::acrossBranches()->withTrashed()->pluck('accumulated_account_id'))
                    ->filter()->map(fn ($id) => (int) $id)->unique()->values();

                $ledger = DB::table('ledger_entries as le')
                    ->join('accounts as a', 'a.id', '=', 'le.account_id')
                    ->where('le.company_id', CompanyContext::id())
                    ->whereIn('le.account_id', $accounts->all())
                    ->where('le.trx_date', '<=', $today)
                    ->groupBy('le.account_id', 'a.code', 'a.name_en')
                    ->select(['le.account_id', 'a.code', 'a.name_en'])
                    ->selectRaw('COALESCE(SUM(le.debit), 0) - COALESCE(SUM(le.credit), 0) AS net')
                    ->get()->keyBy('account_id');

                $names = DB::table('accounts')->where('company_id', CompanyContext::id())
                    ->whereIn('id', $accounts->all())->get(['id', 'code', 'name_en'])->keyBy('id');

                $out = [];

                foreach ($accounts as $id) {
                    $net = Money::of((string) ($ledger[$id]->net ?? '0'));
                    $cost = $want[$id]['cost'] ?? '0';
                    $acc = $want[$id]['acc'] ?? '0';

                    // ⓘ এক খাতে দাম (ডেবিট) আর সঞ্চিত ক্ষয় (জমা) দুইটাই থাকতে পারে — নিবন্ধনের নিট অঙ্কই খাতের জের
                    $expected = bcsub($cost, $acc, 4);

                    if (bccomp($expected, $net, 4) === 0) {
                        continue;
                    }

                    $name = $names[$id] ?? null;

                    $out[] = new IntegrityFinding(
                        what: trim(($name->code ?? '').' '.($name->name_en ?? '#'.$id)),
                        detail: __('accounts::integrity.asset_register_detail', [
                            'register' => Money::format($expected, 2),
                            'ledger' => Money::format($net, 2),
                            'diff' => Money::format(bcsub($net, $expected, 4), 2),
                        ]),
                    );
                }

                return $out;
            },
        );
    }
}
