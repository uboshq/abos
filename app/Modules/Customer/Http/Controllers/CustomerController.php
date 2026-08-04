<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\RunningBalance;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Modules\Customer\Http\Requests\CustomerRequest;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
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

    public function __construct(
        private readonly CustomerService $customers,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    /** সাতটা পদ্ধতির সাতটা অনুমতি — একটাও হাতে লেখা নয়। */
    public static function middleware(): array
    {
        return static::resourcePermissions(Customer::class, 'customer');
    }

    public function index(Request $request): View
    {
        $customers = Customer::query()
            ->search($request->query('q'))
            ->when($request->boolean('inactive') === false, fn ($q) => $q->active())
            ->orderBy('name_en')
            // পেজিনেশন বাধ্যতামূলক (সেকশন ৯) — শেয়ার্ড হোস্টে পুরো তালিকা
            // এক রেসপন্সে পাঠানো মানে টাইমআউট।
            ->paginate(50)
            ->withQueryString();

        return view('customer::index', [
            'menu' => $this->menu->forUser($request->user()),
            'customers' => $customers,
            'q' => $request->query('q'),
            'showInactive' => $request->boolean('inactive'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('customer::form', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => new Customer(['credit_limit' => 0, 'credit_days' => 0, 'is_active' => true]),
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            'requireBangla' => $this->settings->enabled('customer.require_bn_name'),
            'creditLimitOn' => $this->settings->enabled('customer.credit_limit_enabled'),
        ]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = $this->customers->create($request->validated());

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

        // চলমান ব্যালেন্স শুরু হয় খোলা ব্যালেন্স থেকে, তারপর আগের
        // পাতাগুলোর সব সারি — নাহলে প্রতিটা পাতা শূন্য থেকে শুরু হত আর
        // শেষ পাতার ব্যালেন্স উপরের বকেয়ার সাথে মিলত না।
        $opening = (string) $customer->opening_balance;

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

        // খোলা ব্যালেন্স নিজেও একটা সারি — প্রথম পাতায়, সবার উপরে।
        //
        // না দিলে যোগফল মেলে না: করিমের প্রথম বিল ১১,৫০০ অথচ ব্যালেন্স
        // দেখায় ২৩,৫০০, আর বাকি ১২,০০০ কোথা থেকে এল তার কোনো উত্তর
        // পর্দায় নেই। নিয়ম ১ ঠিক এটাই নিষেধ করে।
        //
        // সারিটা সেভ করা হয় না — এটা লেজারের এন্ট্রি নয়, গ্রাহকের রেকর্ডে
        // লেখা একটা সংখ্যা। দেখানোর জন্য একই আকার দেওয়া হচ্ছে যাতে
        // টেবিলটার আলাদা কোনো নিয়ম না লাগে।
        if ($page === 1 && bccomp((string) $customer->opening_balance, '0', 4) !== 0) {
            $opening = new LedgerEntry([
                'trx_date' => $customer->opening_date,
                'debit' => $customer->opening_balance,
                'credit' => 0,
                'narration' => __('customer::message.opening_row'),
            ]);

            $opening->running_balance = (string) $customer->opening_balance;

            $entries->setCollection($entries->getCollection()->prepend($opening));
        }

        return view('customer::show', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => $customer,
            'outstanding' => $customer->outstanding(),
            'entries' => $entries,
            'creditLimitOn' => $this->settings->enabled('customer.credit_limit_enabled'),
        ]);
    }

    public function edit(Request $request, Customer $customer): View
    {
        return view('customer::form', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => $customer,
            'branches' => Branch::query()->active()->orderBy('name_en')->get(),
            'requireBangla' => $this->settings->enabled('customer.require_bn_name'),
            'creditLimitOn' => $this->settings->enabled('customer.credit_limit_enabled'),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->update($customer, $request->validated());

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
}
