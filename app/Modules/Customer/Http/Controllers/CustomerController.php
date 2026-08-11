<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\SortsLists;
use App\Core\Panels\FactRegistry;
use App\Core\Services\CustomFieldService;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\RunningBalance;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Modules\Customer\Http\Requests\CustomerRequest;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * গ্রাহকের স্ক্রিন।
 *
 * কন্ট্রোলার শুধু অনুরোধ নেয় ও উত্তর দেয় — সেকশন ১৯.৬। কোড কীভাবে তৈরি
 * হয়, বাংলা নাম লাগবে কি না, খোলা ব্যালেন্স কেন সম্পাদনায় বদলানো যায় না:
 * সবই CustomerService-এ।
 */
class CustomerController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use SortsLists;

    public function __construct(
        private readonly CustomerService $customers,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,

        /*
         * বাকি মডিউলরা এই গ্রাহক সম্পর্কে যা জানে।
         *
         * Customer নিজে কারও ভেতরে হাত দেয় না — কোরকে জিজ্ঞেস করে,
         * আর কোর রেজিস্ট্রি হেঁটে উত্তর জোগাড় করে। কে কী দিল তা এই
         * কন্ট্রোলার জানে না, জানার দরকারও নেই।
         */
        private readonly FactRegistry $facts,
    ) {}

    /** সাতটা পদ্ধতির সাতটা অনুমতি — একটাও হাতে লেখা নয়। */
    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Customer::class, 'customer'),

            // ফিরিয়ে আনাও নিষ্ক্রিয় করার অনুমতিতেই — নাহলে সুইচের
            // একদিকে তালা থাকত, অন্যদিকে নয়
            new Middleware('can:delete,customer', only: ['activate']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Customer::query()
            ->search($request->query('q'))
            ->when($request->boolean('inactive') === false, fn ($q) => $q->active())
            ->with('partyType')
            // বকেয়া সারির সাথেই আসে, নাহলে ৫০ সারিতে ৫০টা কোয়েরি
            ->withOutstanding();

        $sort = $this->applySort($query, $request, $this->sorts());

        $customers = $query
            // পেজিনেশন বাধ্যতামূলক (সেকশন ৯) — শেয়ার্ড হোস্টে পুরো তালিকা
            // এক রেসপন্সে পাঠানো মানে টাইমআউট।
            ->paginate(50)
            ->withQueryString();

        return view('customer::index', [
            'menu' => $this->menu->forUser($request->user()),
            'customers' => $customers,
            'q' => $request->query('q'),
            'showInactive' => $request->boolean('inactive'),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
        ]);
    }

    public function create(Request $request): View
    {
        return view('customer::form', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => new Customer(['credit_limit' => 0, 'credit_days' => 0, 'is_active' => true]),
            ...$this->options(),
        ]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = $this->customers->create($request->validated());

        /*
         * নিজস্ব ঘরগুলো আলাদা করে — সেবার ভেতরে নয়।
         *
         * CustomerService জানে গ্রাহকের কী কী ঘর আছে; নিজস্ব ঘরগুলো
         * কোম্পানি চালানোর সময় বানায়, তাই সেবার কোনো ধারণাই নেই ওগুলোর
         * সম্পর্কে। সেবায় ঢোকালে প্রতিটা মডিউলের প্রতিটা সেবায় একই
         * কোড বসত।
         */
        app(CustomFieldService::class)->save($customer, $request->input('custom', []));

        return redirect()
            ->route('customer.show', $customer)
            ->with('saved', __('customer::message.created', ['name' => $customer->name()]));
    }

    /**
     * একজন গ্রাহক ও তার লেনদেন।
     *
     * বকেয়ার অঙ্কটার সাথে সেই লেনদেনগুলোও আসে যেগুলো যোগ হয়ে অঙ্কটা
     * হয়েছে — নিয়ম ১। অঙ্কটা দেখিয়ে "কোথা থেকে এল" না বললে ব্যবহারকারীকে
     * বিশ্বাস করতে বলা হয়, দেখতে দেওয়া হয় না।
     */
    public function show(Request $request, Customer $customer): View
    {
        $ledger = LedgerEntry::query()
            ->forParty(Customer::drillSourceType(), $customer->id)
            ->orderBy('trx_date')
            ->orderBy('id');

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        // চলমান ব্যালেন্স শুরু শূন্য থেকে, তারপর আগের পাতাগুলোর সব সারি —
        // নাহলে প্রতিটা পাতা শূন্য থেকে শুরু হত আর শেষ পাতার ব্যালেন্স
        // উপরের বকেয়ার সাথে মিলত না।
        //
        // খোলা ব্যালেন্স আলাদা করে যোগ করা হয় না: ওটা এখন লেজারের
        // সত্যিকারের একটা দাখিলা (OpeningBalanceService), তাই নিজে
        // থেকেই প্রথম সারি হয়ে আসে।
        $opening = '0';

        if ($page > 1) {
            $opening = RunningBalance::sumOf(
                (clone $ledger)->forPage(1, ($page - 1) * $perPage)->get(),
                fn (LedgerEntry $e) => $e->debit,
                fn (LedgerEntry $e) => $e->credit,
                $opening,
            );
        }

        $entries = $ledger->paginate($perPage)->withQueryString();

        $running = new RunningBalance($opening);

        $entries->getCollection()->each(function (LedgerEntry $entry) use ($running) {
            $entry->running_balance = $running->add($entry->debit, $entry->credit);
        });

        /*
         * খোলা ব্যালেন্সের কৃত্রিম সারিটা এখানে আর নেই।
         *
         * আগে ছিল, কারণ অঙ্কটা শুধু গ্রাহকের রেকর্ডে লেখা একটা সংখ্যা
         * ছিল — লেজারে কিছু ছিল না। না দেখালে যোগফল মিলত না: করিমের
         * প্রথম বিল ১১,৫০০ অথচ ব্যালেন্স ২৩,৫০০, আর বাকি ১২,০০০ কোথা
         * থেকে এল তার উত্তর পর্দায় নেই — নিয়ম ১ ঠিক এটাই নিষেধ করে।
         *
         * কিন্তু ওটা উপসর্গের চিকিৎসা ছিল। আসল সমস্যা ছিল অঙ্কটা খাতায়
         * না বসা, আর সেটা এই পাতাতেই থামত না — ট্রায়াল ব্যালেন্স ও
         * বকেয়া তালিকাতেও সংখ্যাটা উধাও থাকত। এখন খোলা ব্যালেন্স
         * সত্যিকারের দাখিলা, তাই সারিটা নিজে থেকেই আসে।
         */

        return view('customer::show', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => $customer,
            'outstanding' => $customer->outstanding(),
            'entries' => $entries,
            'creditLimitOn' => $this->settings->enabled('customer.credit_limit_enabled'),
            'facts' => $this->facts->forRecord(Customer::drillSourceType(), $customer->id),
        ]);
    }

    public function edit(Request $request, Customer $customer): View
    {
        return view('customer::form', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => $customer,
            ...$this->options(),
        ]);
    }

    /**
     * কোন বাছাই কী করে।
     *
     * প্রথমটাই ডিফল্ট, আর সেটা "সবচেয়ে বেশি বকেয়া আগে": তালিকাটা
     * খোলার আসল কারণ প্রায় সবসময় "কার কাছে টাকা আটকে আছে", বর্ণ
     * অনুযায়ী কে কোথায় তা নয়।
     *
     * @return array<string, callable(Builder): mixed>
     */
    private function sorts(): array
    {
        return [
            'due_desc' => fn ($q) => $q->orderByDesc('outstanding_net')->orderBy('name_en'),
            'due_asc' => fn ($q) => $q->orderBy('outstanding_net')->orderBy('name_en'),
            'name' => fn ($q) => $q->orderBy('name_en'),
            'code' => fn ($q) => $q->orderBy('code'),
            'recent' => fn ($q) => $q->orderByDesc('created_at'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'due_desc' => __('customer::sort.due_desc'),
            'due_asc' => __('customer::sort.due_asc'),
            'name' => __('customer::sort.name'),
            'code' => __('customer::sort.code'),
            'recent' => __('customer::sort.recent'),
        ];
    }

    /**
     * ফর্মের ড্রপডাউন ও সুইচগুলো — তৈরি ও সম্পাদনা দুই জায়গায় এক।
     *
     * আগে দুইবার লেখা ছিল, আর ধরনের ড্রপডাউন যোগ করতে গিয়ে দেখা গেল
     * একটায় বসিয়ে অন্যটায় ভুলে যাওয়া কতটা সহজ — সেটাই One Form
     * Standard-এর পেছনের যুক্তি (সেকশন ১৫.২৪)।
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),

            /*
             * দোকান বসে গাছের নিচের ধাপে, তাই কেবল সেগুলোই দেখানো হয়।
             *
             * পুরো গাছ দেখালে কেউ একদিন একটা দোকানকে "বাংলাদেশ"-এ বসিয়ে
             * দিতেন, আর তালিকায় তার এরিয়া ফাঁকা থাকত — কারণ দেশের উপরে
             * এরিয়া খুঁজে পাওয়া যায় না।
             *
             * টেরিটরি ও রুটও আছে: কোনো ডিপো পয়েন্ট পর্যন্ত নামে না, আবার
             * কেউ রুট পর্যন্ত যায়। ধাপটা কড়া করে বাঁধলে যে কোম্পানির
             * পয়েন্ট নেই তার গ্রাহক কোথাও বসানো যেত না।
             */
            'locations' => Location::query()
                ->atLevel([Location::TERRITORY, Location::POINT, Location::ROUTE])
                ->active()
                ->orderBy('name_en')
                ->get(),
            // "both" ধরনগুলোও আসে: একটা প্রতিষ্ঠান একইসাথে গ্রাহক ও
            // সরবরাহকারী হতে পারে, আর দুইবার লিখতে বলার মানে নেই
            'partyTypes' => PartyType::query()->for(PartyType::CUSTOMER)->active()->orderBy('code')->get(),
            'requireBangla' => $this->settings->enabled('customer.require_bn_name'),
            'creditLimitOn' => $this->settings->enabled('customer.credit_limit_enabled'),
        ];
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->update($customer, $request->validated());

        app(CustomFieldService::class)->save($customer, $request->input('custom', []));

        return redirect()
            ->route('customer.show', $customer)
            ->with('saved', __('customer::message.updated', ['name' => $customer->name()]));
    }

    /**
     * মোছা নয়, নিষ্ক্রিয় করা — নিয়ম ৫।
     *
     * রুটটার নাম destroy, কারণ Laravel-এর resource ছক তাই; কিন্তু কাজটা
     * নিষ্ক্রিয় করা, আর বার্তাটাও সেটাই বলে।
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        $this->customers->deactivate($customer);

        return redirect()
            ->route('customer.index')
            ->with('saved', __('customer::message.deactivated', ['name' => $customer->name()]));
    }

    /**
     * আবার সক্রিয় করা।
     *
     * নিষ্ক্রিয় করা একমুখী দরজা হলে ব্যবহারকারী ভুল করে বন্ধ করা
     * গ্রাহকের জন্য দ্বিতীয় একটা রেকর্ড খুলত — একই দোকান দুইবার,
     * দুইটা আলাদা বকেয়া নিয়ে। সেটাই সবচেয়ে খারাপ ফল।
     */
    public function activate(Customer $customer): RedirectResponse
    {
        $this->customers->activate($customer);

        return redirect()
            ->route('customer.show', $customer)
            ->with('saved', __('customer::message.activated'));
    }
}
