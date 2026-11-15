<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Support\PartyStatement;
use App\Modules\Accounts\Support\PartyStatementPages;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * ⭐ হিসাব বিবরণীর PDF — যেকোনো পক্ষ, যেকোনো সময়, সব পাতা (মালিক, ১৫ নভেম্বর ২০২৬: "ওকে, তোমার প্ল্যান মতো করো")।
 *
 * ── কে খুলতে পারে ─────────────────────────────────────────────────────────────────
 * হিসাবের রিপোর্টের চাবি (`accounts.report`), অথবা সেই পক্ষকে দেখার অনুমতি (গ্রাহক বা সরবরাহকারীর নিজের নীতি) — দলের পাতার
 * ছাপার বোতাম এখানেই আসে, তাই যিনি পাতায় খাতা দেখেন তিনি ছাপতেও পারেন। ⛔ অন্য কোম্পানির পক্ষ = ৪০৪ (খোঁজাই হয় নিজের কোম্পানিতে)।
 *
 * ── ঠিকানা ─────────────────────────────────────────────────────────────────────────
 * `?from=&to=` — না দিলে শুরু থেকে আজ পর্যন্ত। উল্টো দিলে সোজা করে পড়া। ⓘ শাখা হেডারে বাছা শাখা ([[ViewedBranch]]), পর্দার খাতার মতো।
 */
final class PartyStatementController extends Controller
{
    /** ঠিকানার ধরন — পক্ষ নয়, হিসাবের খাত (অ্যাকাউন্ট লেজার) */
    public const ACCOUNT = 'account';

