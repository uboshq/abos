<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\GrandTotals;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\Sales\Http\Requests\SalesOrderRequest;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\OrderProgress;
use App\Modules\Sales\Services\OrderTracking;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SellableStock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বিক্রয় আদেশ — পর্দা।
 */
class SalesOrderController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use GrandTotals;
    use SortsLists;

    public function __construct(
        private readonly SalesOrderService $service,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(SalesOrder::class, 'order'),
            // ⓘ ট্র্যাকিং পাতাটা কেবল পড়ার — আদেশ দেখার অনুমতিই যথেষ্ট
            new Middleware('can:sales.order.view', only: ['track']),
            new Middleware('can:sales.order.update', only: ['confirm']),
            new Middleware('can:sales.order.cancel', only: ['cancel']),
            // ⭐ বন্ধ — নিজের চাবি (মালিক, ৪ অক্টোবর ২০২৬; [[SalesOrderService::close()]])
            new Middleware('can:sales.order.close', only: ['close']),
        ];
    }

    /**
     * আদেশ কোথায় দাঁড়িয়ে — আদেশ · আংশিক · মাল গেছে · বিল হয়েছে।
     *
     * ── ⭐ মালিকের চাওয়া, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * *"এখানে Order Tracking-এর ব্যবস্থা করতে হবে… অ্যাপ ১০০% হওয়ার পর
     * সব customer তার নিজের, employee তার অধীনের সকল order ট্রেস করতে
     * পারবে।"*
     *
     * ⓘ আজ পাতাটা কর্মীদের — সব আদেশ, আর প্রতিটার ধাপ। ⚠️ গ্রাহকের
     * নিজের আদেশ পরের ধাপ, কিন্তু আজকের পাতাটা সত্যিকারের কাজ করে:
     * "আসছে" লেখা খালি বোতাম বসানো এখানে নিয়মবিরুদ্ধ।
     *
     * ⓘ হিসাবটা [[OrderTracking]]-এ, আর সেখানেই লেখা কেন সাব-কোয়েরি।
     */
    public function track(Request $request, OrderTracking $tracking): View
    {
        $term = trim((string) $request->query('q')) ?: null;
        $stage = in_array($request->query('stage'), OrderTracking::STAGES, true)
            ? (string) $request->query('stage')
            : 'all';

        // ⓘ গ্রাহকের পাতা থেকে এলে কেবল তাঁর আদেশ — সংখ্যা আর তালিকা এক কথা বলে
        $customerId = (int) $request->query('customer') ?: null;

        return view('sales::order.track', [
            'menu' => $this->menu->forUser($request->user()),
            'orders' => $tracking->rows($term, $stage, $customerId),
            'counts' => $tracking->counts($term, $customerId),
            'tracking' => $tracking,
            'stage' => $stage,
            'customer' => $customerId === null ? null : Customer::query()->find($customerId),
        ]);
    }

    public function index(Request $request, OrderTracking $tracking): View
    {
        /*
         * ⭐ ট্যাব — অপেক্ষমাণ, আংশিক, ব্যাক অর্ডার, ইতিহাস এক তালিকায় (নকশার পর্যালোচনা, ধাপ ৭-এর ২,
         * ১ অক্টোবর ২০২৬)। ⓘ অচেনা বা চাবিহীন ট্যাব মানে "সব" — ভুল ঠিকানায় খালি পাতা নয়।
         */
        $tab = (string) $request->query('tab', OrderTracking::LIST_ALL);
        $tab = isset(OrderTracking::LIST_TABS[$tab])
            && ($request->user()?->can(OrderTracking::LIST_TABS[$tab]['permission']) ?? false)
            ? $tab : OrderTracking::LIST_ALL;

        $base = SalesOrder::query()->search($request->query('q'));
        $dates = $this->applyDateRange($base, $request);

        // ⓘ গোনা খোঁজা আর তারিখ মানে — নাহলে ট্যাব বলত "১২০" আর খুললে দেখাত "৩"
        $counts = $tracking->listCounts(clone $base);

        // বাতিলগুলো লুকানো, মোছা নয় (নিয়ম ৫) — "সব" ট্যাবে চাইলে দেখা যায়
        $query = $tracking->applyListTab(
            (clone $base)->with(['customer.location', 'warehouse']),
            $tab,
            $request->boolean('cancelled'),
        );

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        return view('sales::order.index', [
            'menu' => $this->menu->forUser($request->user()),
            'grand' => $this->grandTotals($query, ['total' => 't.total']),
            'orders' => $orders = $query->paginate(50)->withQueryString(),
            // ⭐ প্রতিটা আদেশের অবস্থা ও অগ্রগতি — পাতার পঞ্চাশটার জন্য একবারে ([[OrderProgress::compute()]])
            'states' => app(OrderProgress::class)->compute($orders->getCollection()),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
            'tab' => $tab,
            'tabs' => $this->listTabs($request, $tab, $counts),
        ]);
    }

    /**
     * তালিকার ওপরের ট্যাব — যে ট্যাব খোলার চাবি নেই সে আঁকা হয় না (চাপলে ৪০৩-এর চেয়ে না দেখানো ভালো)।
     *
     * ⓘ ঠিকানায় বাকি ছাঁকনি (খোঁজা, তারিখ, সাজানো) থাকে; পাতা নম্বর যায় না, নতুন ট্যাব প্রথম পাতা থেকে।
     *
     * @param  array<string, int>  $counts
     * @return list<array{key: string, label: string, hint: string, url: string, count: int, active: bool}>
     */
    private function listTabs(Request $request, string $active, array $counts): array
    {
        $keep = $request->except(['tab', 'page']);
        $tabs = [];

        foreach (OrderTracking::LIST_TABS as $key => $tab) {
            if (! ($request->user()?->can($tab['permission']) ?? false)) {
                continue;
            }

            $tabs[] = [
                'key' => $key,
                'label' => __($tab['label']),
                'hint' => __($tab['hint']),
                'url' => route('sales.order.index', $key === OrderTracking::LIST_ALL ? $keep : [...$keep, 'tab' => $key]),
                'count' => $counts[$key] ?? 0,
                'active' => $key === $active,
            ];
        }

        return $tabs;
    }

    public function create(Request $request): View
    {
        return view('sales::order.form', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => new SalesOrder(['trx_date' => now()->toDateString()]),
            ...$this->formData(),
        ]);
    }

    public function store(SalesOrderRequest $request): RedirectResponse
    {
        $document = $this->service->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.order.show', $document)
            ->with('saved', __('sales::message.order_created'));
    }

    public function show(Request $request, SalesOrder $order): View
    {
        $order->load(['lines.product.unit', 'customer', 'warehouse', 'challans', 'creator', 'quotation']);

        $order->loadMissing('closer');

        return view('sales::order.show', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => $order,
            // ⭐ মাথার আর প্রতি লাইনের অবস্থা ও অগ্রগতি ([[OrderProgress]], মালিক, ৪ অক্টোবর ২০২৬)
            'status' => app(OrderProgress::class)->of($order),
        ]);
    }

    public function edit(Request $request, SalesOrder $order): View
    {
        $order->load(['lines.product']);

        return view('sales::order.form', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => $order,
            ...$this->formData(),
        ]);
    }

    public function update(SalesOrderRequest $request, SalesOrder $order): RedirectResponse
    {
        $this->service->update($order, $request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.order.show', $order)
            ->with('saved', __('sales::message.order_updated'));
    }

    public function confirm(SalesOrder $order): RedirectResponse
    {
        $this->service->confirm($order);

        return redirect()
            ->route('sales.order.show', $order)
            ->with('saved', __('sales::message.order_confirmed'));
    }

    public function cancel(Request $request, SalesOrder $order): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->service->cancel($order, $reason);

        return redirect()
            ->route('sales.order.show', $order)
            ->with('saved', __('sales::message.order_cancelled'));
    }

    /**
     * ⭐ আদেশ বন্ধ — পুরো বিলের পরে কারণ ছাড়া, কম রেখে কারণসহ ([[SalesOrderService::close()]])।
     */
    public function close(Request $request, SalesOrder $order): RedirectResponse
    {
        // ⛔ বন্ধের নিজের চাবি — middleware()-এর পাশাপাশি পদ্ধতির ভিতরেও, যাতে দরজা কোনো পথেই খোলা না থাকে
        $this->authorize('sales.order.close');

        // ⓘ ঘরের নাম পর্দার ভাষায় — বার্তায় ডাটাবেসের কলাম ("close reason") নয়
        $reason = $request->validate(
            ['close_reason' => ['nullable', 'string', 'max:500']],
            [],
            ['close_reason' => __('sales::order_status.field_close_reason')],
        )['close_reason'] ?? null;

        $this->service->close($order, $reason);

        return redirect()
            ->route('sales.order.show', $order)
            ->with('saved', __('sales::order_status.closed_flash'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        /*
         * ⚠️ `withOutstanding()` — নিচের `customerTerms`-এর জন্য, আর
         * কারণটা গোনার। প্রতিটা ক্রেতার বকেয়া ওখানে চাওয়া হয়, আর
         * স্কোপটা না দিলে `outstanding()` নিজে খাতা খুঁজত — ক্রেতাপ্রতি
         * একটা কোয়েরি। ⓘ ছয়জনের ডেমোতে চোখে পড়ে না, তিন হাজার
         * ক্রেতার ডিপোতে পাতা খোলা মানেই তিন হাজার কোয়েরি।
         */
        $customers = Customer::query()->inViewedBranch()->active()->withOutstanding()->orderBy('name_en')->get();
        $products = Product::query()->soldInViewedBranch()->active()->with('unit')->orderBy('name_en')->get();

        return [
            'customers' => $customers,
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'products' => $products,

            /*
             * ⭐ ক্রেতার খাতা — ২১ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশে।
             *
             * ⓘ পুরো তালিকাটা পাতার সাথেই একবার যায়, তাই ক্রেতা বদলালে
             * নতুন কোনো অনুরোধ লাগে না — কাউন্টারের পর্দা ঠিক এভাবেই করে।
             */
            /*
             * ⚠️ অঙ্কগুলো **স্ট্রিং** হয়ে যায়, float হয়ে নয়।
             *
             * ⛔ প্রথমে `(float)` লেখা হয়েছিল, আর [[MoneyIsNeverAFloatTest]]
             * সেটা ধরে ফেলল — ঠিক কাজ করেছে। ⓘ পর্দায় সংখ্যাটা কেবল
             * দেখানো হয় (`x-text`), তাই float বানানোর কোনো দরকারই নেই,
             * অথচ ১২৩৪.৫৬ একদিন ১২৩৪.৫৬০০০০০০০১ হয়ে ফুটে উঠতে পারত।
             *
             * ⓘ সীমা ছাড়ানোর তুলনাটা ব্রাউজারে `parseFloat` দিয়ে হয়, আর
             * সেখানে স্ট্রিং দিলেও একই উত্তর।
             */
            'customerTerms' => $customers->mapWithKeys(fn (Customer $c) => [$c->id => [
                /*
                 * ⓘ দুইটা ঘর, দুইটা কাজ। কাঁচা মানটা তুলনার জন্য (সীমা
                 * ছাড়িয়েছে কি না), আর লেখাটা দেখানোর জন্য।
                 *
                 * ⚠️ একটাই ঘর রাখলে হয় পর্দায় `5000.0000` ফুটত (ঘরটা
                 * `decimal:4`), নয় তুলনায় `12,31,87,500.00`-র কমাগুলো
                 * `parseFloat` ভেঙে দিত।
                 */
                'limit' => (string) $c->credit_limit,
                'due' => (string) $c->outstanding(),
                'limit_text' => Money::format($c->credit_limit),
                'due_text' => Money::format($c->outstanding()),
            ]])->all(),

            /*
             * ⭐ বারকোড → পণ্য।
             *
             * ⚠️ বারকোড ছাড়া পণ্যগুলো বাদ, নাহলে একটা ফাঁকা চাবি সব
             * বারকোডহীন পণ্যের একটাকে ধরে বসত।
             */
            'barcodes' => $products
                ->filter(fn (Product $p) => (string) $p->barcode !== '')
                ->mapWithKeys(fn (Product $p) => [(string) $p->barcode => (string) $p->id])
                ->all(),

            /*
             * ⭐ প্যাকের বারকোড → পণ্য ও পরিমাণ — ধাপ ৬, ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ বারকোডগুলো সংরক্ষিত হত, কিন্তু কোনো পর্দা ওগুলো
             * খুঁজত না — স্ক্যানার কেবল পণ্যের নিজেরটা মিলাত।
             *
             * ⓘ আলাদা চাবি, এক মানচিত্রে মিশিয়ে নয়: পণ্যের
             * বারকোডে পরিমাণ সবসময় ১, আর প্যাকেরটাতে `factor`।
             * ⚠️ মিশালে একটাই মানচিত্রে দুই রকম মান বসত, আর JS-এ
             * কেন একটায় সংখ্যা আর অন্যটায় বস্তু তা বোঝা যেত না।
             */
            'packBarcodes' => app(PackConversion::class)->barcodesFor($products),

            /*
             * ⭐ কতটা বেচা যায় — কাউন্টারের **একই** সূত্রে।
             *
             * ⛔ এখানে আলাদা করে গুনলে একই পণ্যের পাশে দুই পর্দায় দুইটা
             * সংখ্যা বসত ([[SellableStock]]-এ কারণ লেখা)।
             *
             * ⓘ গুদাম ধরা হয় না: অর্ডারের ফর্মে গুদামের ঘরটা বদলানো যায়,
             * আর পাতাটা তখন নতুন করে আসে না। ⚠️ তাই সংখ্যাটা **সব গুদাম
             * মিলিয়ে** — একটা ইঙ্গিত হিসেবে সেটাই সৎ, কারণ একটা নির্দিষ্ট
             * গুদামের সংখ্যা দেখিয়ে সেটা বাসি রাখা আরও খারাপ।
             */
            'stock' => app(SellableStock::class)->byProduct(),
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
