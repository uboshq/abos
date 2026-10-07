<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Services\LocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ পুরনো এলাকা আর রুটে শাখা বসানো — মালিক, ৬ অক্টোবর ২০২৬ (*"এলাকা ও রুট সব ব্রাঞ্চে একই দেখায় কেন?"*);
 * নিয়ম fe-র (খ), ৬ অক্টোবর ২০২৬।
 *
 * ⓘ একটা নোড কোন শাখার, তা বলে **কে তাকে ব্যবহার করে**: গ্রাহকের শাখা (`customers.location_id`) আর গাড়ির
 * চালানের শাখা (`sal_shipments.route_location_id`)। নিচ থেকে উপরে (রুট → পয়েন্ট → টেরিটরি → এরিয়া):
 *
 *  - এক শাখা ব্যবহার করে → **শাখা পায়** (`set`);
 *  - কয়েক শাখা, আর নোডে কিছুই বাঁধা নেই → **নকল** (`split`): বেশি গ্রাহকের শাখা আসলটা রাখে, বাকি প্রতিটা শাখার
 *    জন্য `কোড-২` ধাঁচের নকল; সেই শাখার গ্রাহক আর সন্তানেরা নকলের নিচে সরে;
 *  - কয়েক শাখা, কিন্তু কিছু বাঁধা (দামের তালিকা, স্কিম, রুটের ভিজিট বা লক্ষ্য, চালান, লিড) বা নিচের কোনো সন্তান
 *    ভাগ হতে পারেনি → **সব শাখার** থাকে (`shared`), তালিকায় কারণসহ;
 *  - কেউ ব্যবহার করে না → বাবার শাখা (`inherit`), উপর থেকে নিচে।
 *
 * ── ⚠️ কেন বাঁধা নোডের নকল নয় ─────────────────────────────────────────────
 * দোকানের দাম ([[SalesPrice::chainOf()]]) আর স্কিম গ্রাহকের এলাকা থেকে উপরে হেঁটে মেলে। বাঁধা X-এর নকলে দোকান
 * সরালে X-এর দাম আর স্কিম ঐ দোকানে পৌঁছাত না — পরদিনের বিলে দাম নীরবে বদলাত; আর পুরনো চালান বা ভিজিট
 * আসল X-কেই দেখাত। ⓘ নকল নোডের বাবা আসলটার বাবাই, তাই উপরের স্তরে বাঁধা দাম ঠিকই পৌঁছায়।
 *
 * ⓘ দেশ, বিভাগ, অঞ্চল সবসময় null ([[Location::BRANCHED_LEVELS]])। আগেই শাখা বসানো নোড ছোঁয়া হয় না — আবার
 * চালালে কিছুই বদলায় না। `apply()` এক লেনদেনে, আগের অবস্থা ফেরত-ফাইলে ([[revert()]])।
 *
 * ⓘ গ্রাহক মডিউলে, এলাকার মডিউলে নয়: কাজটা দোকান সরায়, আর গ্রাহক মডিউল এলাকার উপর নির্ভর করে — উল্টোটা নয়।
 */
final class LocationBranchBackfill
{
    public const SET = 'set';

    public const SPLIT = 'split';

    public const SHARED = 'shared';

    public const INHERIT = 'inherit';

