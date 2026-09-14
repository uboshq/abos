<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Security\FieldSecurity;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
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
use Illuminate\Support\Collection;
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
        /*
         * ⓘ আলাদা ভেরিয়েবল, কারণ `formData()`-রও ওটা লাগে — সম্পাদনার
         * সময় কর্মীর নিজের ব্যবহারকারীকে ড্রপডাউনে রাখতে।
         */
        $employee = new Employee([
            'joining_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'is_active' => true,
        ]);

        return view('hr::employee.form', [
            'menu' => $this->menu->forUser($request->user()),
            'employee' => $employee,
            ...$this->formData($employee),
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
            ...$this->formData($employee),
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

        /*
         * পরিচয়ের ঘরগুলো দেখার অনুমতি না থাকলে সেগুলো এখানেই ঝরে যায়।
         *
         * ── কেন কেবল ফর্ম থেকে তুলে দেওয়া যথেষ্ট নয় ─────────────────
         * ফর্মে ঘরটা না থাকলেও একটা হাতে বানানো POST-এ `national_id`
         * পাঠিয়ে দেওয়া যায় — আর তাতে **কেউ না দেখেই একজনের জাতীয়
         * পরিচয়পত্রের নম্বর বদলে দিতে পারতেন**, যেটা ফাঁসের চেয়েও
         * খারাপ: ভুল তথ্যটা তখন খাতায় বসে থাকে।
         *
         * অনুপস্থিত চাবি `$employee->update($data)` ছোঁয় না, তাই
         * আগের মানটা যেমন ছিল তেমনই থাকে।
         */
        foreach (['national_id', 'bank_account_no', 'bank_routing_no', 'mfs_number'] as $guarded) {
            if (! FieldSecurity::visible(Employee::class, $guarded)) {
                $request->request->remove($guarded);
            }
        }

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
    /**
     * ⓘ `$employee` লাগে শুধু একটা কারণে: সম্পাদনার সময় তাঁর নিজের
     * ব্যবহারকারীটা ড্রপডাউনে রাখতে (`taggableUsers`)। নতুন কর্মীর
     * বেলায় খালি মডেলটাই যথেষ্ট, তার `id` নেই।
     *
     * @return array<string, mixed>
     */
    private function formData(Employee $employee): array
    {
        return [
            'branches' => Branch::query()->orderBy('code')->get(),
            'departments' => Department::query()->active()->orderBy('code')->get(),
            'designations' => Designation::query()->active()->orderBy('code')->get(),
            'employmentTypes' => EmploymentType::query()->active()->orderBy('code')->get(),
            'paymentMethods' => Employee::PAYMENT_METHODS,
            'taggableUsers' => $this->taggableUsers($employee),
        ];
    }

    /**
     * ⛔ যে ব্যবহারকারীদের সাথে এই কর্মীকে জোড়া যায় — ১৩ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * `hr_employees.user_id` কলামটা **২০২৬-০৮-০৯ থেকেই আছে**, মডেলের
     * `$fillable`-এ আছে, `user()` সম্পর্ক আছে, আর নিচে `validated()`-এ
     * তার জন্য একটা যত্ন করে লেখা নিয়মও আছে (nullable + exists +
     * unique), পাশে মন্তব্য: *"দুইজন কর্মী এক লগইনে বাঁধা থাকলে 'এই
     * এন্ট্রিটা কে করেছে' প্রশ্নের দুইটা উত্তর হত।"*
     *
     * ⛔ কিন্তু **ফর্মে ঘরটা কোনোদিন আঁকা হয়নি**, আর তালিকাটাও কখনো
     * পাঠানো হয়নি। ⚠️ অর্থাৎ ভ্যালিডেশনটা এমন একটা ঘর পাহারা দিচ্ছিল
     * **যা কেউ পাঠাতেই পারত না** — নিয়ম লেখা, অথচ অপৌঁছানো।
     *
     * ⓘ ফলটা কেবল এই পর্দার নয়: ফুটারে কর্মীর **পদবি** দেখাতে হলে এই
     * সংযোগটাই লাগে, আর সংযোগ না থাকায় কারও পদবিই জানা যেত না।
     *
     * ── ⚠️ কোম্পানির ছাঁকনি — এটা ঐচ্ছিক নয় ─────────────────────────
     * [[User]] [[BaseEntity]]-র গ্লোবাল স্কোপ পায় না; সে `companies`
     * পিভটে ঝোলে, তাই ছাঁকনিটা **হাতে বসাতে হয়**। ⛔ না বসালে এক
     * কোম্পানির HR অন্য কোম্পানির প্রতিটা ব্যবহারকারীর নাম ও ইমেইল
     * দেখতেন — আর CLAUDE.md-তে টেন্যান্ট বিচ্ছিন্নতা সুবিধা নয়,
     * **আইনি বাধ্যবাধকতা**। ⓘ ছাঁচটা রিপোতে বহু জায়গায় আছে, আর
     * [[EveryUserListAsksWhichCompanyTest]] সেটা পাহারা দেয়।
     *
     * ── ⭐ কেন ইতিমধ্যে জোড়া ব্যবহারকারীরা তালিকায় নেই ───────────────
     * একটা লগইন একজন কর্মীর সাথেই জোড়া যায় (নিচের `unique` নিয়ম)।
     * ⚠️ তালিকায় রাখলে মানুষ একজনকে বেছে নিয়ে সেভ চেপে ধমক খেতেন —
     * *"যে বোতাম মিথ্যা বলে সেটা না থাকা বোতামের চেয়ে খারাপ"*।
     *
     * ⛔ তবে **এই কর্মীর নিজের জোড়াটা বাদ দেওয়া যাবে না** — নাহলে নাম
     * শুধরাতে গিয়ে সেভ করলেই সংযোগটা নীরবে মুছে যেত, কারণ ড্রপডাউনে
     * তাঁর নিজের ব্যবহারকারীই থাকত না।
     *
     * @return Collection<int, User>
     */
    private function taggableUsers(Employee $employee): Collection
    {
        $taken = Employee::query()
            ->whereNotNull('user_id')
            ->whereKeyNot($employee->id)
            ->pluck('user_id');

        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->whereNotIn('id', $taken)
            ->orderBy('name')
            ->get();
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
