<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * কোন শাখা কোন মডিউল পাবে।
 *
 * ── ⓘ কেন কন্ট্রোল প্যানেলের পাশে, ভিতরে নয় (২৮ নভেম্বর ২০২৬) ─────────
 * কন্ট্রোল প্যানেল **কোম্পানির** সুইচ ধরে — `settings` টেবিল, unique
 * `(company_id, key)`। ⛔ ওখানে শাখা ঢোকাতে হলে ঐ unique index বদলাতে
 * হত, আর সব সেটিংস ঐ একটা টেবিলে বসে।
 *
 * ⭐ তাই আলাদা পর্দা, আলাদা টেবিল ([[BranchModule]]) — আর প্রশ্নটাও
 * আলাদা: কন্ট্রোল প্যানেল বলে *"এই ব্যবসা মডিউলটা নেয়ইনি"*, এটা বলে
 * *"এই ডিপোতে লাগে না"*।
 *
 * ⚠️ ক্রমটা এক দিকেই চলে: শাখা এমন কিছু **চালু করতে পারে না** যা
 * কোম্পানি বন্ধ রেখেছে ([[MenuBuilder::moduleEnabled()]])। পর্দায় তাই
 * ঐ সারিগুলো ধরা থাকে, লুকানো নয় — লুকালে মানুষ ভাবতেন মডিউলটা নেই।
 */
