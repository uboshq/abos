<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Support;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ কোম্পানি আর শাখার মডিউল — একসাথে অনেকগুলোর পড়া, হিসাব আর লেখা এক জায়গায় (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4).
 *
 * ⓘ লেখা হয় পুরনো দুই জায়গাতেই: কোম্পানি `<code>.enabled` সেটিং ([[MenuSwitches::forModule()]]), শাখা [[BranchModule]]-এর সারি — তাই
 *   মেনু, দরজা আর ফোনের নিয়ম একটুও বদলায় না, আর অডিটও আগের।
 * ⛔ শাখা ধরে আলাদা প্রশ্ন নেই: দশ কোম্পানির একশো শাখায়ও প্রশ্নের সংখ্যা একই।
 */
final class ModuleBoard
{
    /**
     * ⭐ পর্দায় তালাবন্ধ — এগুলো ছাড়া বাকি কিছু দাঁড়ায় না (নমুনার তালা)। ⓘ কেবল এই পর্দায়: `essential` ঘোষণা বদলায়নি, তাই দরজার নিয়ম
     *   আগের মতো। ২৪ নভেম্বর ২০২৬-এ লাইভে কোনো কোম্পানিতে এগুলো বন্ধ নেই (গুনে দেখা)।
     */
    public const LOCKED = ['accounts', 'master_data', 'governance', 'backup'];

