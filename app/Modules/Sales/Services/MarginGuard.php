<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\RecipeBook;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Models\Approval;
use Illuminate\Contracts\Auth\Authenticatable;
use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\ApprovalFlow;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * মার্জিনের দেয়াল — খরচের নিচে বিক্রি যেন নীরবে পার না হয় (NEXUS §৩২)।
 *
 * ── ⭐ কেন, মালিকের অঙ্কে ─────────────────────────────────────────────
 * ডিপোর মার্জিন **৩.৮২%**। ⓘ অর্থাৎ ১০০ টাকার বিক্রিতে পৌনে চার টাকা
 * থাকে — একটা সারিতে ৪% ছাড় দিলেই ঐ সারির পুরো লাভ শেষ, আর আজ পর্যন্ত
 * কোনো পাহারা সেটা দেখত না: দামের নীতি ([[PricingRule]]) মাপে **মান
 * দাম**, খরচ নয়। ⛔ মান দামের ঠিক উপরে থেকেও খরচের নিচে বেচা যেত, যদি
 * মালটা দামি চালানে ঢুকে থাকে।
 *
 * ── ⓘ খরচ কোথা থেকে — নতুন কোনো উৎস নয় ──────────────────────────────
 * বিলের COGS আসে FIFO স্তর থেকে ([[SalesInvoiceService::takeCostFromLayers()]]
 * → [[CostLayerService::issue()]])। ⭐ এখানে ঠিক সেই স্তরগুলোই, সেই একই
 * ক্রমে (`CostLayer::open()`) হেঁটে দেখা হয় — কেবল **কিছু না লিখে**।
 * ⛔ পণ্য-মাস্টারের ক্রয়মূল্য ব্যবহার করা হয়নি: ৭ আগস্টের ভুলটাই ছিল
 * মাল ঢোকে এক দামে, বেরোয় আরেক দামে (docs/Finding — Inventory is valued
 * two different ways.md)।
 *
 * ⚠️ তাই এটা **আনুমানিক** মার্জিন: দেয়াল আর আসল টানের মাঝে অন্য কোনো
 * বিল আগের স্তরটা নিয়ে ফেললে আসল খরচ একটু আলাদা হতে পারে। আসল সংখ্যা
 * থাকে বিলের সারিতে (`unit_cost`), আর মার্জিন রিপোর্ট সেটাই দেখায়।
 *
 * ── ⭐ তিনটা পথ, কোম্পানি বাছে ─────────────────────────────────────────
 *   warn      বিক্রি হয়, সতর্কতা পর্দায় আসে
 *   approval  অনুমোদনের ইঞ্জিনে যায় (`sales|margin`), কাগজ খসড়া থাকে
 *   block     বিক্রি হয় না — কোন পণ্য, কত মার্জিন, সারির ঘরে বলা হয়
 *
 * ⓘ ABOS অনেক ব্যবসায় বিক্রি হয়; কার কাছে কতটা মার্জিন "কম", সেটা
 * কোম্পানিই ঠিক করে — সীমা আর পথ দুইটাই তার সেটিং।
 *
 * ── ⛔ দেয়ালটা কোথায় বসে ──────────────────────────────────────────────
 * বাকির সীমা যেখানে ([[CreditExposure::assertRoom()]]) ঠিক সেখানেই, আর
 * **অনুমোদনের আগে** — [[DocumentApproval::assertClear()]] কাগজ পাঠিয়ে থেমে
 * যায়, তার পরে বসালে প্রশ্নটা কখনো আসত না।
 */
final class MarginGuard
{
    use ReadsPackedQuantities;

    public const FLOOR = 'sales.margin.floor_percent';

    public const ACTION = 'sales.margin.action';

    public const WARN = 'warn';

    public const APPROVAL = 'approval';

    public const BLOCK = 'block';

    /** @var list<string> */
    public const ACTIONS = [self::WARN, self::APPROVAL, self::BLOCK];

    /** অনুমোদনের কাজের নাম — Sales-এর `approvals`-এ ঘোষিত। */
    public const APPROVAL_ACTION = 'margin';

    /** সতর্কতাগুলো সেশনে এই নামে — পর্দার খণ্ড ([[margin/partials/warnings]]) এটাই পড়ে। */
    public const FLASH = 'margin_warnings';

    /** যে চাবি ছাড়া খরচ বা মার্জিনের সংখ্যা কেউ দেখেন না। */
    public const COST_KEY = 'sales.cost.view';

    /**
     * এক কাগজ মাপার সময় কোন স্তর থেকে কতটা "টানা হয়ে গেছে" — পণ্য ধরে।
     *
     * ⚠️ একই পণ্য দুই সারিতে এলে দ্বিতীয় সারি প্রথমটার পরের স্তর থেকে
     * টানে, ঠিক যেমন আসল `issue()` টানে। ⛔ না রাখলে দুই সারিই সবচেয়ে
     * পুরনো (প্রায়ই সবচেয়ে সস্তা) স্তরটা দেখত।
     *
     * ⭐ স্তর ধরে, পণ্য ধরে নয় — ৪ অক্টোবর ২০২৬: লট বাছা সারি নিজের লটের স্তর থেকে টানে, তাই "আগের সারি কতটা
     * নিল" একটা সংখ্যায় আর বলা যায় না।
     *
     * @var array<int, string>  স্তরের id → এই কাগজে আগে টানা পরিমাণ
     */
    private array $drawn = [];

