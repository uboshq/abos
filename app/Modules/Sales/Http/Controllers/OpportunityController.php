<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Services\OpportunityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * সুযোগ ও পাইপলাইন — NEXUS স্পেক §৭।
 *
 * ── চাবি দুইটা, লিডের মতোই ─────────────────────────────────────────────
 *   sales.opportunity.view    নিজের সুযোগ দেখা, লেখা, ধাপ বদলানো
 *   sales.opportunity.manage  সবার সুযোগ ও সবার অঙ্ক, বিক্রয়কর্মী বদলানো
 */
class OpportunityController extends Controller implements HasMiddleware
{
    use GrandTotals;

    /** ফর্মে কয়টা পণ্যের সারি — JS ছাড়া, তাই সংখ্যাটা স্থির। */
    public const LINE_ROWS = 5;

    public function __construct(
        private readonly OpportunityService $opportunities,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.opportunity.view', only: [
                'index', 'pipeline', 'create', 'store', 'show', 'edit', 'update',
            ]),
        ];
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $query = $this->opportunities->visibleTo($user)
            ->with(['customer', 'lead', 'stage', 'salesperson'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->query('q'))).'%';

                $q->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhere('document_no', 'like', $like));
            })
            ->when($request->filled('stage'), fn ($q) => $q->where('stage_id', (int) $request->query('stage')))
            ->latest('id');

        return view('sales::crm.opportunity.index', [
            'menu' => $this->menu->forUser($user),
            'opportunities' => (clone $query)->paginate(50)->withQueryString(),
            // ⭐ যোগফলের পট্টি — গোটা ছাঁকনির আনুমানিক মূল্য, পাতার নয় (মালিক, ৫ অক্টোবর ২০২৬)
            'grand' => $this->grandTotals($query, ['value' => 't.estimated_value']),
            'stages' => $this->opportunities->stages()->mapWithKeys(fn ($s) => [$s->id => $s->name()]),
            'q' => $request->query('q'),
            'stage' => $request->query('stage'),
        ]);
    }

    /**
     * ⓘ পাতা ভাগ নেই, তবু বাঁধা: প্রতিটা ধাপের নিচে সর্বোচ্চ
     * [[OpportunityService::PIPELINE_ROWS_PER_STAGE]] সারি, আর যোগফল
     * ডাটাবেজে। বাকিগুলো তালিকার পর্দায়, ধাপের ছাঁকনিসহ।
     */
    public function pipeline(Request $request): View
    {
        $board = $this->opportunities->pipeline($request->user());

        return view('sales::crm.opportunity.pipeline', [
            'menu' => $this->menu->forUser($request->user()),
            'board' => $board,
            'openWeighted' => $this->opportunities->openWeighted($board),
        ]);
    }

    public function create(Request $request): View
    {
        $this->opportunities->ensureStages();

        $opportunity = new Opportunity(['lead_id' => $request->integer('lead') ?: null]);

        return view('sales::crm.opportunity.form', [
            'menu' => $this->menu->forUser($request->user()),
            'opportunity' => $opportunity,
            ...$this->choices($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $lines] = $this->validated($request);

        $opportunity = $this->opportunities->create($data, $lines, $request->user());

        return redirect()
            ->route('sales.opportunity.show', $opportunity->id)
            ->with('saved', __('sales::crm.opportunity_saved'));
    }

    public function show(Request $request, int $opportunity): View
    {
        $record = $this->opportunities->find($opportunity, $request->user());

        return view('sales::crm.opportunity.show', [
            'menu' => $this->menu->forUser($request->user()),
            'opportunity' => $record->load(['customer', 'lead', 'stage', 'salesperson', 'lines.product']),

            /*
             * ⏳ কোটেশনের দরজা — আরেকটা কাজে তৈরি হচ্ছে।
             *
             * ⓘ রুটটা থাকলে তবেই বাটন, নাহলে কিছুই নয়। ⚠️ মরা লিংক
             * দেখানো "কাজ করে না এমন বাটন"-এর চেয়েও খারাপ।
             */
            // ⛔ চাবি ছাড়া বাটন দেখালে চাপলেই ৪০৩ — সেটাও মরা লিংক
            'quotationUrl' => $record->isWon() && $record->sales_quotation_id === null
                && Route::has('sales.quotation.create')
                && $request->user()->can('sales.quotation.create')
                    ? route('sales.quotation.create', ['opportunity' => $record->id])
                    : null,
        ]);
    }

    public function edit(Request $request, int $opportunity): View
    {
        $record = $this->opportunities->find($opportunity, $request->user());

        abort_if($record->sales_quotation_id !== null, 404);

        return view('sales::crm.opportunity.form', [
            'menu' => $this->menu->forUser($request->user()),
            'opportunity' => $record->load('lines'),
            ...$this->choices($request, $record),
        ]);
    }

    public function update(Request $request, int $opportunity): RedirectResponse
    {
        $record = $this->opportunities->find($opportunity, $request->user());

        [$data, $lines] = $this->validated($request);

        $this->opportunities->update($record, $data, $lines, $request->user());

        return redirect()
            ->route('sales.opportunity.show', $record->id)
            ->with('saved', __('sales::crm.opportunity_saved'));
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        $company = CompanyContext::id();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'customer_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'salesperson_user_id' => ['nullable', 'integer'],
            'stage_id' => ['required', 'integer'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['nullable', 'date'],
            'competitor' => ['nullable', 'string', 'max:191'],
            'remarks' => ['nullable', 'string', 'max:5000'],

            'lines' => ['nullable', 'array', 'max:'.self::LINE_ROWS],
            'lines.*.product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $company)],
            // decimal:0,4 — "1e5"-এর মতো লেখা is_numeric পেরোয়, bcmath-এ ভাঙে
            'lines.*.qty' => ['nullable', 'required_with:lines.*.product_id', 'decimal:0,4', 'gt:0', 'max:999999999'],
            'lines.*.value' => ['nullable', 'required_with:lines.*.product_id', 'decimal:0,4', 'min:0', 'max:99999999999999'],
        ]);

        // ফাঁকা সারি ফেলে দেওয়া — ফর্মে পাঁচটা ঘর, ভরা হয় যতগুলো দরকার
        $lines = collect($data['lines'] ?? [])
            ->filter(fn ($line) => filled($line['product_id'] ?? null))
            ->map(fn ($line) => [
                'product_id' => (int) $line['product_id'],
                'qty' => (string) $line['qty'],
                'value' => (string) $line['value'],
            ])
            ->values()
            ->all();

        unset($data['lines']);

        return [$data, $lines];
    }

    /** @return array<string, mixed> */
    private function choices(Request $request, ?Opportunity $current = null): array
    {
        $user = $request->user();

        // ⓘ সম্পাদনায় নিজের লিডটা তালিকায় থাকুক, যদিও সেটা এখন হারানো বা অন্যের
        $ownLead = $current?->lead_id !== null && $current->customer_id === null
            ? Lead::query()->find($current->lead_id)
            : null;

        return [
            'stages' => $this->opportunities->stages()
                // বন্ধ করা ধাপে বসে থাকা সুযোগ সম্পাদনায় ধাপটা হারায় না
                ->when($current?->stage && ! $current->stage->is_active, fn ($c) => $c->push($current->stage))
                ->mapWithKeys(fn ($s) => [$s->id => $s->name().' ('.$s->probability.'%)']),
            'customers' => Customer::query()->active()->inViewedBranch()->orderBy('name_en')->get()
                ->mapWithKeys(fn (Customer $c) => [$c->id => $c->code.' — '.$c->name()]),

            // ⛔ কেবল নিজের দেখার ভিতরের লিড, আর যেগুলো এখনো লিড
            'leads' => Lead::query()->visibleTo($user)
                ->whereNotIn('status', [Lead::LOST, Lead::CONVERTED])
                ->orderBy('name')->get()
                ->when($ownLead, fn ($c) => $c->push($ownLead))
                ->mapWithKeys(fn (Lead $l) => [$l->id => $l->document_no.' — '.$l->name]),
            'products' => Product::query()->active()->orderBy('name_en')->get()
                ->mapWithKeys(fn (Product $p) => [$p->id => $p->code.' — '.$p->name()]),
            'salespeople' => $user->can('sales.opportunity.manage')
                ? $this->opportunities->salespeople()->mapWithKeys(fn ($u) => [$u->id => $u->name])
                : collect(),
            'lineRows' => self::LINE_ROWS,
        ];
    }
}