class BranchModuleController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly SettingsService $settings,
        private readonly MenuBuilder $menu,
        private readonly MenuSwitches $switches,
    ) {}

    public static function middleware(): array
    {
        /*
         * ⓘ কন্ট্রোল প্যানেলের চাবিই — দুইটা পর্দা একই প্রশ্নের দুই অর্ধেক
         * ("কোন পর্দাগুলো থাকবে")। ⚠️ আলাদা চাবি দিলে কাউকে অর্ধেক উত্তর
         * বদলানোর অধিকার দেওয়া হত, আর অর্ধেক উত্তর কোনো উত্তর নয়।
         */
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function edit(Request $request): View
    {
        $branches = $this->branches();

        $current = $this->chosenBranch($request, $branches);

        return view('system_admin::branch-module.edit', [
            'menu' => $this->menu->forUser($request->user()),
            'branches' => $branches,
            'current' => $current,
            'modules' => $current === null ? [] : $this->modulesFor($current),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $branches = $this->branches();

        $data = $request->validate([
            'branch' => ['required', 'integer'],
            'scope' => ['array'],
            'scope.*' => ['string', 'max:64'],

            /*
             * ⓘ চাবিগুলোই মডিউলের কোড (`modules[accounts]`), মান নয়।
             *
             * ⚠️ `modules[]` হলে সব ঘরের নাম এক হত, আর `switchBoard.touch()`
             * বদল গোনে **নাম ধরে** — দশটা বদলও "১টা" দেখাত।
             */
            'modules' => ['array'],
        ]);

        /*
         * ⛔ শাখাটা সত্যিই এই কোম্পানির কি না — `$branches` ইতিমধ্যেই
         * কোম্পানির দেয়ালের ভিতর দিয়ে এসেছে ([[BelongsToCompany]])।
         *
         * ⚠️ কেবল `Branch::find()` করলে দেয়ালটা কোয়েরিতে থাকত, কিন্তু
         * "ঐ শাখা আমার পর্দায় ছিল কি না" প্রশ্নের উত্তর থাকত না — আর
         * নিষ্ক্রিয় শাখাও তখন বদলানো যেত।
         */
        $branch = $branches->firstWhere('id', (int) $data['branch']);

        abort_if($branch === null, 404);

        /*
         * ⚠️ কেবল যে সারিগুলো ফর্মে ছিল। ⓘ কন্ট্রোল প্যানেলে ৩০ আগস্ট
         * ২০২৬-এ এই ভুলটা সত্যিই হয়েছিল: "চেকবক্স নেই মানে বন্ধ" ধরায়
         * এক ট্যাব সংরক্ষণ করলে অন্য ট্যাবের সুইচগুলো নীরবে বন্ধ হত।
         */
        // ⓘ `all()` কোড ধরেই সাজানো, তাই চাবিগুলোই চেনা মডিউলের তালিকা।
        $scope = array_values(array_intersect(
            $data['scope'] ?? [],
            array_keys($this->registry->all())
        ));

        $wanted = array_keys((array) ($data['modules'] ?? []));

        $changed = 0;

        foreach ($scope as $code) {
            if ($this->nailedDown($code)) {
                continue;
            }

            $on = in_array($code, $wanted, true);

            $row = BranchModule::query()
                ->where('branch_id', $branch->id)
                ->where('module', $code)
                ->first();

            if ($row === null) {
                // ⓘ চালু-ই ডিফল্ট, তাই "চালু" অবস্থায় সারি বসানোর মানে নেই।
                if ($on) {
                    continue;
                }

                BranchModule::create([
                    'branch_id' => $branch->id,
                    'module' => $code,
                    'is_enabled' => false,
                    'created_by' => Auth::id(),
                ]);

                $changed++;

                continue;
            }

            if ((bool) $row->is_enabled === $on) {
                continue;
            }

            $row->is_enabled = $on;
            $row->save();

            $changed++;
        }

        return redirect()
            ->route('system_admin.branch-module', ['branch' => $branch->id])
            ->with('saved', __('system_admin::branch_module.saved', ['count' => $changed]));
    }

    /**
     * এই কোম্পানির সচল শাখাগুলো।
     *
     * @return Collection<int, Branch>
     */
    private function branches(): Collection
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name_en')
            ->get();
    }

    /**
     * ⓘ ঠিকানায় শাখা থাকলে সেটা, নাহলে প্রথমটা।
     *
     * ⚠️ ঠিকানায় থাকাটা ইচ্ছাকৃত — কেউ "ঢাকা ডিপোর মডিউল" বুকমার্ক করে
     * রাখতে পারেন, আর ফিরে গেলে একই শাখায় ফেরেন ([[ControlPanelController]]
     * -এর ট্যাবেও একই কারণে ঠিকানা ব্যবহার হয়)।
     *
     * @param  Collection<int, Branch>  $branches
     */
    private function chosenBranch(Request $request, $branches): ?Branch
    {
        $asked = $request->query('branch');

        if ($asked !== null) {
            $found = $branches->firstWhere('id', (int) $asked);

            if ($found !== null) {
                return $found;
            }
        }

        return $branches->first();
    }

    /**
     * এই শাখার জন্য মডিউলের সারিগুলো।
     *
     * @return list<array{code: string, label: string, on: bool, company_on: bool, locked: bool, off_elsewhere: int}>
     */
    private function modulesFor(Branch $branch): array
    {
        $offHere = BranchModule::query()
            ->where('branch_id', $branch->id)
            ->where('is_enabled', false)
            ->pluck('module')
            ->all();

        /*
         * ⭐ কোন মডিউল আর কয়টা শাখায় বন্ধ — এক নজরে গোটা ছবিটা।
         *
         * ⓘ ট্যাব ধরে একটা শাখা দেখা যায়, কিন্তু "LC কি কেবল এখানেই বন্ধ,
         * নাকি সব ডিপোতেই?" প্রশ্নটার উত্তর ছাড়া সিদ্ধান্তটা অন্ধ হত।
         */
        $offElsewhere = BranchModule::query()
            ->where('is_enabled', false)
            ->where('branch_id', '!=', $branch->id)
            ->selectRaw('module, COUNT(*) as how_many')
            ->groupBy('module')
            ->pluck('how_many', 'module')
            ->all();

        $rows = [];

        foreach ($this->registry->all() as $module) {
            $companyOn = (bool) $this->settings->get($this->switches->forModule($module->code), true);

            $rows[] = [
                'code' => $module->code,
                'label' => $module->label(),
                'on' => ! in_array($module->code, $offHere, true),
                'company_on' => $companyOn,
                'locked' => $this->nailedDown($module->code),
                'off_elsewhere' => (int) ($offElsewhere[$module->code] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * ⛔ যে মডিউলটা এই পর্দাটাই ধরে আছে, সেটা বন্ধ করা যায় না।
     *
     * ⚠️ পারলে কেউ নিজের শাখায় এটা বন্ধ করতেন, আর তারপর **ফিরিয়ে আনার
     * পর্দাটাই** আর মেনুতে থাকত না। ⓘ নামটা হাতে লেখা নেই — এই রুটের
     * নামের উপসর্গ থেকেই আসে, তাই মডিউলের নাম বদলালে এটাও বদলায়।
     */
    private function nailedDown(string $code): bool
    {
        return $code === $this->myOwnModule();
    }

    /**
     * ⓘ রুটের নামের উপসর্গ = মডিউলের কোড।
     *
     * ⚠️ সম্পর্কটা আন্দাজ নয়, নিয়ম: [[ModuleServiceProvider]] প্রতিটা
     * মডিউলের রুটে `->name($code.'.')` বসায়। ⛔ ফোল্ডারের নাম থেকে
     * বের করলে ভুল হত — কোড আসে `module.php`-র ঘোষণা থেকে, ফোল্ডার
     * থেকে নয়।
     *
     * ⚠️ রুট ছাড়া ডাকা হলে (কনসোল, সরাসরি ইউনিট কল) খালি ফেরে, অর্থাৎ
     * কিছুই ধরা থাকে না। ⓘ ওয়েবে এই পথটা নেই, আর ওয়েবই একমাত্র জায়গা
     * যেখানে কেউ নিজের পর্দা নিজে বন্ধ করে ফেলতে পারেন।
     */
    private function myOwnModule(): string
    {
        $name = (string) (request()->route()?->getName() ?? '');

        return str_contains($name, '.') ? (string) strstr($name, '.', true) : $name;
    }
}