    /** @var array<int, list<array{id: int, batch: int|null, qty: string, cost: string}>> */
    private array $layers = [];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly DocumentApproval $approvals,
        private readonly RecipeBook $recipes,
        private readonly ApprovalEngine $engine,
    ) {}

    // ── ⛔ দেয়াল ──────────────────────────────────────────────────────────

    /**
     * ⛔ দেয়াল — চালান আর বিল নিশ্চিত করার ঠিক আগে, অনুমোদনের আগে।
     *
     * @return list<string> সতর্কতাগুলো (warn পথে, আর খরচ-অজানা সারির নোট);
     *                      আটকালে ব্যতিক্রম, ফেরত নয়
     *
     * @throws ValidationException block — বা ছক ছাড়া approval
     * @throws \App\Core\Engines\Approval\HeldForApproval approval, সইয়ের অপেক্ষায়
     */
    public function assertMargin(SalesInvoice|DeliveryChallan $document): array
    {
        $verdict = $this->judge($document);

        $notes = array_map(fn (MarginLine $l) => __('sales::margin.cost_unknown', [
            'product' => $l->productName,
        ]), $verdict->unknownLines());

        if (! $verdict->isBelow()) {
            return $this->flash($notes);
        }

        if ($verdict->action === self::WARN) {
            return $this->flash([...$this->sentences($verdict), ...$notes]);
        }

        if ($verdict->action === self::APPROVAL) {
            /*
             * ⛔ ছক না বসালে অনুমোদন মানে "চুপচাপ পার" — তাই তখন আটকানো।
             *
             * ⓘ [[ApprovalEngine::request()]] ছক না পেলে `null` ফেরায়, আর
             * `assertClear()` নীরবে ফিরে যায়। ⚠️ কোম্পানি "অনুমোদন" বেছে ছক
             * বসাতে ভুলে গেলে খরচের নিচের বিক্রি **কোনো সই ছাড়াই** পার হত
             * — ঠিক সেই নীরব ক্ষতি যেটা ঠেকাতে এই ক্লাস। ⭐ তাই বার্তাসহ
             * আটকানো হয়, আর বার্তা বলে কী বসাতে হবে।
             */
            if (! $this->flowExists($document)) {
                throw ValidationException::withMessages([
                    ...$this->lineErrors($verdict),
                    'lines' => __('sales::margin.no_flow'),
                ]);
            }

            $this->approvals->assertClear(
                document: $document,
                module: 'sales',
                action: self::APPROVAL_ACTION,
                field: 'lines',

                /*
                 * ⓘ অঙ্ক = কাগজের বিক্রয়, ঘাটতি নয়। ⚠️ ঘাটতি স্তরের দামে
                 * নড়ে — সই আর নিশ্চিত করার মাঝে অন্য বিল একটা স্তর নিলে
                 * ঘাটতি বদলাত, আর [[Approval::stillCovers()]] দেওয়া সইটা
                 * বাতিল করে নতুন সই চাইত, কাগজ একটুও না বদলালেও।
                 */
                amount: $verdict->net,
                reason: $this->reasonOf($verdict),
                fields: ['margin_percent' => $verdict->worstPercent()],
            );

            /*
             * ⓘ এখানে আসা মানে দুইটার একটা: সই হয়ে গেছে, নয়তো কোম্পানির
             * ছকের সীমা ("এত টাকার নিচে সই লাগবে না") কাগজটাকে ধরেনি।
             * ⛔ দুই ক্ষেত্রেই বিক্রিটা সীমার নিচে — তাই সতর্কতাটা যায়,
             * নাহলে ছকের সীমার নিচের বিক্রি ঠিক আগের মতোই নীরবে পার হত।
             */
            return $this->flash([...$this->sentences($verdict), ...$notes]);
        }

        throw ValidationException::withMessages($this->lineErrors($verdict));
    }

    /**
     * কাউন্টারের বিক্রয় সইয়ে যাবে কি না — কাগজ বানানোর **আগে**।
     *
     * ── ⚠️ কেন আলাদা প্রশ্ন ──────────────────────────────────────────
     * [[DirectSaleService::complete()]] চালান আর বিল **একটাই লেনদেনে** করে।
     * ⛔ ভিতরে দেয়াল সইয়ের অনুরোধ লিখে ব্যতিক্রম ছুঁড়লে লেনদেনটা অনুরোধের
     * সারিটাও মুছে ফেলত — পর্দা বলত "সইয়ের অপেক্ষায়", অথচ সইকারীর
     * তালিকায় কিছুই নেই। ⓘ তাই ডিপোজিটের সইয়ের মতোই
     * ([[DirectSaleService::counterDepositNeedsApproval()]]) প্রশ্নটা আগে,
     * আর উত্তর হ্যাঁ হলে কাগজ খসড়া থাকে ([[DirectSaleService::hold()]])
     * আর অনুরোধ বসে [[requestFor()]]-এ।
     *
     * ⓘ ছক না থাকলে `false` — তখন ভিতরের দেয়াল বার্তাসহ আটকায়, আর
     * আটকানো লেনদেন মুছে গেলে কিছুই হারায় না।
     *
     * @param  array<string, mixed>  $data  কাউন্টারের মাথা (`discount_amount`)
     * @param  list<array<string, mixed>>  $lines  কাউন্টারের সারি, পর্দা যেমন পাঠায়
     */
    public function counterSaleNeedsApproval(array $data, array $lines): bool
    {
        if ($this->action() !== self::APPROVAL) {
            return false;
        }

        $rows = [];

        foreach (array_values($lines) as $index => $line) {
            $product = Product::query()->find((int) ($line['product_id'] ?? 0));

            if ($product === null) {
                continue;
            }

            // ⓘ চালান যেভাবে নামায় ঠিক সেভাবেই — "২ বাক্স @ ৮০০" পিসে
            $pack = $this->packed(
                $product,
                $this->decimal($line['qty'] ?? '0'),
                $line['unit_id'] ?? null,
                $this->decimal($line['rate'] ?? '0'),
            );

            $gross = bcmul($pack['qty'], $pack['rate'], 4);
            $percent = $this->decimal($line['discount_percent'] ?? '0');

            $rows[] = [
                'index' => $index,
                'product' => $product,
                // ⓘ কাউন্টারে বাছা লট — বিল এই লটের স্তর থেকেই খরচ নেবে
                'batch' => ($line['batch_id'] ?? '') === '' || $line['batch_id'] === null ? null : (int) $line['batch_id'],
                'free' => $this->decimal($line['free_qty'] ?? '0'),
                'warehouse' => ($data['warehouse_id'] ?? null) === null ? null : (int) $data['warehouse_id'],
                'qty' => $pack['qty'],
                'net' => $this->withoutInclusiveVat(
                    $product,
                    bcsub($gross, bcdiv(bcmul($gross, $percent, 4), '100', 4), 4),
                ),
            ];
        }

        $verdict = $this->evaluate($rows, $this->decimal($data['discount_amount'] ?? '0'));

        if (! $verdict->isBelow()) {
            return false;
        }

        /*
         * ⭐ ছকটা সত্যিই ধরবে কি না — কেবল "ছক আছে" নয়।
         *
         * ⚠️ ছকের সীমা (যেমন "৫০ হাজারের নিচে সই লাগবে না") এই বিক্রিকে না
         * ধরলে কাগজ খসড়া রাখার কোনো কারণ নেই — ⛔ রাখলে পর্দা "সইয়ের
         * অপেক্ষায়" বলত, অথচ কারও তালিকায় কিছু যেত না। ⓘ প্রশ্নটা
         * ডিপোজিটের সইয়ের হুবহু ([[DirectSaleService::counterDepositNeedsApproval()]]),
         * আর ঘরগুলো চালান যা বহন করবে তাই: গ্রাহক, গুদাম, সবচেয়ে খারাপ মার্জিন।
         */
        return $this->engine->requires(
            'sales',
            self::APPROVAL_ACTION,
            $verdict->net,
            class_basename(DeliveryChallan::class),
            array_filter([
                'customer_id' => $data['customer_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'margin_percent' => $verdict->worstPercent(),
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }

    /**
     * রাখা খসড়া চালানের জন্য সইয়ের অনুরোধ — ব্যতিক্রম ছাড়া।
     *
     * ⓘ [[counterSaleNeedsApproval()]]-এর জোড়া: কাগজ খসড়া হয়ে বসার পর,
     * লেনদেনের **বাইরে** ডাকা হয়, তাই সারিটা থাকে। ⭐ পরে
     * [[DirectSaleService::finishHeld()]] চালান নিশ্চিত করলে দেয়াল ঐ একই
     * অনুরোধ দেখে — সই হলে পার, না হলে থামে।
     */
    public function requestFor(DeliveryChallan $challan): ?Approval
    {
        $verdict = $this->judge($challan->fresh(['lines.product.tax']) ?? $challan);

        if ($verdict->action !== self::APPROVAL || ! $verdict->isBelow() || ! $this->flowExists($challan)) {
            return null;
        }

        return $this->approvals->stopping(
            document: $challan,
            module: 'sales',
            action: self::APPROVAL_ACTION,
            amount: $verdict->net,
            reason: $this->reasonOf($verdict),
            fields: ['margin_percent' => $verdict->worstPercent()],
        );
    }

    // ── মাপা ──────────────────────────────────────────────────────────────

    /** কাগজের রায় — কিছু না লিখে, কিছু না ছুঁড়ে। */
    public function judge(SalesInvoice|DeliveryChallan $document): MarginVerdict
    {
        if ($document instanceof SalesInvoice) {
            /*
             * ⛔ বিলের মাথার ছাড়ও মাপে — চূড়ান্ত অডিট ⛔৬, ৩০ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ আগে বিল মাপা হত মাথার ছাড় শূন্য ধরে, আর চালানে পাশ করা সারি বিলে আর মাপাই হত না:
             * ৯৬ খরচের মাল চালানে ১০০-তে পাশ, বিলে ২০ মাথার ছাড় → ৮০-তে বিক্রি, কোনো দেয়াল ছাড়া।
             * ⭐ চালান যতটুকু মাথার ছাড় নিয়ে মাপা হয়েছিল বিলে তার বেশি হলে সব সারি আবার, পুরো ছাড়সহ
             * ([[billDiscountBeyondTheChallan()]])। ⓘ কাউন্টারে চালান আর বিলের ছাড় একই — দ্বিতীয় মাপ নয়।
             */
            $beyond = $this->billDiscountBeyondTheChallan($document);

            return $this->evaluate($this->invoiceRows($document, skipJudged: $beyond === null), $beyond ?? '0');
        }

        return $this->evaluate(
            $this->challanRows($document),
            $this->decimal($document->discount_amount ?? '0'),
        );
    }

    /**
     * পর্দার জন্য পণ্যপ্রতি আনুমানিক খরচ — এক ভিত্তি-এককের, আর প্যাকের গুণক।
     *
     * ⛔ কেবল `sales.cost.view` থাকলে ডাকা হয় — চাবি ছাড়া কাউন্টারের পাতায়
     * খরচের সংখ্যা যাওয়াই চলে না, লুকানো ঘরেও না (পাতার উৎসে দেখা যায়)।
     *
     * @param  iterable<Product>  $products
     * @return array<int, array{cost: string|null, lots: array<int, string|null>, other: string|null, factors: array<int, string>}>
     */
    public function screenCosts(iterable $products): array
    {
        // ⓘ দুইবার হাঁটা হয় — জেনারেটর এলে দ্বিতীয়বার খালি পেত
        $products = collect($products)->values()->all();

        $ladders = app(PackConversion::class)->laddersFor($products);
        $out = [];

        /*
         * ⚠️ সব পণ্যের স্তর একটা কোয়েরিতে। ⛔ পণ্যপ্রতি একটা করলে দুই
         * হাজার পণ্যের কাউন্টার খুলতে দুই হাজার কোয়েরি লাগত।
         * ⓘ ক্রম `open()`-এর — FIFO-র মাথা আগে।
         */
        $ids = array_map(fn (Product $p) => (int) $p->id, $products);
        $this->layers = array_fill_keys($ids, []);

        foreach (CostLayer::query()->whereIn('product_id', $ids)->open()
            ->get(['id', 'product_id', 'batch_id', 'qty_remaining', 'unit_cost']) as $layer) {
            $this->layers[(int) $layer->product_id][] = $this->layerRow($layer);
        }

        foreach ($products as $product) {
            $this->drawn = [];

            $factors = [];

            foreach ($ladders[$product->id] ?? [] as $step) {
                $factors[(int) $step['unit']->id] = (string) $step['factor'];
            }

            /*
             * ⭐ লট ধরে খরচ — মালিকের প্রশ্ন, ৪ অক্টোবর ২০২৬: "মার্জিন এত বেশি দেখায় কেন?"
             *
             * ⛔ আগে পর্দা পণ্যের সবচেয়ে পুরনো স্তরের দাম দেখাত, বাছা লটের নয় — অথচ বিল খরচ নেয় বাছা লটের স্তর থেকে
             * ([[CostLayerService::issue()]])। পুরনো স্তর সস্তা হলে মার্জিন ফুলে দেখাত (৭.৮৫%, ১৪.৫৫%), আসলে ৪%।
             *
             * ⓘ `lots` — যে লটের নিজের স্তর আছে, তার এক এককের খরচ। `other` — যে লটের নিজের স্তর নেই (`issue()` তখন
             * আগে লটহীন স্তর, তারপর পুরনো আগে)। `cost` — লট ছাড়া সারি, আগের মতো FIFO।
             */
            $lots = [];

            foreach (array_unique(array_filter(array_column($this->layers[(int) $product->id] ?? [], 'batch'))) as $batchId) {
                $this->drawn = [];
                $lots[(int) $batchId] = $this->costOf($product, '1', (int) $batchId);
            }

            $this->drawn = [];
            $other = $this->costOf($product, '1', 0);
            $this->drawn = [];

            $out[(int) $product->id] = [
                'cost' => $this->costOf($product, '1'),
                'lots' => $lots,
                'other' => $other,
                'factors' => $factors,
            ];
        }

        $this->drawn = [];

        return $out;
    }

    /**
     * কাউন্টারের পর্দার জন্য যা যায় — খরচ, সীমা, আর লেখাগুলো।
     *
     * ⛔ চাবি না থাকলে খরচের তালিকা **খালি** যায়, লুকানো নয় — পাতার উৎস
     * খুললেও সংখ্যাটা পাওয়া যায় না। ⓘ সীমাটা তবু যায়: সেটা গোপন কিছু নয়,
     * আর খরচ ছাড়া তা দিয়ে কিছুই বের করা যায় না।
     *
     * @param  iterable<Product>  $products
     * @return array{costs: array<int, array{cost: string|null, factors: array<int, string>}>, floor: string, words: array<string, string>}
     */
    public function screen(?Authenticatable $user, iterable $products, ?Warehouse $warehouse = null): array
    {
        $allowed = $user !== null && method_exists($user, 'can') && $user->can(self::COST_KEY);

        return [
            'costs' => $allowed ? $this->screenCosts($products) : [],

            /*
             * ⭐ ফ্রির খরচ — সিদ্ধান্ত "ক" ([[freeCost()]])। ⓘ ভাণ্ডারের ফ্রি শূন্যে; নিজের মাল থেকে দেওয়া ফ্রি কেবল সুইচ চালু
             * থাকলে, আর তখন পর্দার জানা দরকার ভাণ্ডারে কত আছে। চাবি ছাড়া এগুলোও যায় না।
             */
            'beyond' => $allowed && (bool) $this->settings->get('sales.free_beyond_pool', false),
            'pools' => $allowed && (bool) $this->settings->get('sales.free_beyond_pool', false)
                ? $this->screenPools($products, $warehouse)
                : [],
            'floor' => $this->floor(),
            'words' => [
                'margin' => __('sales::margin.screen_margin'),
                'below' => __('sales::margin.screen_below'),
                'unknown' => __('sales::margin.screen_cost_unknown'),
            ],
        ];
    }

    /**
     * পর্দার জন্য ফ্রি ভাণ্ডার — পণ্য → ['lots' => [লট → পরিমাণ], 'any' => লট ছাড়া পরিমাণ]।
     *
     * @param  iterable<Product>  $products
     * @return array<int, array{lots: array<int, string>, any: string}>
     */
    private function screenPools(iterable $products, ?Warehouse $warehouse): array
    {
        $products = collect($products)->values();
        $warehouseId = $warehouse?->id ?? (int) Warehouse::query()->where('is_default', true)->value('id');
        $out = [];

        $byLot = StockMovement::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('warehouse_id', $warehouseId)
            ->whereNotNull('batch_id')
            ->groupBy('product_id', 'batch_id')
            ->selectRaw('product_id, batch_id, COALESCE(SUM(free_change), 0) as total')
            ->get();

        foreach ($products as $product) {
            $out[(int) $product->id] = [
                'lots' => $byLot->where('product_id', $product->id)
                    ->mapWithKeys(fn ($r) => [(int) $r->batch_id => (string) $r->total])->all(),
                'any' => $this->freePool((int) $product->id, null, $warehouseId),
            ];
        }

        return $out;
    }

    /** এই কোম্পানির সীমা, শতাংশে (০ = খরচের নিচে নয়)। */
    public function floor(): string
    {
        return $this->decimal($this->settings->get(self::FLOOR, '0'));
    }

    /**
     * এই কোম্পানির পথ।
     *
     * ⚠️ অচেনা মান এলে `block` — সবচেয়ে কড়া। ⛔ হাতে বসানো একটা টাইপো
     * ("aproval") পাহারাটা নীরবে তুলে দিত না।
     */
    public function action(): string
    {
        $action = (string) $this->settings->get(self::ACTION, self::WARN);

        return in_array($action, self::ACTIONS, true) ? $action : self::BLOCK;
    }

    // ── ভিতরের কাজ ───────────────────────────────────────────────────────

    /**
     * @param  list<array{index: int, product: Product, qty: string, net: string}>  $rows
     */
    private function evaluate(array $rows, string $headerDiscount): MarginVerdict
    {
        $floor = $this->floor();

        /*
         * ⚠️ স্তরগুলো প্রতিবার নতুন করে পড়া হয়। ⓘ একই সেবা এক অনুরোধে
         * দুইবার ডাকা হতে পারে (চালান, তারপর বিল) — মাঝখানে আসল টান হয়ে
         * গেলে পুরনো ছবিটা ইতিমধ্যে বেরিয়ে যাওয়া মাল দেখাত।
         */
        $this->drawn = [];
        $this->layers = [];

        $lines = [];
        $net = '0';
        $cost = '0';

        foreach ($rows as $row) {
            $lineCost = $this->costOf($row['product'], $row['qty'], $row['batch'] ?? null);

            /*
             * ⭐ ফ্রির খরচও মার্জিনে — মালিকের সিদ্ধান্ত "ক", ৪ অক্টোবর ২০২৬ (আন্তর্জাতিক নিয়ম)।
             *
             * ⓘ মার্জিন = (নিট বিক্রি − [বিক্রির পরিমাণ + ফ্রি] × বাছা লটের খরচ) ÷ নিট বিক্রি। ⭐ লটের সরবরাহকারী-ফ্রি ভাণ্ডার
             * থেকে আসা ফ্রির খরচ শূন্য — ওটা বিনা দামে এসেছিল। নিজের মাল থেকে দেওয়া ফ্রি (`sales.free_beyond_pool`) স্তরের
             * খরচে, ঠিক যেমন খাতায় প্রচারের খরচে (৫২২২) ওঠে ([[DirectSaleService::giveFromStock()]])।
             */
            if ($lineCost !== null) {
                $freeCost = $this->freeCost($row);
                $lineCost = $freeCost === null ? null : bcadd($lineCost, $freeCost, 4);
            }

            $lines[] = new MarginLine(
                index: $row['index'],
                productId: (int) $row['product']->id,
                productName: $row['product']->name(),
                qty: $row['qty'],
                net: $row['net'],
                cost: $lineCost,
                marginPercent: $lineCost === null ? null : $this->percent($row['net'], $lineCost),
                below: $lineCost !== null && $this->isBelow($row['net'], $lineCost, $floor),
            );

            /*
             * ⓘ গোটা কাগজ কেবল জানা খরচের সারি দিয়ে। ⛔ অজানাটা শূন্য ধরে
             * যোগ করলে তার পুরো বিক্রয় "লাভ" হয়ে বসত, আর গোটা কাগজের
             * মার্জিন ফুলে উঠে অন্য সারির ক্ষতি ঢেকে দিত।
             */
            if ($lineCost !== null) {
                $net = bcadd($net, $row['net'], 4);
                $cost = bcadd($cost, $lineCost, 4);
            }
        }

        $this->drawn = [];

        $known = array_filter($lines, fn (MarginLine $l) => $l->costKnown()) !== [];
        $docNet = bcsub($net, $headerDiscount, 4);

        return new MarginVerdict(
            lines: $lines,
            floor: $floor,
            action: $this->action(),
            net: $docNet,
            cost: $cost,
            marginPercent: $known ? $this->percent($docNet, $cost) : null,
            documentBelow: $known && $this->isBelow($docNet, $cost, $floor),
        );
    }

    /**
     * সীমার নিচে? — ভাগ ছাড়া, গুণ দিয়ে মাপা।
     *
     *     (বিক্রয় − খরচ) × ১০০  <  সীমা × বিক্রয়
     *
     * ⓘ ভাগ করলে দশমিকের গোল করা ঠিক-সীমার সারিকে নিচে ফেলতে পারত।
     * ⭐ ঠিক সীমায় থাকা সারি পার হয় — "নিচে" মানে কঠোরভাবে নিচে।
     *
     * ⚠️ বিক্রয় শূন্য বা ঋণাত্মক (পুরোটাই ছাড়) হলে শতাংশের মানে নেই —
     * তখন খরচ শূন্যের বেশি হলেই নিচে। ⛔ নাহলে ১০০% ছাড়ের সারি কখনো
     * ধরা পড়ত না, অথচ সেটাই সবচেয়ে বড় ক্ষতি।
     */
    private function isBelow(string $net, string $cost, string $floor): bool
    {
        if (bccomp($net, '0', 4) <= 0) {
            return bccomp($cost, '0', 4) > 0;
        }

        return bccomp(
            bcmul(bcsub($net, $cost, 6), '100', 6),
            bcmul($floor, $net, 6),
            6,
        ) < 0;
    }

    private function percent(string $net, string $cost): ?string
    {
        if (bccomp($net, '0', 4) <= 0) {
            return null;
        }

        /* ⓘ দেখানোর জন্য — অর্ধেক উপরে গোল, কাটা নয়: −৬.৬৬৬ হলো −৬.৬৭ (bcdiv কাটে)।
           ⚠️ দেয়ালের তুলনা এই সংখ্যায় নয় — [[isBelow()]] নিজের পূর্ণ অঙ্কে মাপে। */
        return Money::round(bcdiv(bcmul(bcsub($net, $cost, 6), '100', 6), $net, 6), 2);
    }

    /**
     * FIFO স্তর থেকে আনুমানিক খরচ — কিছু না লিখে।
     *
     * ⓘ [[CostLayerService::issue()]]-এর হুবহু হাঁটা: `open()` স্কোপ, একই ক্রম।
     * ⛔ স্তরে যথেষ্ট মাল না থাকলে `null` — আসল `issue()` তখন নিজেই থামে,
     * আর এখানে একটা দাম ধরে নেওয়াটাই পুরনো ভুল।
     *
     * ⓘ রান্না করা খাবার: উপকরণের স্তর থেকে, ঠিক [[SalesInvoiceService::cookedCost()]]
     * যেমন করে।
     */
    /**
     * @param  int|null  $batchId  বাছা লট — `null` লট ছাড়া (FIFO), `0` এমন লট যার নিজের স্তর নেই
     */
    private function costOf(Product $product, string $qty, ?int $batchId = null): ?string
    {
        if (bccomp($qty, '0', 4) <= 0) {
            return '0';
        }

        if ($this->recipes->consumesOnSale((int) $product->id)) {
            $needs = $this->recipes->needsFor((int) $product->id, $qty);

            if ($needs === []) {
                return null;
            }

            $total = '0';

            foreach ($needs as $need) {
                $part = $this->drawFromLayers((int) $need['product_id'], (string) $need['qty']);

                if ($part === null) {
                    return null;
                }

                $total = bcadd($total, $part, 4);
            }

            return $total;
        }

        return $this->drawFromLayers((int) $product->id, $qty, $batchId);
    }

    private function drawFromLayers(int $productId, string $qty, ?int $batchId = null): ?string
    {
        /*
         * ⓘ `CostLayer`-এর কোম্পানির স্কোপ নিজেই বসে (`BelongsToCompany`) —
         * অন্য কোম্পানির স্তর এই হিসাবে কখনো আসে না।
         */
        $this->layers[$productId] ??= CostLayer::query()
            ->where('product_id', $productId)
            ->open()
            ->get(['id', 'batch_id', 'qty_remaining', 'unit_cost'])
            ->map(fn (CostLayer $l) => $this->layerRow($l))
            ->all();

        /*
         * ⭐ [[CostLayerService::issue()]]-এর হুবহু ক্রম — ৪ অক্টোবর ২০২৬।
         *
         * ⓘ লট বাছা থাকলে আগে সেই লটের নিজের স্তর; না কুলালে আগে লটহীন স্তর, তারপর অন্য সব — পুরনো আগে। লট ছাড়া
         * আগের মতো সোজা FIFO। ⛔ আগে এখানে সবসময় সোজা FIFO ছিল, তাই মার্জিন মাপা হত এমন খরচে যা বিল নেয় না।
         */
        $all = $this->layers[$productId];
        $order = $all;

        if ($batchId !== null) {
            $own = array_filter($all, fn (array $l) => $l['batch'] === $batchId);
            $rest = array_filter($all, fn (array $l) => $l['batch'] !== $batchId);
            $order = [
                ...array_values($own),
                ...array_values(array_filter($rest, fn (array $l) => $l['batch'] === null)),
                ...array_values(array_filter($rest, fn (array $l) => $l['batch'] !== null)),
            ];
        }

        $left = $qty;
        $cost = '0';

        foreach ($order as $layer) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            // আগের সারিগুলো এই স্তর থেকে যতটা নিয়েছে, সেটা বাদ
            $available = bcsub($layer['qty'], $this->drawn[$layer['id']] ?? '0', 4);

            if (bccomp($available, '0', 4) <= 0) {
                continue;
            }

            $take = bccomp($available, $left, 4) >= 0 ? $left : $available;
            $cost = bcadd($cost, bcmul($take, $layer['cost'], 4), 4);
            $left = bcsub($left, $take, 4);
            $this->drawn[$layer['id']] = bcadd($this->drawn[$layer['id']] ?? '0', $take, 4);
        }

        return bccomp($left, '0', 4) > 0 ? null : $cost;
    }

    /**
     * সারির ফ্রির খরচ — ভাণ্ডারের ফ্রি শূন্যে, নিজের মালের ফ্রি স্তরের খরচে; `null` মানে খরচ অজানা।
     *
     * ⓘ বিলের সারিতে চালান আগেই মাল বের করে ফেলেছে, তাই সেখানে আসল খরচ (`free_cost`) — আন্দাজ নয়।
     *
     * @param  array<string, mixed>  $row
     */
    private function freeCost(array $row): ?string
    {
        $free = $this->decimal($row['free'] ?? '0');

        if (bccomp($free, '0', 4) <= 0) {
            return '0';
        }

        if (array_key_exists('free_cost', $row)) {
            return (string) $row['free_cost'];
        }

        if (! (bool) $this->settings->get('sales.free_beyond_pool', false)) {
            return '0';
        }

        $pool = $this->freePool((int) $row['product']->id, $row['batch'] ?? null, $row['warehouse'] ?? null);
        $fromStock = bccomp($pool, $free, 4) >= 0 ? '0' : bcsub($free, bccomp($pool, '0', 4) > 0 ? $pool : '0', 4);

        return bccomp($fromStock, '0', 4) > 0 ? $this->costOf($row['product'], $fromStock, $row['batch'] ?? null) : '0';
    }

    /** লটের (বা লট ছাড়া পণ্যের) সরবরাহকারী-ফ্রি ভাণ্ডারে এখন কত — তালা ছাড়া, কেবল মাপার জন্য। */
    private function freePool(int $productId, ?int $batchId, ?int $warehouseId): string
    {
        $warehouseId ??= (int) Warehouse::query()->where('is_default', true)->value('id');

        if ($batchId !== null) {
            return (string) StockMovement::query()
                ->where('batch_id', $batchId)
                ->where('warehouse_id', $warehouseId)
                ->selectRaw('COALESCE(SUM(free_change), 0) as total')
                ->value('total');
        }

        $product = Product::query()->find($productId);
        $warehouse = Warehouse::query()->find($warehouseId);

        return $product === null ? '0' : app(StockService::class)->freeAvailableQty($product, $warehouse);
    }

    /**
     * চালান নিজের মাল থেকে যে ফ্রি দিয়েছিল তার আসল খরচ, এই সারির ফ্রির ভাগে — চালানের ঐ পণ্যের সব ফ্রি সারির অনুপাতে।
     */
    private function freeCostOnTheChallan(int $challanId, int $productId, string $free, string $productFree): string
    {
        if (bccomp($productFree, '0', 4) <= 0) {
            return '0';
        }

        $spent = (string) CostLayerUse::query()
            ->where('source_type', DeliveryChallan::STOCK_SOURCE.':free')
            ->where('source_id', $challanId)
            ->where('product_id', $productId)
            ->sum('amount');

        return bcdiv(bcmul($spent, $free, 6), $productFree, 4);
    }

    /** @return array{id: int, batch: int|null, qty: string, cost: string} */
    private function layerRow(CostLayer $layer): array
    {
        return [
            'id' => (int) $layer->id,
            'batch' => $layer->batch_id === null ? null : (int) $layer->batch_id,
            'qty' => (string) $layer->qty_remaining,
            'cost' => (string) $layer->unit_cost,
        ];
    }

    /**
     * বিলের সারি — চালানে একবার মাপা হয়ে গেলে আবার নয়।
     *
     * ⚠️ চালানে বাঁধা সারি, চালান নিশ্চিত, আর বিলের দর চালানের চেয়ে কম
     * নয় — তখন দেয়ালটা চালানেই দাঁড়িয়েছিল। ⛔ আবার মাপলে একই বিক্রির
     * জন্য দুইটা সই চাওয়া হত (চালানে একটা, বিলে আরেকটা), আর সতর্কতাও
     * দুইবার। ⓘ কিন্তু বিলে দর কমানো বা বাড়তি ছাড় দিলে সারিটা আবার মাপা
     * হয় — নাহলে চালানের পরে দর কমিয়ে দেয়াল এড়ানো যেত।
     *
     * @return list<array{index: int, product: Product, qty: string, net: string}>
     */
    private function invoiceRows(SalesInvoice $invoice, bool $skipJudged = true): array
    {
        $invoice->loadMissing(['lines.product', 'lines.challanLine.challan']);

        $rows = [];

        foreach ($invoice->lines->sortBy('line_no')->values() as $index => $line) {
            /** @var SalesInvoiceLine $line */
            $qty = (string) $line->qty;
            $net = bcsub(bcmul($qty, (string) $line->rate, 4), (string) ($line->discount ?? '0'), 4);

            if ($skipJudged && $this->alreadyJudgedOnTheChallan($line, $qty, $net)) {
                continue;
            }

            if ($line->product === null) {
                continue;
            }

            /*
             * ⓘ বিলের সারিতে ভ্যাট আলাদা লেখা থাকে: `amount − tax` — মার্জিন
             * রিপোর্ট আর পণ্যভিত্তিক রিপোর্টের একই সংজ্ঞা, দামের ভেতরের
             * ভ্যাট হোক বা বাইরের।
             */
            $rows[] = [
                'index' => $index,
                'product' => $line->product,
                // ⓘ বিলের লট আসে তার চালানের সারি থেকে — [[SalesInvoiceService::costByLot()]] যেমন নেয়
                'batch' => $line->challanLine?->batch_id === null ? null : (int) $line->challanLine->batch_id,
                ...$this->invoiceFree($line),
                'qty' => $qty,
                'net' => bcsub((string) $line->amount, (string) ($line->tax ?? '0'), 4),
            ];
        }

        return $rows;
    }

    /**
     * বিলের মাথার ছাড় — যদি সেটা চালানের মাপা ছাড়ের **বেশি** হয়; নাহলে `null` (নতুন কিছু মাপার নেই)।
     *
     * ⓘ চালানের মাথার ছাড় (`discount_amount`) দিয়ে চালান আগেই মাপা হয়েছে — কাউন্টারে ঠিক বিলের ছাড়টাই।
     * বিলে তার বেশি হলে ফেরে পুরো বিলের ছাড়, কারণ তখন সব সারি আবার মাপা হয়।
     */
    /**
     * বিলের সারির ফ্রি — চালানের সারি থেকে; চালান পাকা হলে আসল খরচ (মাল আগেই বেরিয়েছে), নাহলে আগের নিয়মে।
     *
     * @return array<string, mixed>
     */
    private function invoiceFree(SalesInvoiceLine $line): array
    {
        $challanLine = $line->challanLine;
        $free = (string) ($challanLine?->free_qty ?? '0');

        if ($challanLine === null || bccomp($free, '0', 4) <= 0) {
            return ['free' => '0'];
        }

        $challan = $challanLine->challan;

        if ($challan === null || ! in_array($challan->status, DocumentStatus::POSTED, true)) {
            return ['free' => $free, 'warehouse' => $challan?->warehouse_id === null ? null : (int) $challan->warehouse_id];
        }

        $productFree = (string) $challan->lines()->where('product_id', $challanLine->product_id)->sum('free_qty');

        return [
            'free' => $free,
            'free_cost' => $this->freeCostOnTheChallan((int) $challan->id, (int) $challanLine->product_id, $free, $productFree),
        ];
    }

    private function billDiscountBeyondTheChallan(SalesInvoice $invoice): ?string
    {
        $bill = $this->decimal($invoice->bill_discount ?? '0');

        if (bccomp($bill, '0', 4) <= 0) {
            return null;
        }

        $invoice->loadMissing('lines.challanLine.challan');

        $judged = '0';

        foreach ($invoice->lines as $line) {
            $challan = $line->challanLine?->challan;

            if ($challan !== null && in_array($challan->status, DocumentStatus::POSTED, true)
                && bccomp($this->decimal($challan->discount_amount ?? '0'), $judged, 4) > 0) {
                $judged = $this->decimal($challan->discount_amount ?? '0');
            }
        }

        return bccomp($bill, $judged, 4) > 0 ? $bill : null;
    }

    private function alreadyJudgedOnTheChallan(SalesInvoiceLine $line, string $qty, string $net): bool
    {
        $challanLine = $line->challanLine;
        $challan = $challanLine?->challan;

        if ($challanLine === null || $challan === null || ! in_array($challan->status, DocumentStatus::POSTED, true)) {
            return false;
        }

        $gross = bcmul($qty, (string) $challanLine->rate, 4);
        $percent = (string) ($challanLine->discount_percent ?? '0');
        $challanNet = bcsub($gross, bcdiv(bcmul($gross, $percent, 4), '100', 4), 4);

        return bccomp($net, $challanNet, 4) >= 0;
    }

    /**
     * চালানের সারি — ছাড় শতাংশে লেখা থাকে (কাউন্টার), তাই টাকায় নামানো।
     *
     * ⓘ নিয়মটা [[DirectSaleService::lineDiscount()]]-এর হুবহু, কারণ বিলের
     * সারির ছাড় ওখান থেকেই আসে।
     *
     * @return list<array{index: int, product: Product, qty: string, net: string}>
     */
    private function challanRows(DeliveryChallan $challan): array
    {
        // ⓘ করসহ — [[withoutInclusiveVat()]] পণ্যের কর পড়ে; না আনলে প্রতিটা সারিতে আলাদা কোয়েরি, আর local-এ ৫০০ (৬ অক্টোবর ২০২৬-এর বিক্রয় ধারার পরীক্ষা)
        $challan->loadMissing('lines.product.tax');

        $rows = [];

        foreach ($challan->lines->sortBy('line_no')->values() as $index => $line) {
            if ($line->product === null) {
                continue;
            }

            $qty = (string) $line->delivered_qty;
            $gross = bcmul($qty, (string) $line->rate, 4);
            $percent = (string) ($line->discount_percent ?? '0');

            $rows[] = [
                'index' => $index,
                'product' => $line->product,
                'batch' => $line->batch_id === null ? null : (int) $line->batch_id,
                'free' => (string) ($line->free_qty ?? '0'),
                'warehouse' => $challan->warehouse_id === null ? null : (int) $challan->warehouse_id,
                'qty' => $qty,
                'net' => $this->withoutInclusiveVat(
                    $line->product,
                    bcsub($gross, bcdiv(bcmul($gross, $percent, 4), '100', 4), 4),
                ),
            ];
        }

        return $rows;
    }

    /**
     * দামের ভেতরের ভ্যাট বাদ — ভ্যাট সরকারের টাকা, আমাদের আয় নয়।
     *
     * ⛔ বাদ না দিলে ভেতরের ভ্যাটওয়ালা পণ্যে বিক্রয় ভ্যাটের হার পরিমাণ
     * বেশি দেখাত, আর ৩.৮২% মার্জিনের ব্যবসায় ৫% ভ্যাট একাই খরচের নিচের
     * বিক্রিটা "লাভজনক" দেখিয়ে পার করে দিত। ⓘ হিসাবটা [[Tax::amountOn()]]-এর,
     * বিলের সারি যেভাবে ভ্যাট বসায় ([[CalculatesSalesLines::lineFigures()]])।
     * বাইরের ভ্যাট দরের উপরে বসে, তাই সেখানে বাদ দেওয়ার কিছু নেই।
     */
    private function withoutInclusiveVat(Product $product, string $net): string
    {
        $tax = $product->tax;

        if ($tax === null || ! $tax->is_inclusive) {
            return $net;
        }

        return bcsub($net, $tax->amountOn($net), 4);
    }

    /**
     * ছক আছে কি না — কাগজ-নির্দিষ্ট বা মডিউল-ব্যাপী, সক্রিয়।
     *
     * ⓘ [[ApprovalEngine::flowFor()]]-এর একই দুই জায়গা। ⚠️ ছক আছে কিন্তু
     * তার সীমা বা শর্তে কাগজটা ধরা না পড়লে সেটা কোম্পানির নিজের বাছাই
     * ("এত টাকার নিচে সই লাগবে না") — সেটা মানা হয়।
     */
    private function flowExists(Model $document): bool
    {
        return ApprovalFlow::query()
            ->where('module', 'sales')
            ->where('action', self::APPROVAL_ACTION)
            ->where('is_active', true)
            ->whereIn('document_type', ['', class_basename($document)])
            ->exists();
    }

    /**
     * সারির ঘরে বার্তা — `lines.{index}.rate`, আর গোটা কাগজেরটা `lines`-এ।
     *
     * @return array<string, string>
     */
    private function lineErrors(MarginVerdict $verdict): array
    {
        $errors = [];

        foreach ($verdict->belowLines() as $line) {
            $errors["lines.{$line->index}.rate"] = $this->lineSentence($line, $verdict->floor);
        }

        if ($verdict->documentBelow) {
            $errors['lines'] = $this->documentSentence($verdict);
        }

        return $errors;
    }

    /** @return list<string> */
    private function sentences(MarginVerdict $verdict): array
    {
        return array_values($this->lineErrors($verdict));
    }

    /**
     * ⛔ সংখ্যাগুলো কেবল খরচের চাবিধারীর জন্য।
     *
     * ⓘ বিক্রয় আর মার্জিন% জানা থাকলে খরচ এক অঙ্কেই বেরোয় — তাই চাবি
     * ছাড়া বার্তা কেবল পণ্যের নাম আর "সীমার নিচে" বলে। ⚠️ কাউন্টারের
     * বিক্রেতা জানেন **কোন** সারিটা থামাল, খরচটা জানেন না — দরকষাকষিতে
     * খরচ জানা থাকলে সেটাই ব্যবহার হয়।
     */
    private function lineSentence(MarginLine $line, string $floor): string
    {
        if (! $this->canSeeCost() || $line->marginPercent === null) {
            return __('sales::margin.below_line_plain', ['product' => $line->productName]);
        }

        return __('sales::margin.below_line', [
            'product' => $line->productName,
            'margin' => $this->trim($line->marginPercent),
            'floor' => $this->trim($floor),
        ]);
    }

    private function documentSentence(MarginVerdict $verdict): string
    {
        if (! $this->canSeeCost() || $verdict->marginPercent === null) {
            return __('sales::margin.below_document_plain');
        }

        return __('sales::margin.below_document', [
            'margin' => $this->trim($verdict->marginPercent),
            'floor' => $this->trim($verdict->floor),
        ]);
    }

    /**
     * সইয়ের অনুরোধের কারণ — সংখ্যা ছাড়া।
     *
     * ⚠️ কারণটা [[DocumentApproval::awaitingWord()]] বিক্রেতার পর্দাতেও দেখায়,
     * তাই খরচ বা মার্জিন% এখানে বসে না। ⓘ সইকারী কাগজ খুলে মার্জিন রিপোর্টে
     * সংখ্যাগুলো দেখেন।
     */
    private function reasonOf(MarginVerdict $verdict): string
    {
        $names = array_values(array_unique(array_map(
            fn (MarginLine $l) => $l->productName,
            $verdict->belowLines(),
        )));

        return __('sales::margin.reason', [
            'products' => $names === [] ? __('sales::margin.whole_document') : implode(', ', $names),
            'floor' => $this->trim($verdict->floor),
        ]);
    }

    private function canSeeCost(): bool
    {
        return (bool) auth()->user()?->can(self::COST_KEY);
    }

    /**
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function flash(array $warnings): array
    {
        $warnings = array_values(array_unique($warnings));

        if ($warnings !== [] && app()->bound('session')) {
            session()->flash(self::FLASH, array_values(array_unique([
                ...(array) session()->get(self::FLASH, []),
                ...$warnings,
            ])));
        }

        return $warnings;
    }

    private function decimal(mixed $value): string
    {
        $value = trim((string) $value);

        return is_numeric($value) ? bcadd($value, '0', 4) : '0';
    }

    private function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
