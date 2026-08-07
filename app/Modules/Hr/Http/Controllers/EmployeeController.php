<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use App\Modules\MasterData\Models\EmploymentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * কর্মীর তালিকা ও ফর্ম।
 */
class EmployeeController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly SalaryStructureService $salaries,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.employee.view', only: ['index', 'show']),
            new Middleware('can:hr.employee.manage', only: ['create', 'store', 'edit', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Employee::query()
            ->search($request->query('q'))
            ->with(['department', 'designation', 'employmentType']);

        /*
         * ছেড়ে যাওয়া কর্মীরা ডিফল্টে তালিকায় নেই।
         *
         * সুইচটা কোম্পানির, কিন্তু পর্দার চেকবক্সও আছে — কারণ "গত বছর
         * কে কে ছিল" প্রশ্নটা মাঝে মাঝে ওঠে, আর তার জন্য সেটিংসে গিয়ে
         * সুইচ বদলে আবার ফিরে আসাটা কাজের পথ নয়।
         */
        $showLeft = $request->boolean('left')
            || (bool) $this->settings->get('hr.show_left_employees');

        if (! $showLeft) {
            $query->whereNull('leaving_date');
        }

        $sort = $this->applySort($query, $request, [
            'code' => fn ($q) => $q->orderBy('code'),
            'name' => fn ($q) => $q->orderBy('name_en'),
            'newest' => fn ($q) => $q->orderByDesc('joining_date')->orderByDesc('id'),
            'department' => fn ($q) => $q->orderBy('department_id')->orderBy('code'),
        ]);

        return view('hr::employee.index', [
            'menu' => $this->menu->forUser($request->user()),
            'employees' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showLeft' => $showLeft,
        ]);
    }

    public function create(Request $request): View
    {
        return view('hr::employee.form', [
            'menu' => $this->menu->forUser($request->user()),
            'employee' => new Employee([
                'joining_date' => now()->toDateString(),
                'payment_method' => 'cash',
                'is_active' => true,
            ]),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = $this->employees->create($this->validated($request));

        return redirect()
            ->route('hr.employee.salary', $employee)
            ->with('saved', __('hr::message.employee_created'));
    }

    public function show(Request $request, Employee $employee): View
    {
        $employee->load(['department', 'designation', 'employmentType', 'branch', 'creator']);

        return view('hr::employee.show', [
            'menu' => $this->menu->forUser($request->user()),
            'employee' => $employee,
            'components' => $request->user()?->can('hr.salary.view')
                ? $this->salaries->componentsOn($employee, now())
                : [],
            'totals' => $request->user()?->can('hr.salary.view')
                ? $this->salaries->totalsOn($employee, now())
                : null,
        ]);
    }

    public function edit(Request $request, Employee $employee): View
    {
        return view('hr::employee.form', [
            'menu' => $this->menu->forUser($request->user()),
            'employee' => $employee,
            ...$this->formData(),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->employees->update($employee, $this->validated($request, $employee));

        return redirect()
            ->route('hr.employee.show', $employee)
            ->with('saved', __('hr::message.employee_updated'));
    }

    /** চাকরির অবসান — মোছা নয়, কারণ পুরনো বেতনশিটে নামটা লাগে। */
    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        $date = $request->validate([
            'leaving_date' => ['required', 'date'],
        ])['leaving_date'];

        $this->employees->endEmployment($employee, $date);

        return redirect()
            ->route('hr.employee.show', $employee)
            ->with('saved', __('hr::message.employment_ended'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Employee $employee = null): array
    {
        $companyId = CompanyContext::id();

        return $request->validate([
            // খালি রাখলে সিরিজ থেকে বসে — EmployeeService::create() দেখুন
            'code' => ['nullable', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'father_name' => ['nullable', 'string', 'max:120'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'national_id' => ['nullable', 'string', 'max:32'],

            'branch_id' => ['nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'department_id' => ['nullable', 'integer',
                Rule::exists('mdm_departments', 'id')->where('company_id', $companyId)],
            'designation_id' => ['nullable', 'integer',
                Rule::exists('mdm_designations', 'id')->where('company_id', $companyId)],
            'employment_type_id' => ['nullable', 'integer',
                Rule::exists('mdm_employment_types', 'id')->where('company_id', $companyId)],

            /*
             * ব্যবহারকারীর সাথে জোড়া একটাই কর্মীতে।
             *
             * দুইজন কর্মী এক লগইনে বাঁধা থাকলে "এই এন্ট্রিটা কে করেছে"
             * প্রশ্নের দুইটা উত্তর হত।
             */
            'user_id' => ['nullable', 'integer', 'exists:users,id',
                Rule::unique('hr_employees', 'user_id')
                    ->where('company_id', $companyId)
                    ->ignore($employee?->id)
                    ->whereNull('deleted_at')],

            'joining_date' => ['required', 'date'],
            'leaving_date' => ['nullable', 'date'],

            'payment_method' => ['required', Rule::in(Employee::PAYMENT_METHODS)],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'bank_account_no' => ['nullable', 'string', 'max:64'],
            'bank_routing_no' => ['nullable', 'string', 'max:32'],
            'mfs_number' => ['nullable', 'string', 'max:32'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'branches' => Branch::query()->orderBy('code')->get(),
            'departments' => Department::query()->active()->orderBy('code')->get(),
            'designations' => Designation::query()->active()->orderBy('code')->get(),
            'employmentTypes' => EmploymentType::query()->active()->orderBy('code')->get(),
            'paymentMethods' => Employee::PAYMENT_METHODS,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'code' => __('hr::sort.code'),
            'name' => __('hr::sort.name'),
            'newest' => __('hr::sort.newest'),
            'department' => __('hr::sort.department'),
        ];
    }
}
