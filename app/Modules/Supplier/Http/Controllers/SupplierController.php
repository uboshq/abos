<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\GrandTotals;
use App\Core\Concerns\SortsLists;
use App\Core\Services\CustomFieldService;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\PartyLedger;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\Supplier\Http\Requests\SupplierRequest;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * সরবরাহকারীর স্ক্রিন।
 *
 * গ্রাহকের পর্দার আয়না। সবচেয়ে বড় পার্থক্য পাতার নিচে: গ্রাহকের
 * পাতায় "বকেয়া" মানে সে আমাদের দেবে, এখানে মানে আমরা তাকে দেব।
 */
class SupplierController extends Controller implements HasMiddleware
{
    use GrandTotals;

    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    use AuthorizesResource;
    use SortsLists;

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Supplier::class, 'supplier'),

            /*
             * ফিরিয়ে আনাও নিষ্ক্রিয় করার অনুমতিতেই।
             *
             * update দিলে যে ব্যবহারকারী নিষ্ক্রিয় করতে পারে না, সে-ও
             * অন্যের নিষ্ক্রিয় করা সরবরাহকারী ফিরিয়ে আনতে পারত — তখন
             * সুইচটার একদিকে তালা থাকত, অন্যদিকে নয়।
             */
            new Middleware('can:delete,supplier', only: ['activate']),

            /*
             * ⛔ সেবাদাতার তালিকাও তালার ভিতরে — ২০ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ [[AuthorizesResource::abilityPerResourceMethod()]] কেবল চেনা
             * নামগুলোয় (index, show, edit…) পাহারা বসায়, আর `services` সেই
             * তালিকায় নেই। ⓘ ফল: তালিকাটা **লগইন করা যে কারও** কাছে খুলত —
             * সরবরাহকারীর নাম, বাকির সীমা, সব। মেনুতে সারিটা লুকানো থাকলেও
             * ঠিকানা টাইপ করলেই পর্দা আসত।
             *
             * ⓘ একই তালিকা, একই সারি — তাই একই চাবি (`viewAny`), সরবরাহকারীর
             * তালিকার মতোই।
             */
            new Middleware('can:viewAny,'.Supplier::class, only: ['services']),
        ];
    }

    /**
     * সরবরাহকারীর তালিকা — কেবল আসল সরবরাহকারীরা।
     */
    public function index(Request $request): View
    {
        return $this->list($request, services: false);
    }

    /**
     * ⭐ সেবাদাতার তালিকা — মালিকের নির্দেশ, ১৬ সেপ্টেম্বর ২০২৬।
     *
     * *"সরবরাহকারীর পাশে আরও একটা বোতাম বানাও… সরবরাহকারী বাদে বাকিগুলো
     * ওই লিস্টে যাবে"*।
     *
     * ── ⓘ কেন আলাদা তালিকা, শুধু একটা ছাঁকনি নয় ────────────────────
     * ছাঁকনি হলে ঠিকানাটা মনে রাখতে হত আর প্রতিবার বেছে নিতে হত। ⭐ দুইটা
     * আলাদা পথ মানে দুইটা আলাদা বোতাম, আর মেনুতেই বোঝা যায় কোথায় কী।
     *
     * ⚠️ তবু ভেতরে একটাই কোড — নিচের `list()`। ⛔ দুইবার লিখলে একদিন
     * একটায় ছাঁকনি বসত আর অন্যটায় নয়।
     */
    public function services(Request $request): View
    {
        return $this->list($request, services: true);
    }

    /**
     * দুইটা তালিকার ভেতরের একমাত্র কোড।
     *
     * ⓘ পার্থক্য কেবল একটা scope, আর পর্দার শিরোনামটা।
     */
    private function list(Request $request, bool $services): View
    {
        $query = Supplier::query()->inViewedBranch()
            ->when($services, fn ($q) => $q->onlyServiceProviders(), fn ($q) => $q->onlySuppliers())
            ->search($request->query('q'))
            ->when(! $request->boolean('inactive'), fn ($q) => $q->active())
            ->with(['partyType', 'paymentTerm'])
            // প্রদেয় সারির সাথেই আসে, নাহলে ৫০ সারিতে ৫০টা কোয়েরি
            // ⭐ হেডারে বাছা শাখায় (৩০ সেপ্টেম্বর ২০২৬) — [[Supplier::scopeWithPayableInView()]]
            ->withPayableInView();

        $sort = $this->applySort($query, $request, $this->sorts());

        // ⭐ সর্বমোট — ছাঁকা তালিকার সব পাতা মিলে, পাতা ভাঙার আগে ([[GrandTotals]])
        $grand = $this->grandTotals($query, ['payable' => 't.payable_in_view']);

        $suppliers = $query
            // পেজিনেশন বাধ্যতামূলক (সেকশন ৯)
            ->paginate(50)
            ->withQueryString();

        return view('supplier::index', [
            'menu' => $this->menu->forUser($request->user()),
            'suppliers' => $suppliers,
            'grand' => $grand,
            'q' => $request->query('q'),
            'showInactive' => $request->boolean('inactive'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,

            /* ⓘ পর্দাটা এক, কিন্তু শিরোনাম দুই — নাহলে দুইটা তালিকা
               দেখতে হুবহু এক হত আর কেউ বুঝত না কোনটায় দাঁড়িয়ে আছেন। */
            'heading' => $services
                ? __('supplier::menu.service_providers')
                : __('supplier::menu.suppliers'),
            'isServices' => $services,
        ]);
    }

    public function create(Request $request): View
    {
        /* ⓘ কোন তালিকা থেকে আসা হলো — কেবল পর্দার লেখা ঠিক করতে।
           ⚠️ এটা কোনো ছাঁকনি নয়, তাই ভুল মান এলেও কিছু ভাঙে না। */
        $kind = $request->query('kind') === 'service' ? 'service' : null;

        /*
         * ⭐ ধরনের ঘরে "সরবরাহকারী" আগে থেকেই বসানো — মালিকের নির্দেশ,
         * ১৬ সেপ্টেম্বর ২০২৬: *"নতুন সরবরাহকারী → ধরন → সরবরাহকারী by
         * default বসে থাকবে"*।
         *
         * ⓘ যুক্তিটা গণনার: সরবরাহকারীর তালিকায় যাঁরা যোগ হন তাঁদের
         * প্রায় সবাই আসল সরবরাহকারী। ⚠️ ঘরটা খালি রাখলে প্রতিবার একই
         * জিনিস বাছতে হত, আর কেউ ভুলে গেলে সারিটা ধরনহীন হয়ে বসত।
         *
         * ⛔ সেবাদাতার তালিকা থেকে এলে **বসানো হয় না**, আর সেটা
         * ইচ্ছাকৃত: ওখানে চারটা ধরন (কুরিয়ার · পরিবহন · হাম্মালি ·
         * সার্ভিস), আর কোনটা তা কেবল মানুষই জানেন। একটা আন্দাজ বসিয়ে
         * দিলে ভুলটা নীরবে সংরক্ষিত হত।
         */
        $supplier = new Supplier(['credit_limit' => 0, 'credit_days' => 0, 'is_active' => true]);

        if ($kind === null) {
            $supplier->party_type_id = $this->vendorTypeId();
        }

        return view('supplier::form', [
            'menu' => $this->menu->forUser($request->user()),
            'supplier' => $supplier,
            'kind' => $kind,
            ...$this->options($kind === 'service'),
        ]);
    }

    /**
     * "সরবরাহকারী" ধরনের আইডি — না থাকলে `null`।
     *
     * ⚠️ প্রতিষ্ঠান সারিটা মুছে বা নিষ্ক্রিয় করে দিতে পারে, তাই এটা
     * কখনোই ধরে নেওয়া যায় না যে সারিটা আছে। ⓘ না পেলে ঘরটা খালিই
     * থাকে — একটা ভুল আইডি বসিয়ে দেওয়ার চেয়ে খালি ঘর ভালো।
     */
    private function vendorTypeId(): ?int
    {
        return PartyType::query()
            ->where('code', Supplier::VENDOR_CODE)
            ->where('is_active', true)
            ->value('id');
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $supplier = $this->suppliers->create($request->validated());

        app(CustomFieldService::class)->save($supplier, $request->input('custom', []));

        return redirect()
            ->route('supplier.show', $supplier)
            ->with('saved', __('supplier::message.created'));
    }

    /**
     * একজন সরবরাহকারী ও তার লেনদেন।
     *
     * বকেয়ার অঙ্কটার সাথে সেই লেনদেনগুলোও আসে যেগুলো যোগ হয়ে অঙ্কটা
     * হয়েছে — নিয়ম ১। গ্রাহকের পাতায় একই কাঠামো, একই কারণে।
     */
    public function show(Request $request, Supplier $supplier): View
    {
        /*
         * ⭐ খাতার সারি আর মাথার প্রদেয় — হেডারে বাছা শাখায় (৩০ সেপ্টেম্বর ২০২৬), একই
         * ছাঁকনিতে; নাহলে শেষ সারির চলমান জের মাথার অঙ্কের সাথে মিলত না। ⛔ সীমার
         * সতর্কতা ([[Supplier::isOverTheirLimit()]]) গোটা কোম্পানিতেই মাপা হয়।
         */
        $ledger = ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->forParty(Supplier::drillSourceType(), $supplier->id)
            ->orderBy('trx_date')
            ->orderBy('id');

        /*
         * ⭐ খোঁজা আর ছাঁকনি — মালিক, ৩ অক্টোবর ২০২৬: "ফিল্টার অপশন দিতে হবে সার্চ অপশন দিতে হবে"।
         * ⓘ প্রতিটা সারির জের খাতার সব সারি থেকে, ছাঁকনিতেও — কখনো শূন্য থেকে নয় ([[PartyLedger::page()]])।
         * ⓘ `net_balance` খাতার নিয়মে (ডেবিট − ক্রেডিট), পর্দা লেখে "(Dr)/(Cr)" ([[Money::drCr()]]);
         * `running_balance` পাতার পুরনো অর্থেই থাকে — অন্য কোনো পড়ুয়া যেন না ভাঙে।
         */
        // ⭐ তারিখের ক্রমে, খুললে শেষ পাতা — মালিক, ৬ অক্টোবর ২০২৬ ([[PartyLedger::page()]])
        $entries = PartyLedger::page($ledger, $request, openAtEnd: true);

        $entries->getCollection()->each(function (LedgerEntry $entry) {
            $entry->running_balance = bcmul($entry->net_balance, '-1', 4);
        });

        return view('supplier::show', [
            'menu' => $this->menu->forUser($request->user()),
            'supplier' => $supplier->load(['partyType', 'paymentTerm', 'branch']),
            'payable' => $this->payableInView($supplier),
            /* ⓘ এক শাখা বাছা থাকলে সব শাখা মিলিয়ে প্রদেয়ও — সীমা ওটা দিয়েই মাপা হয় */
            'payableAll' => ViewedBranch::one() !== null ? $supplier->payable() : null,
            'entries' => $entries,
        ]);
    }

    /** মাথার প্রদেয় — খাতার সারির ঠিক সেই ছাঁকনিতে ([[show()]])। */
    private function payableInView(Supplier $supplier): string
    {
        $net = ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->forParty(Supplier::drillSourceType(), $supplier->id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as net')
            ->value('net') ?? 0;

        return bcadd((string) $net, '0', 4);
    }

    public function edit(Request $request, Supplier $supplier): View
    {
        return view('supplier::form', [
            'menu' => $this->menu->forUser($request->user()),
            'supplier' => $supplier,
            ...$this->options($supplier->party_type_id !== null && Supplier::query()->onlyServiceProviders()->whereKey($supplier->id)->exists()),
        ]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->suppliers->update($supplier, $request->validated());

        app(CustomFieldService::class)->save($supplier, $request->input('custom', []));

        return redirect()
            ->route('supplier.show', $supplier)
            ->with('saved', __('supplier::message.updated'));
    }

    /** মোছা নয়, নিষ্ক্রিয় করা — নিয়ম ৫। */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->suppliers->deactivate($supplier);

        return redirect()
            ->route('supplier.index')
            ->with('saved', __('supplier::message.deactivated'));
    }

    /**
     * আবার সক্রিয় করা।
     *
     * নিষ্ক্রিয় করা একমুখী দরজা হলে ব্যবহারকারী ভুল করে বন্ধ করা
     * সরবরাহকারীর জন্য দ্বিতীয় একটা রেকর্ড খুলত — একই প্রতিষ্ঠান দুইবার,
     * দুইটা আলাদা বকেয়া নিয়ে। সেটাই সবচেয়ে খারাপ ফল।
     */
    public function activate(Supplier $supplier): RedirectResponse
    {
        $this->suppliers->activate($supplier);

        return redirect()
            ->route('supplier.show', $supplier)
            ->with('saved', __('supplier::message.activated'));
    }

    /**
     * কোন বাছাই কী করে।
     *
     * প্রথমটাই ডিফল্ট, আর সেটা ইচ্ছাকৃতভাবে "সবচেয়ে বেশি প্রদেয় আগে":
     * তালিকাটা খোলার আসল কারণ প্রায় সবসময় "কাকে টাকা দিতে হবে", বর্ণ
     * অনুযায়ী কে কোথায় তা নয়।
     *
     * payable_net সাব-কোয়েরি থেকে আসে (withPayable), তাই এই সাজানোটা
     * ডাটাবেজেই হয় — PHP-তে সাজালে শুধু চলতি পাতাটা সাজত, পুরো তালিকা নয়।
     *
     * @return array<string, callable(Builder): mixed>
     */
    private function sorts(): array
    {
        return [
            'payable_desc' => fn ($q) => $q->orderByDesc('payable_in_view')->orderBy('name_en'),
            'payable_asc' => fn ($q) => $q->orderBy('payable_in_view')->orderBy('name_en'),
            'name' => fn ($q) => $q->orderBy('name_en'),
            'code' => fn ($q) => $q->orderBy('code'),
            'recent' => fn ($q) => $q->orderByDesc('created_at'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'payable_desc' => __('supplier::sort.payable_desc'),
            'payable_asc' => __('supplier::sort.payable_asc'),
            'name' => __('supplier::sort.name'),
            'code' => __('supplier::sort.code'),
            'recent' => __('supplier::sort.recent'),
        ];
    }

    /** @return array<string, mixed> */
    private function options(bool $services = false): array
    {
        return [
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            // "both" ধরনগুলোও আসে: একটা প্রতিষ্ঠান একইসাথে গ্রাহক ও
            // সরবরাহকারী হতে পারে, আর দুইবার লিখতে বলার মানে নেই
            /*
             * ⭐ ধরনের তালিকা তালিকা-ধরে ভাগ — মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬:
             * সরবরাহকারীর ফর্মে কেবল "সরবরাহকারী", সেবাদাতার ফর্মে কেবল সেবার
             * ধরন। নিয়মটা [[Supplier::scopeOnlySuppliers()]]-এর হুবহু: VENDOR
             * মানে সরবরাহকারী, বাকি সব সেবাদাতা — তাই ফর্ম আর তালিকা কখনো
             * আলাদা কথা বলে না।
             */
            'partyTypes' => PartyType::query()->for(PartyType::SUPPLIER)->active()
                ->where('code', $services ? '!=' : '=', Supplier::VENDOR_CODE)
                ->orderBy('code')->get(),
            'paymentTerms' => PaymentTerm::query()->active()->orderBy('code')->get(),
            'requireBangla' => $this->settings->enabled('supplier.require_bn_name'),
            'requireBin' => $this->settings->enabled('supplier.require_bin'),
        ];
    }
}
