<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\Sales\Models\CommissionRule;
use App\Modules\Sales\Models\Scheme;
use App\Modules\Sales\Services\CommissionEngine;
use App\Modules\Sales\Services\SchemeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * স্কিম — কোন পণ্যে, কার জন্য, কোন হারে।
 *
 * ── কেন হারগুলো স্কিমের নিজের পাতায় ─────────────────────────────────
 * একটা স্কিমের ধাপ চার-পাঁচটা, আর প্রতিটা ভূমিকার নিজের সিঁড়ি। তালিকার
 * ভেতরে ওগুলো ধরে না, আর আলাদা পর্দায় পাঠালে "এই স্কিমটা আসলে কত দেয়"
 * প্রশ্নের উত্তর দিতে দুইটা পাতা খুলতে হত।
 */
class SchemeController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly SchemeService $schemes,
        private readonly CommissionEngine $engine,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.scheme.view', only: ['index', 'show']),
            new Middleware('can:sales.scheme.manage', only: [
                'store', 'update', 'addRule', 'removeRule', 'activate', 'cancel',
            ]),
        ];
    }

    public function index(Request $request): View
    {
        $query = Scheme::query()
            ->withCount('rules')
            ->when($request->filled('q'), fn ($q) => $q->where(
                fn ($w) => $w->where('code', 'like', '%'.$request->query('q').'%')
                    ->orWhere('name', 'like', '%'.$request->query('q').'%'),
            ))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        $sort = $this->applySort($query, $request, $this->sorts());

        return view('sales::scheme.index', [
            'menu' => $this->menu->forUser($request->user()),
            'schemes' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'status' => $request->query('status'),
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
        ]);
    }

    /**
     * বাছাইয়ের ক্রম — প্রথমটাই ডিফল্ট।
     *
     * ---- কেন "মেয়াদ শেষের কাছে" আগে ----
     * এই তালিকা খোলা হয় দুই কারণে: নতুন স্কিম বসাতে, আর চলতিগুলো
     * দেখতে। দ্বিতীয়টায় কাজের সারি হলো যেগুলোর মেয়াদ ফুরিয়ে আসছে --
     * ওগুলোই নবায়ন বা থামানোর সিদ্ধান্ত চায়। কোড ধরে সাজালে সবচেয়ে
     * পুরনো স্কিমটা উপরে থাকত, যেটা কারও কাজে লাগে না।
     *
     * @return array<string, callable(Builder): mixed>
     */
    private function sorts(): array
    {
        return [
            'valid_to' => fn ($q) => $q->orderBy('valid_to'),
            'code' => fn ($q) => $q->orderBy('code'),
            'name' => fn ($q) => $q->orderBy('name'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'valid_to' => __('sales::field.valid_to'),
            'code' => __('sales::field.scheme_code'),
            'name' => __('core.table.name'),
        ];
    }

    public function show(Request $request, Scheme $scheme): View
    {
        return view('sales::scheme.show', [
            'menu' => $this->menu->forUser($request->user()),
            'scheme' => $scheme->load(['rules' => fn ($q) => $q
                ->orderBy('level_order')->orderBy('slab_from')]),

            /*
             * ভূমিকার তালিকাটা যা আগে ব্যবহার হয়েছে তা থেকেই।
             *
             * কোরে একটা enum বসালে যাঁর ভূমিকার নাম তালিকায় নেই তিনি
             * স্কিমই বানাতে পারতেন না — প্রতিটা পরিবেশক নিজের মতো নাম
             * দেয় (SR, বিক্রয় প্রতিনিধি, দালাল)।
             */
            'roles' => $this->engine->rolesUsed(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $scheme = $this->schemes->create($this->validated($request));

        return redirect()->route('sales.scheme.show', $scheme)
            ->with('saved', __('sales::message.scheme_created', ['code' => $scheme->code]));
    }

    public function update(Request $request, Scheme $scheme): RedirectResponse
    {
        $this->schemes->update($scheme, $this->validated($request, $scheme->id));

        return back()->with('saved', __('core.message.saved'));
    }

    public function addRule(Request $request, Scheme $scheme): RedirectResponse
    {
        $data = $request->validate([
            'earner_role' => ['required', 'string', 'max:40'],

            /*
             * হার বা থোক টাকা — অন্তত একটা।
             *
             * দুইটাই খালি রেখে সারি বসানো যেত, আর তখন ধাপটা কিছুই
             * দিত না। পর্দায় সারিটা দেখা যেত, তাই কেউ ভাবত হার বসানো
             * আছে।
             */
            'rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_without:fixed_amount'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0', 'required_without:rate_percent'],

            'slab_from' => ['required', 'numeric', 'min:0'],
            'slab_to' => ['nullable', 'numeric', 'gt:slab_from'],
            'level_order' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $this->schemes->addRule($scheme, $data);

        return back()->with('saved', __('sales::message.scheme_rule_added'));
    }

    public function removeRule(Scheme $scheme, CommissionRule $rule): RedirectResponse
    {
        abort_unless($rule->scheme_id === $scheme->id, 404);

        $this->schemes->removeRule($rule);

        return back()->with('saved', __('core.message.deleted'));
    }

    public function activate(Scheme $scheme): RedirectResponse
    {
        $this->schemes->activate($scheme);

        return back()->with('saved', __('sales::message.scheme_activated'));
    }

    public function cancel(Request $request, Scheme $scheme): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $this->schemes->cancel($scheme, $data['reason']);

        return back()->with('saved', __('sales::message.scheme_cancelled'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            // খালি রাখা যায় — [[SchemeService::create()]] তখন নাম থেকে বসায়
            'code' => ['nullable', 'string', 'max:40',
                Rule::unique('sal_schemes', 'code')->ignore($ignore)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:160'],
            'basis' => ['required', Rule::in([Scheme::VALUE, Scheme::VOLUME, Scheme::SLAB])],
            'applies_to' => ['required', Rule::in([
                Scheme::ALL, Scheme::PRODUCT, Scheme::CATEGORY,
                Scheme::BRAND, Scheme::TERRITORY, Scheme::DEALER_TIER,
            ])],

            /*
             * লক্ষ্য ছাড়া তাক করা স্কিম কিছুই খুঁজে পায় না।
             *
             * "একটা ব্র্যান্ডের উপর" বাছার পর ব্র্যান্ডটা না বসালে
             * স্কিমটা প্রতিটা বিলে শূন্য ভিত্তি পেত, আর নীরবে কিছুই
             * দিত না — অথচ তালিকায় চালু লেখা থাকত।
             */
            'target_id' => ['nullable', 'integer', 'required_unless:applies_to,'.Scheme::ALL],

            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['applies_to'] === Scheme::ALL) {
            $data['target_id'] = null;
        }

        return $data;
    }

    /**
     * "কীসের উপর" বাছার পর কোন তালিকা থেকে লক্ষ্য বাছা হবে।
     *
     * পর্দায় পাঁচটা ড্রপডাউন একসাথে না দেখিয়ে একটাই দেখানো হয়, আর
     * ভেতরের তালিকাটা বদলায় — নাহলে চারটা অপ্রাসঙ্গিক ঘর প্রতিবার
     * চোখের সামনে থাকত।
     *
     * @return array<string, array<int, string>>
     */
    public static function targets(): array
    {
        return [
            Scheme::PRODUCT => Product::query()->where('is_active', true)->orderBy('code')
                ->pluck('name_en', 'id')->all(),
            Scheme::CATEGORY => ProductCategory::query()->orderBy('code')
                ->pluck('name_en', 'id')->all(),
            Scheme::BRAND => Brand::query()->orderBy('code')
                ->pluck('name_en', 'id')->all(),
            Scheme::TERRITORY => Location::query()->orderBy('name_en')
                ->pluck('name_en', 'id')->all(),
            Scheme::DEALER_TIER => PartyType::query()->orderBy('code')
                ->pluck('name_en', 'id')->all(),
        ];
    }
}