    /** পর্দার চার ভাগ — তালিকায় না থাকা মডিউল শেষ ভাগে */
    private const GROUPS = [
        'business' => ['sales', 'customer', 'credit_control', 'promotion', 'purchase', 'supplier', 'inventory', 'restaurant'],
        'money' => ['accounts', 'finance', 'fixed_assets', 'approval'],
        'people' => ['hr', 'messaging', 'notification', 'assistant'],
        'base' => ['master_data', 'documents', 'executive', 'governance', 'backup', 'system_admin'],
    ];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly SettingsService $settings,
        private readonly MenuSwitches $switches,
    ) {}

    public function locked(string $code): bool
    {
        return in_array($code, self::LOCKED, true) || ($this->registry->get($code)?->essential ?? true);
    }

    /** @return list<array{key: string, label: string, codes: list<string>}> */
    public function groups(): array
    {
        $known = array_keys($this->registry->all());
        $groups = array_map(fn (array $codes) => array_values(array_intersect($codes, $known)), self::GROUPS);
        $groups['base'] = [...$groups['base'], ...array_values(array_diff($known, array_merge(...array_values(self::GROUPS))))];

        $out = [];
        foreach ($groups as $key => $codes) {
            if ($codes !== []) {
                $out[] = ['key' => $key, 'label' => __('system_admin::module_board.group_'.$key), 'codes' => $codes];
            }
        }

        return $out;
    }

    public function label(string $code): string
    {
        return $this->registry->get($code)?->label() ?? $code;
    }

    /** বন্ধ করলে মেনু থেকে কয়টা পর্দা সরে */
    public function screens(string $code): int
    {
        return collect($this->registry->get($code)?->menu ?? [])->flatten(1)->reject(fn ($item) => (bool) ($item['planned'] ?? false))->count();
    }

    /** @return list<string> যে মডিউলগুলো সরাসরি এটার উপর দাঁড়ানো */
    public function dependents(string $code): array
    {
        $out = [];
        foreach ($this->registry->all() as $module) {
            if (in_array($code, $module->dependsOn, true)) {
                $out[] = $module->code;
            }
        }

        return $out;
    }

    /**
     * এই মানুষ যে কোম্পানিগুলোর মডিউল বদলাতে পারেন — চাবিটা **ঐ** কোম্পানিতে থাকতে হয় ([[User::canInCompany()]])।
     *
     * @return Collection<int, Company>
     */
    public function companiesFor(User $user): Collection
    {
        return $user->companies()->where('companies.is_active', true)->orderBy('companies.name_en')->orderBy('companies.id')->get()
            ->filter(fn (Company $c) => $user->canInCompany((int) $c->id, 'system_admin.settings.manage'))->values();
    }

    /**
     * কোন কোম্পানিতে কোন মডিউল বন্ধ — এক প্রশ্নে।
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    public function companyOff(array $ids): array
    {
        $out = array_fill_keys($ids, []);
        $codes = [];
        foreach ($this->registry->all() as $module) {
            if (! $module->essential) {
                $codes[$this->switches->forModule($module->code)] = $module->code;
            }
        }

        foreach (Setting::query()->whereIn('company_id', $ids)->whereIn('key', array_keys($codes))->get() as $row) {
            if (! $row->typedValue()) {
                $out[(int) $row->company_id][] = $codes[$row->key];
            }
        }

        return $out;
    }

    /**
     * কোন শাখায় কোন মডিউল বন্ধ — চলতি কোম্পানির দেয়ালের ভিতরে, এক প্রশ্নে।
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    public function branchOff(array $ids): array
    {
        $out = array_fill_keys($ids, []);

        foreach (BranchModule::query()->whereIn('branch_id', $ids)->where('is_enabled', false)->get(['branch_id', 'module']) as $row) {
            $out[(int) $row->branch_id][] = $row->module;
        }

        return $out;
    }

    /**
     * কী কী বদলাবে — লেখার আগে দেখানোর তালিকা, লেখারও একমাত্র উৎস।
     *
     * @param  array<int, string>  $names  কোনগুলো (id → নাম)
     * @param  array<int, list<string>>  $off  এখন কোথায় কী বন্ধ
     * @param  array<string, bool>  $wanted  মডিউল → চালু চাই কি না
     * @param  list<string>  $wall  কোম্পানিতেই বন্ধ (শাখায় চালু করা যায় না)
     * @return array{changes: list<array{id: int, name: string, module: string, on: bool}>, warnings: list<array{module: string, needs: list<string>, count: int}>, blocked: list<string>}
     */
    public function plan(array $names, array $off, array $wanted, array $wall = []): array
    {
        $changes = [];
        $blocked = [];
        $warn = [];

        foreach ($wanted as $code => $on) {
            if ($this->registry->get($code) === null || $this->locked($code)) {
                continue;
            }
            if (in_array($code, $wall, true)) {
                $blocked[] = $code;

                continue;
            }
            foreach ($names as $id => $name) {
                if (in_array($code, $off[$id] ?? [], true) !== $on) {
                    continue;
                }
                $changes[] = ['id' => $id, 'name' => $name, 'module' => $code, 'on' => $on];
            }
        }

        // ⓘ সতর্কতা, শিকল নয়: বন্ধ করা মডিউলের উপর দাঁড়ানো কিছু যেখানে চালু থেকে যাচ্ছে, সেটাই বলা হয় — নিজে থেকে কিছু বন্ধ হয় না
        $after = $off;
        foreach ($changes as $c) {
            $after[$c['id']] = $c['on'] ? array_values(array_diff($after[$c['id']] ?? [], [$c['module']])) : [...($after[$c['id']] ?? []), $c['module']];
        }
        foreach ($changes as $c) {
            if ($c['on']) {
                continue;
            }
            $needs = array_values(array_diff($this->dependents($c['module']), $after[$c['id']], $wall));
            if ($needs !== []) {
                $warn[$c['module']]['needs'] = array_values(array_unique([...($warn[$c['module']]['needs'] ?? []), ...$needs]));
                $warn[$c['module']]['count'] = ($warn[$c['module']]['count'] ?? 0) + 1;
            }
        }

        $warnings = [];
        foreach ($warn as $code => $w) {
            $warnings[] = ['module' => (string) $code, 'needs' => $w['needs'], 'count' => $w['count']];
        }

        return ['changes' => $changes, 'warnings' => $warnings, 'blocked' => $blocked];
    }

    /** দেখানো তালিকার ছাপ — দেখার পরে অন্য কেউ বদলালে লেখা থামে */
    public function stamp(array $changes): string
    {
        return hash('sha256', (string) json_encode(array_map(fn (array $c) => [$c['id'], $c['module'], $c['on']], $changes)));
    }

    /** @param  list<array{id: int, name: string, module: string, on: bool}>  $changes */
    public function writeCompanies(array $changes): int
    {
        return DB::transaction(function () use ($changes): int {
            foreach ($changes as $c) {
                CompanyContext::forCompany($c['id'], fn () => $this->settings->set($this->switches->forModule($c['module']), $c['on']));
            }

            return count($changes);
        });
    }

    /**
     * ⛔ চলতি কোম্পানির ভিতরে ডাকা ([[CompanyContext]]) — ডাকার আগেই শাখাগুলো ঐ কোম্পানির দেয়াল পেরিয়ে এসেছে।
     *
     * @param  list<array{id: int, name: string, module: string, on: bool}>  $changes
     */
    public function writeBranches(array $changes, ?int $userId, bool $byHand = true): int
    {
        return DB::transaction(function () use ($changes, $userId, $byHand): int {
            $rows = BranchModule::query()->whereIn('branch_id', array_values(array_unique(array_column($changes, 'id'))))
                ->get()->keyBy(fn (BranchModule $r) => $r->branch_id.'|'.$r->module);
            $written = 0;

            foreach ($changes as $c) {
                $row = $rows->get($c['id'].'|'.$c['module']);

                if ($row !== null) {
                    $row->is_enabled = $c['on'];
                    $row->save();
                    $written++;
                } elseif (! $c['on']) {
                    // ⓘ চালু-ই ডিফল্ট, তাই "চালু" বসাতে সারি লাগে না
                    BranchModule::create(['branch_id' => $c['id'], 'module' => $c['module'], 'is_enabled' => false, 'created_by' => $userId]);
                    $written++;
                }
            }

            if ($byHand) {
                app(ModulePackages::class)->markHandMade(array_values(array_unique(array_column($changes, 'id'))));
            }

            return $written;
        });
    }

    /**
     * বদলের ইতিহাস — কে, কবে, কোথায়, কোন মডিউল।
     *
     * @param  list<int>  $ids
     * @return list<array{when: \Illuminate\Support\Carbon|null, who: ?string, where: string, module: string, on: bool}>
     */
    public function branchHistory(array $ids, array $names): array
    {
        $rows = BranchModule::query()->whereIn('branch_id', $ids)->get(['id', 'branch_id', 'module'])->keyBy('id');

        return $this->history(BranchModule::class, $rows->keys()->all(), 'is_enabled',
            fn (int $id) => [$names[$rows[$id]->branch_id] ?? '', $this->label($rows[$id]->module)]);
    }

    /** @param  list<int>  $ids */
    public function companyHistory(array $ids, array $names): array
    {
        $codes = [];
        foreach ($this->registry->all() as $module) {
            $codes[$this->switches->forModule($module->code)] = $module->code;
        }
        $rows = Setting::query()->whereIn('company_id', $ids)->whereIn('key', array_keys($codes))->get(['id', 'company_id', 'key'])->keyBy('id');

        return $this->history(Setting::class, $rows->keys()->all(), 'value',
            fn (int $id) => [$names[$rows[$id]->company_id] ?? '', $this->label($codes[$rows[$id]->key])]);
    }

    private function history(string $type, array $ids, string $field, callable $say): array
    {
        if ($ids === []) {
            return [];
        }

        return AuditTrail::query()->where('auditable_type', $type)->whereIn('auditable_id', $ids)
            ->with(['changes', 'user'])->latest('id')->limit(20)->get()
            ->map(function (AuditTrail $trail) use ($field, $say): array {
                [$where, $module] = $say((int) $trail->auditable_id);

                return ['when' => $trail->created_at, 'who' => $trail->user?->name, 'where' => $where, 'module' => $module,
                    'on' => filter_var($trail->changes->firstWhere('field', $field)?->new_value, FILTER_VALIDATE_BOOLEAN)];
            })->all();
    }

    /**
     * ঠিকানার বাছাই — `pick=all` মানে ছাঁকনিতে মেলা সব, নইলে পাঠানো নম্বরের যেগুলো দেয়ালের ভিতরে।
     *
     * @param  list<int>  $allowed
     * @return list<int>
     */
    public static function picked(mixed $raw, array $allowed): array
    {
        if ($raw === 'all') {
            return $allowed;
        }

        $asked = array_map('intval', array_filter((array) $raw, 'is_scalar'));

        return array_values(array_intersect($allowed, $asked));
    }

    /** ফর্মের মডিউলগুলো → চাওয়া অবস্থা; মিশ্র ঘর না ছুঁলে কিছুই চাওয়া হয় না */
    public function wanted(array $scope, array $modules, array $was, array $allOn): array
    {
        $wanted = [];
        foreach (array_filter($scope, 'is_string') as $code) {
            $on = isset($modules[$code]);
            if (($was[$code] ?? null) === 'mixed') {
                if (isset($allOn[$code])) {
                    $wanted[$code] = true;
                } elseif (! $on) {
                    $wanted[$code] = false;
                }

                continue;
            }
            $wanted[$code] = $on;
        }

        return $wanted;
    }

    /**
     * ডান পাশের সারি — প্রতিটা মডিউলে বাছাগুলোর কয়টায় চালু।
     *
     * @param  list<int>  $ids
     * @param  array<int, list<string>>  $off
     * @param  list<string>  $wall
     */
    public function rows(array $ids, array $off, array $wall = []): array
    {
        $total = count($ids);

        return array_map(fn (array $g) => [...$g, 'rows' => array_map(function (string $code) use ($ids, $off, $wall, $total): array {
            $on = count(array_filter($ids, fn (int $id) => ! in_array($code, $off[$id] ?? [], true)));
            $locked = $this->locked($code);

            return ['code' => $code, 'label' => $this->label($code), 'on' => $locked || $on > 0, 'count' => $on, 'total' => $total,
                'state' => $locked || $on === $total ? 'on' : ($on === 0 ? 'off' : 'mixed'), 'locked' => $locked,
                'wall' => in_array($code, $wall, true), 'screens' => $this->screens($code),
                'needs' => array_map($this->label(...), $this->dependents($code))];
        }, $g['codes'])], $this->groups());
    }

    /** এই কোম্পানির সচল শাখা, ছাঁকনি আর খোঁজাসহ — চলতি কোম্পানির দেয়ালের ভিতরে */
    public function branches(string $q = '', string $state = ''): \Illuminate\Database\Eloquent\Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $off = fn () => BranchModule::query()->select('branch_id')->where('is_enabled', false);

        return Branch::query()->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name_en', 'like', $like)->orWhere('name_bn', 'like', $like)->orWhere('code', 'like', $like)))
            ->when($state === 'diff', fn ($query) => $query->whereIn('id', $off()))
            ->when($state === 'same', fn ($query) => $query->whereNotIn('id', $off()))
            ->orderByDesc('is_default')->orderBy('name_en')->orderBy('id');
    }
}
