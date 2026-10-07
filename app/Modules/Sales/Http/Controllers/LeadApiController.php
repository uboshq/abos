<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * ⭐ ফোনে লিড — নতুন দোকানের খোঁজ মাঠ থেকেই লেখা (সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)।
 *
 * ⓘ ওয়েবের [[LeadController]]-এর একই চাবি (`sales.lead.view`), একই সেবা ([[LeadService]]) আর একই নিয়ম — কে কোনটা দেখেন
 * ([[LeadService::visibleTo()]]), মালিক কে হন, কোন অবস্থা হাতে বসানো যায়। এখানে কেবল অনুবাদ।
 * ⓘ লিড থেকে গ্রাহক বানানো ফোনে নয় — ইংরেজি নাম, ধরন আর এলাকা ঠিক করতে হয়, সেটা ওয়েবের লিডের পাতায়।
 * ⓘ সব আইডি `public_id`; অন্যের লিড খোঁজাতেই নেই — ৪০৪ (ওয়েবের একই নিয়ম)।
 */
class LeadApiController extends Controller implements HasMiddleware
{
    public function __construct(private readonly LeadService $leads) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.lead.view')];
    }

    /** `GET /leads/setup` — উৎস আর হাতে বসানো যায় এমন অবস্থা, পর্দার ভাষায় */
    public function setup(): JsonResponse
    {
        return response()->json([
            'sources' => array_map(fn (string $s) => ['key' => $s, 'label' => __('sales::crm.source.'.$s)], Lead::SOURCES),
            'statuses' => array_map(fn (string $s) => ['key' => $s, 'label' => __('sales::crm.lead_status.'.$s)], Lead::SETTABLE),
        ]);
    }

    /** `GET /leads?q=&status=&page=` — নতুনটা আগে, পাতায় ৫০ */
    public function index(Request $request): JsonResponse
    {
        $rows = $this->leads->visibleTo($request->user())
            ->with('owner')
            ->when(trim(PhoneInput::text($request, 'q', '')), function ($q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('document_no', 'like', $like));
            })
            ->when(in_array($request->query('status'), Lead::STATUSES, true),
                fn ($q) => $q->where('status', PhoneInput::text($request, 'status')))
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'leads' => collect($rows->items())->map(fn (Lead $l) => $this->facts($l))->values(),
            'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->facts($this->lead($request, $id)->load('owner')));
    }

    /** `POST /leads` — মালিক লেখক নিজে, যদি না ব্যবস্থাপক অন্য কাউকে দেন ([[LeadService::create()]]) */
    public function store(Request $request): JsonResponse
    {
        $lead = $this->leads->create($this->validated($request), $request->user());

        return response()->json($this->facts($lead->load('owner')), 201);
    }

    /** `PUT /leads/{id}` — অবস্থা, কারণ, টুকিটাকি; গ্রাহক হয়ে গেলে আর নয় (সেবার নিয়ম) */
    public function update(Request $request, string $id): JsonResponse
    {
        $lead = $this->leads->update($this->lead($request, $id), $this->validated($request), $request->user());

        return response()->json($this->facts($lead->load('owner')));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, mixed> — ওয়েবের ফর্মের হুবহু নিয়ম, এলাকা আর মালিক বাদে (ফোনে বাছাই নেই, মালিক লেখক নিজে) */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'contact_person' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'source' => ['required', Rule::in(Lead::SOURCES)],
            'status' => ['nullable', Rule::in(Lead::SETTABLE)],
            'lost_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function lead(Request $request, string $id): Lead
    {
        return $this->leads->visibleTo($request->user())->where('public_id', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function facts(Lead $l): array
    {
        return [
            'id' => (string) $l->public_id,
            'no' => (string) $l->document_no,
            'name' => (string) $l->name,
            'contact_person' => $l->contact_person,
            'phone' => $l->phone,
            'address' => $l->address,
            'source' => (string) $l->source,
            'source_label' => __('sales::crm.source.'.$l->source),
            'status' => (string) $l->status,
            'status_label' => __('sales::crm.lead_status.'.$l->status),
            'lost_reason' => $l->lost_reason,
            'notes' => $l->notes,
            'owner' => $l->owner?->name,
            'converted' => $l->status === Lead::CONVERTED,
        ];
    }
}
