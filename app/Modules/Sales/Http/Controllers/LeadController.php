<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * লিড — NEXUS স্পেক §৭, ডিপোর মাপে ছোট।
 *
 * ── চাবি দুইটা ──────────────────────────────────────────────────────────
 *   sales.lead.view    নিজের লিড দেখা, লেখা, বদলানো
 *   sales.lead.manage  সবার লিড দেখা, মালিক বদলানো, গ্রাহক বানানো
 *
 * ⓘ গ্রাহক বানাতে `customer.create`-ও লাগে — লিডের চাবি গ্রাহক তালিকার
 * দরজা খোলে না।
 *
 * ⚠️ প্রতিটা পদ্ধতি লিড খোঁজে [[LeadService::find()]] দিয়ে, রুট-বাঁধন
 * দিয়ে নয়: রুট-বাঁধন কেবল কোম্পানির দেয়াল জানে, বিক্রয়কর্মীর নয়।
 */
class LeadController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.lead.view', only: ['index', 'create', 'store', 'show', 'edit', 'update']),
            new Middleware('can:sales.lead.manage', only: ['convert']),
            new Middleware('can:customer.create', only: ['convert']),
        ];
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $query = $this->leads->visibleTo($user)
            ->with(['owner', 'location'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->query('q'))).'%';

                $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('document_no', 'like', $like));
            })
            ->when(in_array($request->query('status'), Lead::STATUSES, true),
                fn ($q) => $q->where('status', $request->query('status')))
            ->latest('id');

        return view('sales::crm.lead.index', [
            'menu' => $this->menu->forUser($user),
            'leads' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'status' => $request->query('status'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('sales::crm.lead.form', [
            'menu' => $this->menu->forUser($request->user()),
            'lead' => new Lead(['source' => 'field_visit', 'status' => Lead::NEW]),
            ...$this->choices($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $lead = $this->leads->create($this->validated($request), $request->user());

        return redirect()
            ->route('sales.lead.show', $lead->id)
            ->with('saved', __('sales::crm.lead_saved'));
    }

    public function show(Request $request, int $lead): View
    {
        $user = $request->user();
        $record = $this->leads->find($lead, $user);

        return view('sales::crm.lead.show', [
            'menu' => $this->menu->forUser($user),
            'lead' => $record->load(['owner', 'location', 'customer', 'converter']),
            'opportunities' => $record->opportunities()->visibleTo($user)->with('stage')->latest('id')->get(),
            'locations' => $this->locations(),
            'partyTypes' => PartyType::query()->active()->orderBy('code')->get()
                ->mapWithKeys(fn (PartyType $t) => [$t->id => $t->name()]),
        ]);
    }

    public function edit(Request $request, int $lead): View
    {
        $record = $this->leads->find($lead, $request->user());

        abort_if($record->isConverted(), 404);

        return view('sales::crm.lead.form', [
            'menu' => $this->menu->forUser($request->user()),
            'lead' => $record,
            ...$this->choices($request),
        ]);
    }

    public function update(Request $request, int $lead): RedirectResponse
    {
        $record = $this->leads->find($lead, $request->user());

        $this->leads->update($record, $this->validated($request), $request->user());

        return redirect()
            ->route('sales.lead.show', $record->id)
            ->with('saved', __('sales::crm.lead_saved'));
    }

    /**
     * ⭐ গ্রাহক বানানো — ফর্মটা লিডের পাতায়, কারণ লিডের তথ্য চোখের সামনে রেখেই
     * ইংরেজি নাম আর ধরন ঠিক করা হয়।
     */
    public function convert(Request $request, int $lead): RedirectResponse
    {
        $record = $this->leads->find($lead, $request->user());

        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:191'],
            'name_bn' => ['nullable', 'string', 'max:191'],
            'location_id' => ['nullable', 'integer',
                Rule::exists('mdm_locations', 'id')->where('company_id', CompanyContext::id())],
            'party_type_id' => ['nullable', 'integer',
                Rule::exists('mdm_party_types', 'id')->where('company_id', CompanyContext::id())],
            'allow_duplicate' => ['nullable', 'boolean'],
        ]);

        $customer = $this->leads->convert($record, $data, $request->user());

        return redirect()
            ->route('sales.lead.show', $record->id)
            ->with('saved', __('sales::crm.converted', ['code' => $customer->code]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'contact_person' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],

            // exists-এ company_id — ভ্যালিডেটরের কাঁচা কোয়েরিতে গ্লোবাল স্কোপ খাটে না
            'location_id' => ['nullable', 'integer',
                Rule::exists('mdm_locations', 'id')->where('company_id', CompanyContext::id())],
            'source' => ['required', Rule::in(Lead::SOURCES)],
            'owner_user_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Lead::SETTABLE)],
            'lost_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function choices(Request $request): array
    {
        return [
            'locations' => $this->locations(),
            'owners' => $request->user()->can('sales.lead.manage')
                ? $this->leads->owners()->mapWithKeys(fn ($u) => [$u->id => $u->name])
                : collect(),
        ];
    }

    private function locations()
    {
        return Location::query()->active()->orderBy('name_en')->get()
            ->mapWithKeys(fn (Location $l) => [$l->id => $l->label()]);
    }
}
