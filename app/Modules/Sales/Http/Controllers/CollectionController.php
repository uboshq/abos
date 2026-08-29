<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ProcessBand;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Http\Requests\CollectionRequest;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * আদায় — পর্দা।
 */
class CollectionController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly CollectionService $service,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Collection::class, 'collection'),
            new Middleware('can:sales.collection.create', only: ['confirm']),
            new Middleware('can:sales.collection.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Collection::query()
            ->search($request->query('q'))
            ->with(['customer', 'account'])
            // বাতিলগুলো লুকানো, মোছা নয় (নিয়ম ৫)
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        // ধাপের পটির হিসাব অবস্থার ছাঁকনির আগে — কারণটা
        // [[ProcessBand::forStatuses()]]-এ লেখা
        $bandBase = clone $query;

        $stage = (string) $request->query('stage', '');

        if (in_array($stage, DocumentStatus::ALL, true)) {
            $query->where('status', $stage);
        } else {
            $stage = '';
        }

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('amount'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        return view('sales::collection.index', [
            'menu' => $this->menu->forUser($request->user()),
            'collections' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
            'stage' => $stage,
            'processBand' => ProcessBand::forStatuses(
                $bandBase,
                [
                    ['status' => DocumentStatus::DRAFT, 'label' => __('core.status.draft')],
                    ['status' => DocumentStatus::CONFIRMED, 'label' => __('core.status.confirmed')],
                    ['status' => DocumentStatus::CLOSED, 'label' => __('core.status.closed')],
                ],
                'sales.collection.index',
                $request->except(['stage', 'page']),
                $stage !== '' ? $stage : null,
                /*
                 * আদায়ের টাকার কলামটা `amount`, `total` নয়।
                 *
                 * ── কী ভাঙল, ২৯ আগস্ট ২০২৬ ───────────────────────────
                 * ডিফল্ট `total` ধরে নেওয়ায় লাইভে `/sales/collections`
                 * ৫০০ দিচ্ছিল — "Unknown column 'total'"। বাকি তিনটা
                 * কাগজে `total` আছে বলে ওখানে ধরা পড়েনি।
                 *
                 * সুইট এটা ধরেছিল ([[ModuleMenuTest]] — "মেনুতে দেখা
                 * যাচ্ছে, ক্লিক করলে ভাঙে"), কিন্তু আমি সুইট চালানোর
                 * **আগেই** ডিপ্লয় করে ফেলেছিলাম। পাহারাটা কাজ করেছে;
                 * আমি ওটাকে কাজ করার সুযোগ দিইনি।
                 */
                'amount',
            ),
        ]);
    }

    public function create(Request $request): View
    {
        return view('sales::collection.form', [
            'menu' => $this->menu->forUser($request->user()),
            'collection' => new Collection(['trx_date' => now()->toDateString()]),
            'invoice' => $this->chosenInvoice($request),
            ...$this->formData(),
        ]);
    }

    public function store(CollectionRequest $request): RedirectResponse
    {
        $document = $this->service->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.collection.show', $document)
            ->with('saved', __('sales::message.collection_created'));
    }

    public function show(Request $request, Collection $collection): View
    {
        $collection->load(['lines.invoice', 'customer', 'account', 'creator']);

        return view('sales::collection.show', [
            'menu' => $this->menu->forUser($request->user()),
            'collection' => $collection,
        ]);
    }

    public function edit(Request $request, Collection $collection): View
    {
        $collection->load(['lines.invoice']);

        return view('sales::collection.form', [
            'menu' => $this->menu->forUser($request->user()),
            'collection' => $collection,
            'invoice' => null,
            ...$this->formData(),
        ]);
    }

    public function update(CollectionRequest $request, Collection $collection): RedirectResponse
    {
        $this->service->update($collection, $request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.collection.show', $collection)
            ->with('saved', __('sales::message.collection_updated'));
    }

    public function confirm(Collection $collection): RedirectResponse
    {
        $this->service->confirm($collection);

        return redirect()
            ->route('sales.collection.show', $collection)
            ->with('saved', __('sales::message.collection_confirmed'));
    }

    public function cancel(Request $request, Collection $collection): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->service->cancel($collection, $reason);

        return redirect()
            ->route('sales.collection.show', $collection)
            ->with('saved', __('sales::message.collection_cancelled'));
    }

    /** বিল ধরে খোলা হলে ওই বিলের বকেয়াটাই আগে থেকে বসে। */
    private function chosenInvoice(Request $request): ?SalesInvoice
    {
        $id = $request->integer('sales_invoice_id');

        if ($id <= 0) {
            return null;
        }

        return SalesInvoice::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with('customer')
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'customers' => Customer::query()->active()->orderBy('name_en')->get(),
            'accounts' => Account::query()
                ->whereIn('code', [StandardChart::CASH_IN_HAND,
                    StandardChart::BANK_AND_MFS])
                ->orWhereIn('parent_id', Account::query()
                    ->whereIn('code', [StandardChart::CASH_IN_HAND,
                        StandardChart::BANK_AND_MFS])->select('id'))
                ->orderBy('code')->get(),
            // withCollected — পর্দায় প্রতিটা বিলের পাশে বাকি টাকা লেখা
            // থাকে, আর সেটা বিলপ্রতি একটা করে যোগফল চালাত
            'openInvoices' => SalesInvoice::query()->where('status', DocumentStatus::CONFIRMED)
                ->with('customer')->withCollected()->orderByDesc('trx_date')->limit(200)->get(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'recent' => __('sales::sort.recent'),
            'oldest' => __('sales::sort.oldest'),
            'largest' => __('sales::sort.largest'),
            'customer' => __('sales::sort.customer'),
        ];
    }
}
