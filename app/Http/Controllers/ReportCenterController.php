<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Engines\Map\MapEngine;
use App\Core\Engines\Report\ReportCenterPlan;
use App\Core\Services\MenuBuilder;
use App\Core\Support\ScreenAddress;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * রিপোর্ট সেন্টার — সব মডিউলের রিপোর্ট এক পাতায়, মডিউল ধরে ভাগ (রিপোর্ট সেন্টার ধাপ ১, ২ অক্টোবর ২০২৬)।
 *
 * ── ⭐ মালিকের নীতি ──────────────────────────────────────────────────
 * *"যা তৈরি, কেবল সেটাই দেখায়"* — মেনুতে "শীঘ্রই আসছে" জাতীয় খালি সারি নয়। আর *"ফিন্যান্স মানচিত্রের মতো সব
 * জায়গায়"* — তাই পাতাটা একটা মানচিত্র ([[MapEngine]]): প্রথম মানচিত্র রিপোর্ট সেন্টারই।
 *
 * ── ⛔ তালিকা কোথা থেকে ──────────────────────────────────────────────
 * প্রতিটা মডিউলের মেনুর "রিপোর্ট" দল — [[MenuBuilder::forUser()]] যেভাবে সাইডবারে আঁকে, হুবহু সেটাই: অনুমতি, কোম্পানি ও
 * শাখার মডিউল-সুইচ, সারির সুইচ সব আগেই ছাঁকা। ⓘ দ্বিতীয় একটা তালিকা লিখলে একদিন সাইডবার আর রিপোর্ট সেন্টার দুই কথা
 * বলত — আর বেশি দেখানোটা ফাঁস, কম দেখানোটা হারানো রিপোর্ট।
 * ⓘ সাথে মালিকের তালিকার যেগুলো এখনো বাকি ([[ReportCenterPlan]]) — সেগুলো কেবল মালিক/অ্যাডমিন দেখেন, আর বানানো হলে
 * নিজে লিংক হয়।
 *
 * ── ⭐ প্রিয় ─────────────────────────────────────────────────────────
 * রিপোর্টের পাতায় "এই দৃশ্যটা রেখে দিন" ([[SavedView]]) — নিজের, এই কোম্পানির। এখানে কেবল সেগুলো যাদের রিপোর্ট এখনো
 * এই মানুষের তালিকায় আছে: চাবি কেড়ে নিলে প্রিয়টাও সরে, পুরনো লিংক দরজা হয় না। ⛔ খুললে ছাঁকনিগুলো রিপোর্টের নিজের
 * পথেই যায় ([[ReportFilters::resolve()]]) — প্রিয়তে অন্যের নম্বর থাকলে রিপোর্টই ফেরে।
 *
 * ⓘ রুটে `can:` নেই, ইচ্ছাকৃত: প্রতিটা সারি তার নিজের চাবিতে ছাঁকা; চাবিহীন মানুষ খালি পাতা পান, কিছু নয়।
 * ⓘ `show`, `index` নয়: এটা কোনো টেবিলের তালিকা নয় — সারিগুলো মডিউলের ঘোষণা, ডাটাবেজের নয় (প্রিয় ছাড়া, আর সেগুলো
 * একজনের নিজের হাতে রাখা কয়েকটা)।
 */
final class ReportCenterController extends Controller
{
    /** মেনুর কোন দলে রিপোর্ট থাকে — [[ModuleDefinition::MENU_GROUPS]]-এর একটা */
    private const GROUP = 'reports';

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly MapEngine $maps,
    ) {}

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $menu = $this->menu->forUser($user);

        [$sections, $reports] = $this->sections($menu);

        return view('reports.center', [
            'menu' => $menu,
            'map' => $this->maps->draw($sections, $user),
            'favourites' => $this->favourites($user, $reports),
            'reconciledOn' => ReportCenterPlan::RECONCILED_ON,
        ]);
    }

    /**
     * মডিউল ধরে ভাগ — তৈরি রিপোর্ট মেনু থেকে, বাকিগুলো মালিকের তালিকা থেকে।
     *
     * @param  list<array<string, mixed>>  $menu
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>} ভাগগুলো, আর লিংক → রিপোর্টের নাম
     */
    private function sections(array $menu): array
    {
        $plan = [];

        foreach (ReportCenterPlan::pending() as $line) {
            $plan[$line['module'] ?? ''][] = $line;
        }

        $sections = [];
        $reports = [];

        foreach ($menu as $module) {
            $items = [];

            foreach ($module['groups'] as $group => $rows) {
                // ⓘ অতিথি মডিউলের দলের চাবি `অতিথি:দল` ([[MenuBuilder::settleGuestsIntoTheirHosts()]])
                if ($group !== self::GROUP && ! str_ends_with((string) $group, ':'.self::GROUP)) {
                    continue;
                }

                foreach ($rows as $row) {
                    // ⛔ "শীঘ্রই" সারি নয় — কেবল যা খোলে
                    if (($row['url'] ?? null) === null || ($row['planned'] ?? false)) {
                        continue;
                    }

                    $items[] = ['label' => $row['label'], 'url' => $row['url']];
                    $reports[$row['url']] = $row['label'];
                }
            }

            foreach ($module['codes'] ?? [$module['code']] as $code) {
                foreach ($plan[$code] ?? [] as $line) {
                    $items = $this->withPlanLine($items, $line, $reports);
                }
            }

            if ($items !== []) {
                $sections[] = ['title' => $module['label'], 'code' => $module['code'], 'items' => $items];
            }
        }

        $across = [];

        foreach ($plan[''] ?? [] as $line) {
            $across = $this->withPlanLine($across, $line, $reports);
        }

        if ($across !== []) {
            $sections[] = ['title' => __('report_center.across'), 'code' => 'across', 'items' => $across];
        }

        return [$sections, $reports];
    }

    /**
     * মালিকের তালিকার একটা লাইন — ⓘ বানানো হয়ে মেনুতে উঠে গেলে আর দ্বিতীয়বার নয়: সারিটা তখন উপরেই আছে।
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array{key: string, module: ?string, route: string, step: int}  $line
     * @param  array<string, string>  $reports
     * @return list<array<string, mixed>>
     */
    private function withPlanLine(array $items, array $line, array $reports): array
    {
        $url = $this->maps->urlFor($line['route']);

        if ($url !== null && isset($reports[$url])) {
            return $items;
        }

        $items[] = [
            'label' => __('report_center.plan.'.$line['key']),
            'route' => $line['route'],
            'note' => __('report_center.steps.s'.$line['step']),
        ];

        return $items;
    }

    /**
     * নিজের প্রিয় রিপোর্ট — এই কোম্পানির ([[BelongsToCompany]]), আর কেবল যাদের রিপোর্ট এখনো তালিকায় আছে।
     *
     * @param  array<string, string>  $reports  লিংক → রিপোর্টের নাম
     * @return list<array{name: string, url: string, report: string}>
     */
    private function favourites(User $user, array $reports): array
    {
        $out = [];

        $views = SavedView::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->limit(200)
            ->get();

        foreach ($views as $view) {
            $base = ScreenAddress::url((string) $view->screen);

            if ($base === null || ! isset($reports[$base])) {
                continue;
            }

            $out[] = ['name' => (string) $view->name, 'url' => $view->url(), 'report' => $reports[$base]];
        }

        return $out;
    }
}
