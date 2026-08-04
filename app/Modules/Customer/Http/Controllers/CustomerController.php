<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Customer\Http\Requests\CustomerRequest;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * গ্রাহকের স্ক্রিন।
 *
 * কন্ট্রোলার শুধু অনুরোধ নেয় ও উত্তর দেয় — সেকশন ১৯.৬। কোড কীভাবে তৈরি
 * হয়, বাংলা নাম লাগবে কি না, খোলা ব্যালেন্স কেন সম্পাদনায় বদলানো যায় না:
 * সবই CustomerService-এ।
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {
        $this->authorizeResource(Customer::class, 'customer');
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

    public function show(Request $request, Customer $customer): View
    {
        return view('customer::show', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => $customer,
            'outstanding' => $customer->outstanding(),
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