    /**
     * চলতি কোম্পানির পরিকল্পনা — কিছুই লেখে না। ক্রম: নিচ থেকে উপরে (`apply()` এই ক্রমেই চলে)।
     *
     * @return list<array{id: int, code: string, name: string, level: string, action: string, branch: ?int, copies: array<int, string>, customers: array<int, int>, bound: array<string, int>, reason: ?string}>
     */
    public function plan(): array
    {
        $nodes = Location::query()->get(['id', 'parent_id', 'level', 'code', 'name_en', 'name_bn', 'branch_id'])->keyBy('id');
        $children = $nodes->groupBy('parent_id');
        $uses = $this->usesByBranch();
        $customers = $this->customersByBranch();
        $bound = $this->bindings();
        $codes = Location::query()->withTrashed()->pluck('code')->map(fn ($c) => mb_strtolower((string) $c))->flip()->all();

        /** @var array<int, array<string, mixed>> $out */
        $out = [];

        // নিচ থেকে উপরে — সন্তানের সিদ্ধান্ত বাবার আগে
        foreach (array_reverse(Location::LADDER) as $level) {
            foreach ($nodes->where('level', $level) as $node) {
                $id = (int) $node->id;
                $kids = $children->get($id, collect());

                // ⓘ সন্তানের টুকরো: শাখা-সেট (নকলসহ), আর কেউ ভাগ হতে না পারা অবস্থায় আটকে আছে কি না
                $set = $uses[$id] ?? [];
                $people = $customers[$id] ?? [];
                $blocked = false;

                foreach ($kids as $kid) {
                    $k = $out[(int) $kid->id] ?? null;

                    foreach (($k['pieces'] ?? ($kid->branch_id !== null ? [(int) $kid->branch_id] : [])) as $b) {
                        $set[$b] = true;
                    }

                    foreach (($k['customers'] ?? []) as $b => $n) {
                        $people[$b] = ($people[$b] ?? 0) + $n;
                    }

                    $blocked = $blocked || ($k['action'] ?? null) === self::SHARED && ($k['pieces'] ?? []) === [] && ($k['used'] ?? false);
                }

                $entry = [
                    'id' => $id, 'code' => (string) $node->code, 'name' => $node->name(), 'level' => $level,
                    'action' => null, 'branch' => null, 'copies' => [], 'customers' => $people,
                    'bound' => $bound[$id] ?? [], 'reason' => null, 'pieces' => [], 'used' => $set !== [] || $blocked,
                ];

                if ($node->branch_id !== null) {
                    $entry['action'] = null;
                    $entry['pieces'] = [(int) $node->branch_id];
                } elseif (! in_array($level, Location::BRANCHED_LEVELS, true)) {
                    $entry['action'] = null;
                } elseif ($blocked) {
                    $entry['action'] = self::SHARED;
                    $entry['reason'] = 'child_shared';
                } elseif (count($set) === 1) {
                    $entry['action'] = self::SET;
                    $entry['branch'] = (int) array_key_first($set);
                    $entry['pieces'] = [$entry['branch']];
                } elseif (count($set) > 1 && $entry['bound'] !== []) {
                    $entry['action'] = self::SHARED;
                    $entry['reason'] = 'bound';
                } elseif (count($set) > 1) {
                    $branches = array_map('intval', array_keys($set));
                    sort($branches);
                    // বেশি গ্রাহকের শাখা আসলটা রাখে; সমান হলে ছোট নম্বরের শাখা
                    usort($branches, fn (int $x, int $y) => ($people[$y] ?? 0) <=> ($people[$x] ?? 0) ?: $x <=> $y);
                    $entry['action'] = self::SPLIT;
                    $entry['branch'] = array_shift($branches);
                    $entry['pieces'] = [$entry['branch'], ...$branches];

                    foreach ($branches as $b) {
                        $entry['copies'][$b] = $this->freeCode((string) $node->code, $codes);
                    }
                } else {
                    $entry['action'] = self::INHERIT;
                }

                $out[$id] = $entry;
            }
        }

        // ⓘ খালিরা বাবার শাখা পায় — উপর থেকে নিচে, বাবার চূড়ান্ত শাখা ধরে (নকল হলে আসলটার)
        $final = static fn (?array $e, $node): ?int => $node === null ? null
            : ($node->branch_id !== null ? (int) $node->branch_id : (in_array($e['action'] ?? null, [self::SET, self::SPLIT, self::INHERIT], true) ? $e['branch'] : null));

        foreach (Location::LADDER as $level) {
            foreach ($nodes->where('level', $level) as $node) {
                if (($out[(int) $node->id]['action'] ?? null) !== self::INHERIT) {
                    continue;
                }

                $parent = $node->parent_id === null ? null : $nodes->get((int) $node->parent_id);
                $out[(int) $node->id]['branch'] = $final($parent === null ? null : ($out[(int) $parent->id] ?? null), $parent);
            }
        }

        return array_values(array_map(
            fn (array $e) => array_diff_key($e, ['pieces' => 1, 'used' => 1]),
            array_filter($out, fn (array $e) => $e['action'] !== null && ! ($e['action'] === self::INHERIT && $e['branch'] === null)),
        ));
    }

