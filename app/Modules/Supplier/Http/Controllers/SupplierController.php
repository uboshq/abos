<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\RunningBalance;
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
        ];
    }

    public function index(Request $request): View
    {
        $query = Supplier::query()
            ->search($request->query('q'))
            ->when(! $request->boolean('inactive'), fn ($q) => $q->active())
            ->with(['partyType', 'paymentTerm'])
            // প্রদেয় সারির সাথেই আসে, নাহলে ৫০ সারিতে ৫০টা কোয়েরি
            ->withPayable();

        $sort = $this->applySort($query, $request, $this->sorts());

        $suppliers = $query
            // পেজিনেশন বাধ্যতামূলক (সেকশন ৯)
            ->paginate(50)
            ->withQueryString();

        return view('supplier::index', [
            'menu' => $this->menu->forUser($request->user()),
            'suppliers' => $suppliers,
            'q' => $request->query('q'),
            'showInactive' => $request->boolean('inactive'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
        ]);
    }

    public function create(Request $request): View
    {
        return view('supplier::form', [
            'menu' => $this->menu->forUser($request->user()),
            'supplier' => new Supplier(['credit_limit' => 0, 'credit_days' => 0, 'is_active' => true]),
            ...$this->options(),
        ]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $supplier = $this->suppliers->create($request->validated());

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
        $ledger = LedgerEntry::query()
            ->forParty(Supplier::drillSourceType(), $supplier->id)
            ->orderBy('trx_date')
            ->orderBy('id');

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        /*
         * চলমান ব্যালেন্স ক্রেডিট-ধনাত্মক চিহ্নে।
         *
         * RunningBalance ডেবিট − ক্রেডিট গোনে, যা সম্পদের জন্য ঠিক।
         * দেনা ক্রেডিট প্রকৃতির, তাই এখানে দুইটা যুক্তি উল্টে দেওয়া হয়:
         * ক্রেডিটকে "ডেবিট" আর ডেবিটকে "ক্রেডিট" হিসেবে পাঠানো হয়।
         * নাহলে প্রতিটা সারিতে ঋণাত্মক সংখ্যা দেখাত।
         *
         * শুরুর অঙ্ক শূন্য, আর খোলা ব্যালেন্সের জন্য কোনো কৃত্রিম সারিও
         * বসানো হয় না: ওটা এখন লেজারের সত্যিকারের একটা দাখিলা, তাই
         * নিজে থেকেই প্রথম সারি হয়ে আসে (OpeningBalanceService)।
         */
        $opening = '0';

        if ($page > 1) {
            $opening = RunningBalance::sumOf(
                (clone $ledger)->forPage(1, ($page - 1) * $perPage)->get(),
                fn (LedgerEntry $e) => $e->credit,
                fn (LedgerEntry $e) => $e->debit,
                $opening,
            );
        }

        $entries = $ledger->paginate($perPage)->withQueryString();

        $running = new RunningBalance($opening);

        $entries->getCollection()->each(function (LedgerEntry $entry) use ($running) {
            $entry->running_balance = $running->add($entry->credit, $entry->debit);
        });

        return view('supplier::show', [
            'menu' => $this->menu->forUser($request->user()),
            'supplier' => $supplier->load(['partyType', 'paymentTerm', 'branch']),
            'payable' => $supplier->payable(),
            'entries' => $entries,
        ]);
    }

    public function edit(Request $request, Supplier $supplier): View
    {
        return view('supplier::form', [
            'menu' => $this->menu->forUser($request->user()),
            'supplier' => $supplier,
            ...$this->options(),
        ]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->suppliers->update($supplier, $request->validated());

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
            'payable_desc' => fn ($q) => $q->orderByDesc('payable_net')->orderBy('name_en'),
            'payable_asc' => fn ($q) => $q->orderBy('payable_net')->orderBy('name_en'),
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
    private function options(): array
    {
        return [
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            // "both" ধরনগুলোও আসে: একটা প্রতিষ্ঠান একইসাথে গ্রাহক ও
            // সরবরাহকারী হতে পারে, আর দুইবার লিখতে বলার মানে নেই
            'partyTypes' => PartyType::query()->for(PartyType::SUPPLIER)->active()->orderBy('code')->get(),
            'paymentTerms' => PaymentTerm::query()->active()->orderBy('code')->get(),
            'requireBangla' => $this->settings->enabled('supplier.require_bn_name'),
            'requireBin' => $this->settings->enabled('supplier.require_bin'),
        ];
    }
}
