<?php

declare(strict_types=1);

namespace App\Core\Engines\Map;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\ScreenAddress;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * মানচিত্রের ইঞ্জিন — একটাই, সব মডিউলের জন্য (রিপোর্ট সেন্টার ধাপ ১, ২ অক্টোবর ২০২৬)।
 *
 * ── ⭐ মালিকের কথা, ১ অক্টোবর ২০২৬ ─────────────────────────────────
 * *"ফিন্যান্স মানচিত্রের মতো সব জায়গায়"* — একটাই ইঞ্জিন; তৈরি হলে নিজে থেকে লিংক, নইলে "বাকি", আর "বাকি" কেবল
 * মালিক/অ্যাডমিন দেখেন।
 *
 * ── মানচিত্র মানে কী ─────────────────────────────────────────────────
 * একটা ঘোষিত তালিকা: ভাগ (`title`, ঐচ্ছিক `no`) আর তার লাইন। প্রতিটা লাইন `[নাম, ঠিকানা বা নাল, টীকা বা নাল]`
 * ([[FinancePlan]]-এর পুরনো ছাঁচ), বা চাবিসহ `['label', 'route', 'note']` — অথবা `'url'` যদি ডাকার জন নিজেই
 * লিংকটা বানিয়ে ছেঁকে এনেছেন (রিপোর্ট সেন্টারের মেনু-সারি, [[MenuBuilder]] যেগুলো আগেই অনুমতি ও সুইচ দিয়ে ছেঁকেছে)।
 *
 * ── ⛔ "তৈরি" কে বলে ([[exists()]]) ───────────────────────────────
 * রুটের নাম থাকা যথেষ্ট নয়, যখন ঠিকানায় একটা প্যারামিটার থাকে: `sales.report.show` আছে, কিন্তু `:analysis`
 * স্লাগটা হয়তো এখনো কেউ বানায়নি — ⚠️ তখন লিংকটা ৪০৪-এ যেত, আর "নিজে থেকে লিংক" মানে হত "নিজে থেকে ভাঙা লিংক"।
 * ⭐ তাই প্যারামিটারের জন্য একজন সাক্ষী লাগে: রুটের নিজের বাঁধন (`whereIn`), নয়তো কোনো মডিউলের মেনুতে ঠিক এই
 * ঠিকানার সারি — রিপোর্ট বানালে মেনুর সারি বসেই (তাছাড়া কেউ পৌঁছায় না), আর ঠিক তখনই মানচিত্রের লাইনটা জ্বলে।
 *
 * ── ⛔ কে কী দেখেন ([[draw()]]) ──────────────────────────────────────
 * • তৈরি লাইন — যিনি পর্দাটা খুলতে পারেন (রুটের `can:` চাবি); না পারলে লাইনটাই নেই, ৪০৩-এর লিংক নয়।
 * • "বাকি" লাইন — কেবল সুপার অ্যাডমিন (মালিক); বাকিদের কাছে "শীঘ্রই আসছে" জাতীয় কিছুই নয়।
 */
final class MapEngine
{
    /** @var array<string, true>|null মডিউলের মেনুতে ঘোষিত প্রতিটা "রুট:প্যারামিটার" */
    private ?array $declared = null;

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * ঠিকানাটা সত্যিই একটা খোলা যায় এমন পর্দা কি না — কে দেখছেন তা না দেখে।
     */
    public function exists(?string $address): bool
    {
        if ($address === null || $address === '') {
            return false;
        }

        [$name, $param] = ScreenAddress::split($address);
        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            return false;
        }

        $names = $route->parameterNames();

        if ($param === null) {
            return $names === [];
        }

        if (count($names) !== 1) {
            return false;
        }

        $pattern = $route->wheres[$names[0]] ?? null;

        if (is_string($pattern) && $pattern !== '') {
            return preg_match('#^(?:'.$pattern.')$#u', $param) === 1;
        }