    /**
     * পরিকল্পনাটা বসানো — এক লেনদেনে। আগের অবস্থা (প্রতিটা নোডের শাখা ও বাবা, প্রতিটা সরানো গ্রাহকের পয়েন্ট)
     * লেনদেনের **আগে** ফাইলে লেখা হয়, নকলের নম্বর পরে যোগ হয়।
     *
     * @param  list<array<string, mixed>>  $plan
     * @return array{set: int, copies: int, moved: int, file: string}
     */
    public function apply(array $plan, string $file): array
    {
        $company = (int) CompanyContext::id();
        $ids = array_column($plan, 'id');

        $before = [
            'company_id' => $company,
            'nodes' => Location::query()->whereIn('parent_id', $ids)->orWhereIn('id', $ids)->get(['id', 'branch_id', 'parent_id'])
                ->map(fn (Location $l) => ['id' => $l->id, 'branch_id' => $l->branch_id, 'parent_id' => $l->parent_id])->values()->all(),
            'customers' => $this->customersOn($ids)->get(['id', 'location_id'])
                ->map(fn (Customer $c) => ['id' => $c->id, 'location_id' => $c->location_id])->values()->all(),
            'created' => [],
        ];
        $this->write($file, $before);

        $result = DB::transaction(function () use ($plan): array {
            $set = $copies = $moved = 0;
            $created = [];

            foreach ($plan as $e) {
                if ($e['action'] === self::INHERIT) {
                    continue;
                }

                $node = Location::query()->findOrFail($e['id']);

                if ($node->branch_id !== null || $e['action'] === self::SHARED) {
                    continue;
                }

                $node->forceFill(['branch_id' => $e['branch']])->save();
                $set++;

                foreach ($e['copies'] as $branch => $code) {
                    $copy = Location::query()->create([
                        'company_id' => $node->company_id, 'branch_id' => (int) $branch, 'parent_id' => $node->parent_id,
                        'code' => $code, 'name_en' => $node->name_en, 'name_bn' => $node->name_bn, 'level' => $node->level,
                        'assigned_to' => $node->assigned_to, 'is_active' => $node->is_active,
                    ]);
                    $created[] = (int) $copy->id;
                    $copies++;

                    // সেই শাখার দোকান আর সন্তান (নকলসহ) নকলের নিচে
                    foreach ($this->customersOn([$node->id])->where('branch_id', (int) $branch)->get() as $shop) {
                        $shop->forceFill(['location_id' => $copy->id])->save();
                        $moved++;
                    }

                    foreach (Location::query()->where('parent_id', $node->id)->where('branch_id', (int) $branch)->get() as $kid) {
                        $kid->forceFill(['parent_id' => $copy->id])->save();
                    }
                }
            }

            // খালিরা — উপর থেকে নিচে, বাবার এখনকার শাখা
            foreach (Location::LADDER as $level) {
                foreach ($plan as $e) {
                    if ($e['action'] !== self::INHERIT || $e['level'] !== $level) {
                        continue;
                    }

                    $node = Location::query()->with('parent')->findOrFail($e['id']);
                    $branch = $node->parent?->branch_id;

                    if ($node->branch_id === null && $branch !== null) {
                        $node->forceFill(['branch_id' => (int) $branch])->save();
                        $set++;
                    }
                }
            }

            return ['set' => $set, 'copies' => $copies, 'moved' => $moved, 'created' => $created];
        });

        $this->write($file, [...$before, 'created' => $result['created']]);

        return ['set' => $result['set'], 'copies' => $result['copies'], 'moved' => $result['moved'], 'file' => $file];
    }

    /**
     * ফেরত — ফাইলের আগের অবস্থায়, এক লেনদেনে। ⛔ কোনো নকল এর মধ্যে ব্যবহার হয়ে থাকলে (নতুন দোকান, চালান,
     * দামের তালিকা) কিছুই না করে থামে — মোছা নকল পুরনো কাগজকে অনাথ করত।
     */
    public function revert(string $file): int
    {
        $state = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        if ((int) $state['company_id'] !== (int) CompanyContext::id()) {
            throw ValidationException::withMessages(['file' => 'ফাইলটা অন্য কোম্পানির — কিছুই ফেরানো হয়নি।']);
        }

        return DB::transaction(function () use ($state): int {
            $created = array_map('intval', $state['created']);
            $known = array_column($state['customers'], 'id');

            $strangers = Customer::query()->withoutGlobalScopes()->withTrashed()->whereIn('location_id', $created)->whereNotIn('id', $known ?: [0])->count();
            // ⓘ নকলে যা যা বাঁধা (দোকান আর গাছ বাদে), আর নকলের নিচে নতুন বসানো এলাকা — ফাইলে লেখা সন্তান আর নকল নিজে বাদ
            $links = $created === [] ? 0
                : array_sum(array_map('array_sum', app(LocationService::class)->tiesOf($created, ['customers', 'mdm_locations'])))
                    + Location::query()->withTrashed()->whereIn('parent_id', $created)
                        ->whereNotIn('id', [...array_column($state['nodes'], 'id'), ...$created])->count();

            if ($strangers + $links > 0) {
                throw ValidationException::withMessages(['file' => "নকলগুলো এর মধ্যে ব্যবহার হয়েছে ({$strangers}টা নতুন দোকান, {$links}টা অন্য সারি) — কিছুই ফেরানো হয়নি।"]);
            }

            foreach ($state['customers'] as $row) {
                Customer::query()->withoutGlobalScopes()->withTrashed()->whereKey($row['id'])->first()
                    ?->forceFill(['location_id' => $row['location_id']])->save();
            }

            foreach ($state['nodes'] as $row) {
                Location::query()->whereKey($row['id'])->first()?->forceFill(['branch_id' => $row['branch_id'], 'parent_id' => $row['parent_id']])->save();
            }

            foreach (Location::query()->whereIn('id', $created)->get() as $copy) {
                $copy->forceDelete();
            }

            return count($state['nodes']);
        });
    }

