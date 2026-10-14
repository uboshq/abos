<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Modules\Executive\Services\Alerts;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\CreditHeadlines;
use App\Modules\Executive\Services\Figures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ ফোনে মালিকের কেন্দ্র — "আজ" পাতার আটটা সংখ্যা, কোম্পানি × শাখার ছক, সতর্কতা আর সইয়ের অপেক্ষা (মালিক, ১৩ অক্টোবর ২০২৬:
 * "app e baki module & menu")।
 *
 * `GET /api/v1/executive/today?period=today|week|month`
 *
 * ⛔ এখানে কোনো সংখ্যা গোনা হয় না — ওয়েবের [[TodayController::show()]] যে তিন সেবা ডাকে, হুবহু সেগুলোই: [[Board::build()]],
 * [[Alerts::all()]]/[[Alerts::waiting()]], [[CreditHeadlines::for()]]। তাই ফোন আর ওয়েবের ঘর কখনো দুই সংখ্যা বলে না।
 * ⓘ কোম্পানি আর শাখা ঠিক ওয়েব যেমন দেখায় — [[CompanyLens]]: সদস্যপদ আর ঐ কোম্পানিতে `executive.view`, শাখা মানুষটার নাগালে।
 * ⛔ ক্রমিক আইডি কখনো নয় — কোম্পানি আর শাখার `public_id`। ঢাকা ঘর (চাবি নেই) `"hidden"`, শাখায় ভাগ হয় না এমন ঘর `null`।
 */
final class ExecutiveApiController extends Controller implements HasMiddleware
{
    /** ঢাকা ঘর — ফোন "••••" আঁকে; শূন্য নয়, কারণ শূন্য একটা মিথ্যা সংখ্যা */
    public const HIDDEN = 'hidden';

    public function __construct(
        private readonly Board $board,
        private readonly Alerts $alerts,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:executive.view')];
    }

    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = PhoneInput::text($request, 'period', Figures::TODAY);

        $board = $this->board->build($user, $period);
        $companies = array_map(fn (array $c) => ['id' => $c['id'], 'name' => $c['name'], 'only' => $c['only']], $board['companies']);

        $companyIds = Company::query()->withoutGlobalScopes()->whereIn('id', array_column($board['companies'], 'id'))->pluck('public_id', 'id');
        $branchIds = Branch::acrossAllCompanies()->whereIn('id', array_merge([0], ...array_map(
            fn (array $c) => array_column($c['rows'], 'id'), $board['companies'])))->pluck('public_id', 'id');

        $credit = app(CreditHeadlines::class)->for($user, $companies);

        /*
         * ⛔ কোনো কোম্পানিই নেই — "অনুমতি নেই" (••••) নয়, পর্দার নিজের "কোনো কোম্পানি নেই" কথা; ঘরগুলো খালি (null)।
         * আগে খালি যোগফল ঢাকা ঘর হয়ে `"hidden"` যেত (ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬;
         * [[ThePhoneSaysNoCompanyNotNoPermissionTest]])।
         */
        $none = $board['companies'] === [];

        return response()->json([
            'message' => $none ? __('executive::today.no_company') : null,
            'period' => $board['period'],
            'from' => $board['from'],
            'to' => $board['to'],
            'figures' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => __('executive::figure.'.$key),
                'value' => $none ? null : $this->cell($board['total'][$key]),
                'money' => Figures::definition($key)['money'],
                // ⓘ কোনো কোম্পানিতে ঘরটা ঢাকা — যোগটা অসম্পূর্ণ, পর্দা পাশে লেখে
                'partial' => (bool) ($board['partial'][$key] ?? false),
            ], Figures::KEYS),
            'eliminated' => $board['eliminated'] === null ? null : array_map(fn ($v) => (string) $v, $board['eliminated']),
            'companies' => array_map(fn (array $c) => [
                'id' => (string) ($companyIds[$c['id']] ?? ''),
                'name' => (string) $c['name'],
                'narrowed' => (bool) $c['narrowed'],
                'values' => $this->cells($c['values']),
                'rows' => array_map(fn (array $row) => [
                    'id' => (string) ($branchIds[$row['id']] ?? ''),
                    'name' => (string) $row['name'],
                    'values' => $this->cells($row['values']),
                ], $c['rows']),
                'unsplit' => $c['unsplit'] === null ? null : $this->cells($c['unsplit']),
            ], $board['companies']),
            'alerts' => array_map(fn (array $a) => [
                'kind' => $a['kind'],
                'label' => __('executive::alert.'.$a['kind']),
                'total' => (int) $a['total'],
                // ⓘ কোম্পানির ঘর `null` = ঐ কোম্পানিতে দেখার চাবি নেই (শূন্য নয়)
                'companies' => array_map(fn (array $p) => ['name' => (string) $p['name'], 'count' => $p['count']], $a['companies']),
            ], $this->alerts->all($user, $companies)),
            'waiting' => array_map(fn (array $w) => [
                'company' => (string) $w['company_name'],
                'label' => (string) $w['label'],
                'amount' => $w['amount'],
                'requested_at' => $w['requested_at'],
            ], $this->alerts->waiting($user, $companies, 8)),
            'credit' => $credit === null ? null : [
                'totals' => $credit['totals'],
                'partial' => $credit['partial'],
            ],
        ]);
    }

    /**
     * @param  array<string, ?string>  $values
     * @return array<string, ?string>
     */
    private function cells(array $values): array
    {
        $out = [];

        foreach (Figures::KEYS as $key) {
            $out[$key] = $this->cell($values[$key] ?? null);
        }

        return $out;
    }

    private function cell(?string $value): ?string
    {
        return $value === Figures::HIDDEN ? self::HIDDEN : $value;
    }
}