    public function __invoke(Request $request, string $type, int $id, PartyStatement $statements, PartyStatementPages $paging,
        PrintEngine $print, PartyRegistry $parties, SettingsService $settings): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);
        [$from, $to] = $this->dates($request);

        if ($type === self::ACCOUNT) {
            /*
             * ⭐ অ্যাকাউন্ট লেজার — যেকোনো হিসাবের খাত (সাধারণ খতিয়ান), একই ছাঁচে (মালিক, ১৫ নভেম্বর ২০২৬)।
             * ⓘ খাতের খতিয়ান হিসাবের রিপোর্টের জিনিস — চাবি `accounts.report`, সাধারণ খতিয়ানের রিপোর্টের একই দরজা।
             */
            $account = Account::query()->whereKey($id)->first();
            abort_if($account === null, 404);
            abort_unless($user->can('accounts.report'), 403);

            $statement = $statements->buildAccount($account, $from, $to);
            $titleKey = self::ACCOUNT;
            $facts = ['name' => $account->name(), 'code' => (string) $account->code];
            // ⓘ খাতের নামের নিচে: ধরন ও কোড, তারপর কোন দলের — তিন লাইনের একই ছাঁচ
            $factLines = array_values(array_filter([
                trim(__('accounts::type.'.$account->type).' · '.__('accounts::statement.fact.code').' '.$account->code),
                $account->parent !== null ? __('accounts::statement.fact.group').': '.$account->parent->name() : '',
            ], fn ($line) => trim((string) $line) !== ''));
        } else {
            abort_unless($parties->knows($type), 404);
            $row = $parties->find($type, $id);
            abort_if($row === null, 404);
            abort_unless($user->can('accounts.report') || Gate::forUser($user)->allows('view', $row), 403);

            $statement = $statements->build($type, $id, $from, $to);
            /*
             * ⭐ শিরোনাম দলের ধরন দেখে — কাস্টমার লেজার, সাপ্লায়ার লেজার, সার্ভিস প্রোভাইডার লেজার, পার্টি লেজার, এমপ্লয়ি লেজার;
             *   নিচে ছোট করে ইংরেজি নাম (মালিক, ১৫ নভেম্বর ২০২৬: "মানুষ যেটা সহজে বোঝে")। ⓘ পরিবেশক গ্রাহকই — কাস্টমার লেজার।
             */
            $titleKey = match (true) {
                $type === 'supplier' && method_exists($row, 'isServiceProvider') && $row->isServiceProvider() => 'service_provider',
                in_array($type, ['customer', 'supplier', 'person', 'employee'], true) => $type,
                default => 'party',
            };
            $facts = $parties->paperFacts($type, $id) ?? [];
            $factLines = $this->facts($facts);
        }

        abort_if($statement['truncated'], 422, (string) __('accounts::statement.too_many', ['n' => number_format(PartyStatement::MAX_ROWS)]));

        $signatures = $settings->enabled('accounts.print_signature_lines');
        $docTitle = (string) __('accounts::statement.title.'.$titleKey);

        $data = [
            'title' => $docTitle.' — '.($facts['name'] ?? ''),
            'docTitle' => $docTitle,
            'docTitleEn' => (string) __('accounts::statement.title_en.'.$titleKey),
            'statement' => $statement,
            'party' => [
                'name' => (string) ($facts['name'] ?? ''),
                'code' => (string) ($facts['code'] ?? ''),
                'facts' => $factLines,
            ],
            'from' => $from,
            'to' => $to,
            'period' => $from !== null
                ? __('accounts::statement.from_to', ['from' => Carbon::parse($from)->format('d-m-Y'), 'to' => Carbon::parse($to)->format('d-m-Y')])
                : __('accounts::statement.since_start', ['to' => Carbon::parse($to)->format('d-m-Y')]),
            'branch' => ($branchId = ViewedBranch::one($user)) !== null
                ? (string) Branch::query()->whereKey($branchId)->first()?->name()
                : (string) __('accounts::statement.all_branches'),
            'printedAt' => now()->format('d-m-Y, h:i A'),
            'printedBy' => (string) $user->name,
            'signatures' => $signatures,
            // ⓘ মূল ছাপা সাদা-কালো; রঙিন কেবল হিসাবের সেটিংসে চালু থাকলে (মালিক, ১৫ নভেম্বর ২০২৬)
            'colour' => $settings->enabled('accounts.statement_colour'),
        ];

        /*
         * ⓘ পাতা ভাগ মাপা হিসাবে ([[PartyStatementPages]]); mPDF ভাগের চেয়ে বেশি পাতা বানালে (মাপে ভুল — কোনো সারি বেশি লাইনে
         * ভাঙল) প্রতি পাতায় একটু কম জায়গা ধরে আবার — জেরের সারি যেন ঠিক পাতার শেষেই থাকে।
         */
        $result = null;

        foreach ([0.0, 6.0, 14.0] as $shrink) {
            $pages = $paging->split($statement['rows'], $signatures, $shrink);
            $spare = $paging->spare(end($pages) ?: [], count($pages) === 1, $signatures, $shrink);
            $result = $print->renderDocument('accounts::print.party-statement', [...$data, 'pages' => $pages, 'signGap' => $spare], PaperSize::A4);

            if ($result['pages'] <= count($pages)) {
                break;
            }
        }

        // ⓘ ফাইলের নাম ইংরেজি শিরোনাম আর কোড — "customer-ledger-cus-0192.pdf"; রাখলে চেনা যায়
        $name = str(__('accounts::statement.title_en.'.$titleKey, [], 'en').' '.((string) ($facts['code'] ?? '') ?: $id))->slug()->value();

        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
            'X-Statement-Pages' => (string) $result['pages'],
        ]);
    }

    /** @return array{0: ?string, 1: string} */
    private function dates(Request $request): array
    {
        $read = function (mixed $value): ?string {
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            try {
                return Carbon::parse(trim($value))->toDateString();
            } catch (Throwable) {
                return null;
            }
        };

        $from = $read($request->query('from'));
        $to = $read($request->query('to')) ?? now()->toDateString();

        // ⓘ উল্টো পরিসর সোজা করে — তারিখের ঘরের একই নিয়ম ([[FiltersByDate::applyDateRange()]])
        if ($from !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * যার খাতা — নামের নিচে ঠিকানা, তারপর ফোন; কেবল এই তিনটা (মালিক, ১৫ নভেম্বর ২০২৬: "কাস্টমারের নাম থাকবে আর নিচে থাকবে
     * কাস্টমারের ঠিকানা … ফোন নাম্বার")। ⓘ খালি লাইন দেখায় না।
     *
     * @param  array<string, string>  $facts
     * @return list<string>
     */
    private function facts(array $facts): array
    {
        $phone = trim((string) ($facts['phone'] ?? ''));

        return array_values(array_filter([
            trim((string) ($facts['address'] ?? '')),
            $phone !== '' ? __('accounts::statement.fact.phone').': '.$phone : '',
        ], fn ($line) => $line !== ''));
    }
}