    // ── ভেতরের ──

    /** @param  list<int>  $ids */
    private function customersOn(array $ids)
    {
        return Customer::query()->withoutGlobalScopes()->withTrashed()
            ->where('company_id', CompanyContext::id())->whereIn('location_id', $ids ?: [0]);
    }

    /**
     * কে কোন নোড ব্যবহার করে — মোছা গ্রাহকও (পুরনো বিল তাদের নামে)।
     *
     * @return array<int, array<int, true>>
     */
    private function usesByBranch(): array
    {
        $company = CompanyContext::id();

        $customers = DB::table('customers')
            ->where('company_id', $company)->whereNotNull('location_id')->whereNotNull('branch_id')
            ->select(['location_id', 'branch_id'])->distinct();

        $shipments = DB::table('sal_shipments')
            ->where('company_id', $company)->whereNotNull('route_location_id')->whereNotNull('branch_id')
            ->select(['route_location_id AS location_id', 'branch_id'])->distinct();

        $out = [];

        foreach ($customers->union($shipments)->get() as $row) {
            $out[(int) $row->location_id][(int) $row->branch_id] = true;
        }

        return $out;
    }

    /** @return array<int, array<int, int>> */
    private function customersByBranch(): array
    {
        $out = [];

        foreach (DB::table('customers')->where('company_id', CompanyContext::id())->whereNotNull('location_id')->whereNotNull('branch_id')
            ->selectRaw('location_id, branch_id, COUNT(*) AS n')->groupBy('location_id', 'branch_id')->get() as $row) {
            $out[(int) $row->location_id][(int) $row->branch_id] = (int) $row->n;
        }

        return $out;
    }

    /**
     * নোডে কী কী বাঁধা — ডাটাবেজের নিজের বিদেশি-চাবির তালিকা থেকে ([[LocationService::purge()]]-এর একই কারণ:
     * হাতে লেখা তালিকা নতুন টেবিলে ফাঁক রাখত), আর স্কিমের "এলাকা" লক্ষ্য (ওটা বিদেশি চাবি নয়)।
     *
     * @return array<int, array<string, int>>
     */
    private function bindings(): array
    {
        // ⓘ কেবল এই কোম্পানির এলাকার id ধরে — বাঁধা টেবিলগুলো কোম্পানির দেয়াল জানে না, এলাকা জানে
        $out = app(LocationService::class)->tiesOf(
            Location::query()->withTrashed()->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ['customers', 'mdm_locations'],
        );

        foreach (DB::table('sal_schemes')->where('company_id', CompanyContext::id())->where('applies_to', 'territory')
            ->selectRaw('target_id AS node, COUNT(*) AS n')->groupBy('target_id')->get() as $row) {
            $out[(int) $row->node]['sal_schemes'] = (int) $row->n;
        }

        return $out;
    }


    /** @param  array<string, int>  $taken */
    private function freeCode(string $code, array &$taken): string
    {
        for ($n = 2; ; $n++) {
            $suffix = '-'.$n;
            $candidate = mb_substr($code, 0, 32 - mb_strlen($suffix)).$suffix;

            if (! isset($taken[mb_strtolower($candidate)])) {
                $taken[mb_strtolower($candidate)] = 1;

                return $candidate;
            }
        }
    }

    /** @param  array<string, mixed>  $state */
    private function write(string $file, array $state): void
    {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }

        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
