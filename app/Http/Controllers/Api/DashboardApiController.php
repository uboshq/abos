<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Services\PhoneModules;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RefuseSwitchedOffScreens;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ⭐ ফোনে মডিউলের ড্যাশবোর্ড — মালিক, ৪ অক্টোবর ২০২৬: *"mobile app e inventory desborad … Sales Account er gulo dorkar
 * … egulo nadile bujbo kikore kihocche"*।
 *
 * ── ⭐ ফোনে একটাও সংখ্যা কষা হয় না, এখানেও না ──────────────────────────
 * ওয়েবের ড্যাশবোর্ড যে ইঞ্জিন থেকে আসে ([[DashboardEngine::for()]] → মডিউলের নিজের `dashboard()`), এই দরজা ঠিক সেটাই
 * JSON-এ পাঠায়। ⛔ ফোনের জন্য আলাদা হিসাব লিখলে একদিন ফোনে মজুদের মূল্য একটা আর ওয়েবে আরেকটা দেখাত।
 *
 * ── দরজার ক্রম — ওয়েবের হুবহু ([[ModuleDashboardController::show()]]) ────────────
 *   ৪০৪  এমন মডিউল নেই, বা তার ড্যাশবোর্ড নেই, বা কোম্পানি মডিউলটা বন্ধ রেখেছে ([[RefuseSwitchedOffScreens]] নিজেই)।
 *   ৪০৩  ফোনে মডিউলটা চালু নয় ([[PhoneModules::isOn()]]) — উত্তরের আকার ফোনের বাকি দরজার মতোই।
 *   ৪০৩  মডিউলের নিজের মেনু-সারির চাবি নেই ([[DashboardEngine::permissionFor()]]); সারিটাই না থাকলে বন্ধ।
 * ⓘ ভেতরের সংখ্যার চাবি (যেমন মজুদের মূল্য — `inventory.cost.view`) ইঞ্জিন নিজেই ঢাকে (`••••`), ওয়েবে যেমন।
 * ⓘ শাখার দেয়াল মডিউলের নিজের হিসাবে ([[StockFacts]], [[SalesMetrics]] …) — হেডারে বাছা শাখা ফোনেও একই।
 */
final class DashboardApiController extends Controller
{
    /** একটা তালিকার সর্বোচ্চ সারি — ফোনের পর্দায় পুরো খাতা নামানোর জায়গা নয়; পুরোটা রিপোর্টে */
    private const MAX_ROWS = 20;

    public function __construct(
        private readonly DashboardEngine $engine,
        private readonly PhoneModules $phone,
    ) {}

    /**
     * কোন মডিউলের ড্যাশবোর্ড এই মানুষ খুলতে পারেন — প্রতিটার মাথার সংখ্যাসহ ([[DashboardEngine::overall()]], ওয়েবের হোমের
     * একই তালিকা), আর কেবল ফোনে চালু মডিউলগুলো।
     */
    public function index(Request $request): JsonResponse
    {
        $out = [];

        foreach ($this->engine->overall($request->user()) as $row) {
            if (! $this->phone->isOn($row['module']) || $this->switchedOff($row['module'])) {
                continue;
            }

            $out[] = ['module' => $row['module'], 'name' => $row['name'], 'stat' => $this->stat($row['stat'])];
        }

        return response()->json(['modules' => $out]);
    }

    public function show(Request $request, string $module): JsonResponse
    {
        if (! $this->engine->has($module) || $this->switchedOff($module)) {
            throw new NotFoundHttpException;
        }

        /*
         * ⚠️ `isOn()`, `isReachable()` নয়: পৌঁছানো মানে অন্য চালু মডিউলের তথ্যের জন্য এর ডেটা ফোনে নামে (বিক্রি চালু থাকলে
         * মজুদের দাম); কিন্তু ড্যাশবোর্ড মডিউলের **নিজের পর্দা** — ফোনে মডিউলটা বন্ধ থাকলে পর্দাও বন্ধ ([[ModuleGateView]])।
         */
        if (! $this->phone->isOn($module)) {
            return response()->json(['message' => __('mobile.module_off'), 'reason' => PhoneModules::REASON, 'module' => $module], 403);
        }

        $permission = $this->engine->permissionFor($module);

        abort_if($permission === null, 403);

        $this->authorize($permission);

        $dashboard = $this->engine->for($module, $request->user());

        return response()->json([
            'module' => $module,
            'title' => $dashboard->title,
            'subtitle' => $dashboard->subtitle,
            'stats' => array_map(fn (Stat $s) => $this->stat($s), $dashboard->stats),
            'panels' => array_values(array_filter(array_map(fn ($p) => $this->panel($p), $dashboard->panels))),
            'listings' => array_map(fn (Listing $l) => $this->listing($l), $dashboard->listings),
        ])->header('Cache-Control', 'no-store');
    }

    private function switchedOff(string $module): bool
    {
        return app(RefuseSwitchedOffScreens::class)->refuses('module.dashboard', ['module' => $module]);
    }

    /** @return array<string, mixed> */
    private function stat(Stat $stat): array
    {
        return [
            'label' => $stat->label,
            // ⓘ ঢাকা সংখ্যা ঢাকাই যায় — ফোন `hidden` দেখে "চাবি নেই" বলে, ফাঁকা নয়
            'value' => $stat->value === Stat::HIDDEN ? null : $stat->value,
            'hidden' => $stat->value === Stat::HIDDEN,
            'hint' => $stat->hint,
            'tone' => $stat->tone,
            'previous' => $stat->previous,
            'previousLabel' => $stat->previousLabel,
        ];
    }

    /** @return array<string, mixed>|null */
    private function panel(mixed $panel): ?array
    {
        return match (true) {
            $panel instanceof Series => [
                'kind' => 'series', 'label' => $panel->label, 'chart' => $panel->chart,
                'firstLabel' => $panel->firstLabel, 'secondLabel' => $panel->secondLabel,
                'points' => array_map(fn (array $p) => [
                    'label' => (string) $p['label'], 'first' => (string) $p['first'], 'second' => (string) $p['second'],
                ], $panel->points),
            ],
            $panel instanceof Breakdown => [
                'kind' => 'breakdown', 'label' => $panel->label, 'chart' => $panel->chart, 'hint' => $panel->hint,
                'parts' => array_map(fn (array $p) => [
                    'label' => (string) $p['label'], 'value' => (string) $p['value'], 'tone' => $p['tone'] ?? null,
                ], $panel->parts),
            ],
            default => null,
        };
    }

    /**
     * ⓘ সেলগুলো ওয়েবের তালিকার একই `render` দিয়ে ([[Table::cell()]]) — তারপর কেবল লেখা, HTML নয়।
     *
     * @return array<string, mixed>
     */
    private function listing(Listing $listing): array
    {
        $columns = array_values($listing->columns);

        return [
            'label' => $listing->label,
            'empty' => $listing->empty,
            'columns' => array_map(fn (array $c) => ['key' => $c['key'], 'label' => $c['label']], $columns),
            'rows' => $listing->rows->take(self::MAX_ROWS)->values()->map(fn ($row, int $i) => array_map(
                fn (array $c) => $this->text(isset($c['render']) && is_callable($c['render']) ? ($c['render'])($row, $i) : data_get($row, $c['key'])),
                $columns,
            ))->all(),
        ];
    }

    private function text(mixed $value): string
    {
        $value = $value instanceof Htmlable ? $value->toHtml() : (string) ($value ?? '');

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
