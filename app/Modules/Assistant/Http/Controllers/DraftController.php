<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyMessages;
use App\Core\Services\SmsOutboxService;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\AssistantEvent;
use App\Models\SmsOutbox;
use App\Models\User;
use App\Modules\Assistant\Watch\Suggestions;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Dashboard\InventoryBoard;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Services\PurchaseRequisitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ কোপাইলটের খসড়া — প্রস্তাব থেকে কাগজ বা বার্তা, কিন্তু পাঠান মানুষ (পূর্ণ পরিকল্পনা অংশ ১ ও ৭, ধাপ ৩)।
 *
 * ⓘ ক্রয় চাহিদা: পুনঃক্রয় তালিকা থেকে খসড়া (DRAFT) — কোপাইলট বানায়, মানুষ দেখে বদলান, আর সাধারণ পথেই অনুমোদনে পাঠান
 *   ([[PurchaseRequisitionService::create()]] — একই সেবা, একই নম্বর, একই নিয়ম; কোপাইলটের আলাদা পথ নেই)।
 * ⓘ তাগাদার SMS: কাকে ফোনের তালিকা থেকে লেখা তৈরি, প্রতিটা আলাদা টিক — মানুষ টিক দিয়ে "পাঠান" চাপলে তবেই অফিসের SMS ফোনের সারিতে।
 * ⛔ কোপাইলট নিজে কিছু পাঠায় না, খাতায় তোলে না। প্রতিটা খসড়া আর পাঠানো খাতায় ওঠে।
 */
class DraftController extends Controller
{
    public function __construct(
        private readonly AssistantEngine $engine,
        private readonly MenuBuilder $menu,
    ) {}

    /** ⭐ পুনঃক্রয় তালিকা → ক্রয় চাহিদার খসড়া; তারপর চাহিদার নিজের পাতা — দেখে বদলে পাঠানোর জন্য */
    public function reorder(Request $request, PurchaseRequisitionService $requisitions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->engine->on('draft'), 404);

        $rows = collect(app(InventoryBoard::class)->reorder(20)['rows'])
            ->filter(fn (array $r): bool => is_numeric($r['sug']) && bccomp((string) $r['sug'], '0', 4) > 0)->values();

        if ($rows->isEmpty()) {
            return redirect()->route('assistant.today')->with('saved', __('assistant::draft.nothing'));
        }

        $prices = Product::query()->whereIn('id', $rows->pluck('id')->all())->pluck('purchase_price', 'id');
        $requisition = $requisitions->create([
            'purpose' => (string) __('assistant::draft.reorder_purpose', ['name' => $this->engine->name()]),
            'needed_by' => now()->addDays(7)->toDateString(),
        ], $rows->map(fn (array $r): array => [
            'product_id' => (int) $r['id'], 'qty' => (string) $r['sug'], 'estimated_rate' => (string) ($prices[$r['id']] ?? '0'),
            'narration' => (string) __('assistant::draft.reorder_line', ['have' => $r['have'], 'days' => $r['days']]),
        ])->all());

        $this->engine->record(AssistantEvent::DRAFT, $user, (string) __('assistant::draft.reorder_log', ['n' => $rows->count()]), [
            'module' => 'purchase', 'outcome' => (string) $requisition->document_no,
        ]);

        return redirect()->route('purchase.requisition.show', $requisition)->with('saved', __('assistant::draft.reorder_done', ['no' => $requisition->document_no]));
    }

    /** ⭐ তাগাদার SMS — খসড়াগুলো দেখা, টিক দেওয়া */
    public function dunning(Request $request, Suggestions $suggestions, SmsOutboxService $outbox): View
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->engine->on('draft'), 404);

        return view('assistant::dunning', [
            'menu' => $this->menu->forUser($user),
            'name' => $this->engine->name(),
            'drafts' => $this->dunningDrafts(),
            'phoneReady' => $outbox->hasPhone(),
        ]);
    }

    /** ⭐ টিক দেওয়াগুলো পাঠানো — লেখা আবার সার্ভারে বানানো (পাতা থেকে নেওয়া নয়), তারপর অফিসের ফোনের সারিতে */
    public function sendDunning(Request $request, SmsOutboxService $outbox): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->engine->on('draft'), 404);

        $data = $request->validate(['customers' => ['required', 'array', 'max:50'], 'customers.*' => ['integer']]);

        if (! $outbox->hasPhone()) {
            throw ValidationException::withMessages(['customers' => __('notification::sms.no_gateway')]);
        }

        $drafts = collect($this->dunningDrafts())->keyBy('customer_id');
        $sent = 0;

        foreach (array_unique($data['customers']) as $id) {
            $d = $drafts->get((int) $id);

            if ($d === null || $d['phone'] === null) {
                continue;
            }

            $row = $outbox->queue($d['phone'], $d['text'], SmsOutbox::PARTY);
            $this->engine->record(AssistantEvent::DRAFT, $user, $d['text'], ['module' => 'credit_control', 'outcome' => 'sms:'.$row->public_id, 'page' => $d['name']]);
            $sent++;
        }

        return redirect()->route('assistant.draft.dunning')->with('saved', __('assistant::draft.dunning_sent', ['n' => $sent]));
    }

    /**
     * কাকে ফোনের তালিকা → প্রতিজনের তাগাদার লেখা (বাংলা), নম্বরসহ।
     *
     * @return list<array{customer_id: int, name: string, phone: ?string, amount: string, days: int, text: string}>
     */
    private function dunningDrafts(): array
    {
        $customers = Customer::query()->where('is_active', true)->get()->keyBy('id')->all();
        $facts = app(\App\Modules\CreditControl\Services\CreditRisk::class)->factsFor($customers);
        $company = \App\Models\Company::query()->find(\App\Core\Support\CompanyContext::id());
        $from = (string) ($company?->name_bn ?: $company?->name_en ?: '');

        return collect($facts)->filter(fn (array $f): bool => bccomp((string) $f['overdue'], '0', 4) > 0)
            ->sortByDesc(fn (array $f): float => (float) $f['overdue'])->take(Suggestions::CALL_LIST)
            ->map(function (array $f, int $id) use ($customers, $from): array {
                $c = $customers[$id];

                return [
                    'customer_id' => $id,
                    'name' => $c->name(),
                    'phone' => PartyMessages::phone((string) ($c->phone ?? '')),
                    'amount' => Money::format((string) $f['overdue'], 0),
                    'days' => (int) $f['oldest'],
                    'text' => (string) __('assistant::draft.dunning_text', ['party' => $c->name(), 'amount' => Money::format((string) $f['overdue'], 0),
                        'days' => (int) $f['oldest'], 'company' => $from]),
                ];
            })->values()->all();
    }
}