        return isset($this->declared()[$name.ScreenAddress::SEPARATOR.$param]);
    }

    /** তৈরি হলে লিংক, নইলে নাল — ⓘ নাল মানেই "বাকি"। */
    public function urlFor(?string $address): ?string
    {
        return $this->exists($address) ? ScreenAddress::url((string) $address) : null;
    }

    /** "বাকি" লাইন দেখেন কেবল সুপার অ্যাডমিন — চলতি কোম্পানিতে (spatie teams)। */
    public function seesPending(?User $user): bool
    {
        return $user !== null && $user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE);
    }

    /**
     * একজন মানুষের চোখে মানচিত্রটা।
     *
     * @param  list<array{no?: ?string, title: string, code?: ?string, items: list<array<int|string, mixed>>}>  $sections
     */
    public function draw(array $sections, ?User $user): DrawnMap
    {
        $seesPending = $this->seesPending($user);
        $drawn = [];
        $done = 0;
        $total = 0;

        foreach ($sections as $section) {
            $items = [];

            foreach ($section['items'] as $node) {
                $line = $this->line($node);
                $total++;

                if ($line['done']) {
                    $done++;
                }

                $visible = $line['done']
                    ? ($line['vouched'] || $this->mayOpen($user, (string) $line['route']))
                    : $seesPending;

                if ($visible) {
                    unset($line['route'], $line['vouched']);
                    $items[] = $line;
                }
            }

            if ($items === []) {
                continue;
            }

            $drawn[] = [
                'no' => $section['no'] ?? null,
                'title' => (string) $section['title'],
                'code' => $section['code'] ?? null,
                'done' => count(array_filter($items, fn (array $i): bool => $i['done'])),
                'total' => count($items),
                'items' => $items,
            ];
        }

        return new DrawnMap($drawn, $done, $total, $seesPending);
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @return array{label: string, url: ?string, note: ?string, done: bool, route: ?string, vouched: bool}
     */
    private function line(array $node): array
    {
        if (array_is_list($node)) {
            $node = ['label' => $node[0] ?? '', 'route' => $node[1] ?? null, 'note' => $node[2] ?? null];
        }

        /* ⓘ ডাকার জন নিজেই লিংকটা এনেছেন, আগেই ছেঁকে — মেনুর সারি */
        if (isset($node['url']) && is_string($node['url']) && $node['url'] !== '') {
            return ['label' => (string) $node['label'], 'url' => $node['url'], 'note' => $node['note'] ?? null,
                'done' => true, 'route' => null, 'vouched' => true];
        }

        $address = isset($node['route']) && is_string($node['route']) ? $node['route'] : null;
        $url = $this->urlFor($address);

        return [
            'label' => (string) $node['label'],
            'url' => $url,
            'note' => isset($node['note']) && is_string($node['note']) && $node['note'] !== '' ? $node['note'] : null,
            'done' => $url !== null,
            'route' => $address === null ? null : ScreenAddress::split($address)[0],
            'vouched' => false,
        ];
    }

    /**
     * এই মানুষ কি পর্দাটা খুলতে পারেন — রুটের নিজের `can:` চাবিগুলো দিয়ে (কন্ট্রোলারের `middleware()`-সহ)।
     *
     * ⓘ মডেলের সাথে বাঁধা চাবি (`can:update,post`) এখানে মাপা যায় না — পর্দা নিজেই দেখে, তাই সেটা বাধা নয়।
     */
    private function mayOpen(?User $user, string $name): bool
    {
        if ($user === null) {
            return false;
        }

        $route = Route::getRoutes()->getByName($name);

        if (! $route instanceof RoutingRoute) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                continue;
            }

            $ability = substr($middleware, 4);

            if (str_contains($ability, ',')) {
                continue;
            }

            if (! $user->can($ability)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, true> */
    private function declared(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $out = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->menu as $items) {
                foreach ($items as $item) {
                    foreach ((array) ($item['route_params'] ?? []) as $value) {
                        if (is_scalar($value) && isset($item['route'])) {
                            $out[$item['route'].ScreenAddress::SEPARATOR.$value] = true;
                        }
                    }
                }
            }
        }

        return $this->declared = $out;
    }
}
