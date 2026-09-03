<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\CompanyProvisioner;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CodeFromName;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * কোম্পানি ও শাখা — খোলা, সম্পাদনা, আর নতুন শাখা যোগ।
 *
 * ── কেন এই পর্দাটা এতদিন ছিল না, আর কেন থাকা দরকার ─────────────────
 * কোম্পানি বসত কেবল সিডারে। অর্থাৎ ABOS চালু করতে গেলে কাউকে কমান্ড
 * লাইনে গিয়ে কোড লিখে দিতে হত — লগইন করা মালিক নিজে পারতেন না।
 * পরীক্ষায় ধাপ ১ ঠিক এখানেই আটকে গিয়েছিল: "একাধিক কোম্পানি, একাধিক
 * শাখা" লেখা থাকা সত্ত্বেও কোথাও তৈরির কোনো পথ নেই।
 *
 * ── কোম্পানি মোছা যায় না, আর সেটা ইচ্ছাকৃত ──────────────────────────
 * একটা কোম্পানি মানে তার প্রতিটা বিল, চালান, খতিয়ানের সারি আর
 * ব্যাংকের মিলান। ওটা মুছে ফেলার কোনো বোতাম থাকা উচিত নয় — ভুল করে
 * চাপলে ফেরার পথ নেই। নিষ্ক্রিয় করা যায়: তখন সুইচারে আর আসে না,
 * কিন্তু পুরনো সব কাগজ অক্ষত থাকে।
 */
class CompanyController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.company.manage')];
    }

    public function index(Request $request): View
    {
        return view('system_admin::company.index', [
            'menu' => $this->menu->forUser($request->user()),

            /*
             * সব কোম্পানি, কেবল চলতিটা নয়।
             *
             * Company-তে টেন্যান্ট স্কোপ নেই — থাকলে এই পর্দাটাই
             * অসম্ভব হত, কারণ অন্য কোম্পানিতে যেতে হলে আগে তাকে দেখতে
             * পাওয়া লাগে। যিনি দেখেন তিনি system_admin.company.manage
             * ধারী, অর্থাৎ প্রতিষ্ঠানের মালিক।
             */
            /*
             * শাখা গোনার সময় টেন্যান্ট স্কোপ সরাতে হয়।
             *
             * ── কী ভুল দেখাচ্ছিল ───────────────────────────────────
             * Branch-এ BelongsToCompany গ্লোবাল স্কোপ আছে, তাই
             * withCount('branches') চুপচাপ "AND company_id = চলতি
             * কোম্পানি" জুড়ে দিত। ফল: নিজের সারিতে ঠিক সংখ্যা, আর
             * বাকি প্রতিটা কোম্পানির সারিতে **শূন্য** — যদিও তাদের
             * শাখা আছে।
             *
             * পরীক্ষায় ধরা পড়েছে: তিনটা শাখা বানানোর পরেও তালিকায়
             * ০ দেখাচ্ছিল। কোনো ত্রুটিবার্তা নেই, শুধু ভুল সংখ্যা।
             *
             * স্কোপটা এখানেই সরানো নিরাপদ: এই পাতাটা খোলেন
             * system_admin.company.manage ধারী, যাঁর কাছে সব কোম্পানিই
             * নিজের — আর গোনা হচ্ছে কেবল সংখ্যা, কারো ডাটা নয়।
             */
            'companies' => Company::query()
                ->withCount(['branches' => fn ($q) => $q->withoutGlobalScopes()])
                ->orderBy('name_en')
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('system_admin::company.form', [
            'menu' => $this->menu->forUser($request->user()),
            'company' => new Company,
            'year' => CompanyProvisioner::currentBangladeshiYear(),
        ]);
    }

    public function store(Request $request, CompanyProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            /*
             * খালি রাখা যায় — নাম থেকে বসে (২ সেপ্টেম্বর ২০২৬)।
             *
             * কোম্পানির কোড লগইনের পর্দায় ও প্রতিটা ডকুমেন্ট নম্বরে
             * বসে, তাই ওটা পড়ার মতো হওয়া দরকার — `Trade Depot` →
             * `TRA`, `CMP-0002` নয়।
             */
            'code' => ['nullable', 'string', 'max:16', 'alpha_dash', Rule::unique('companies', 'code')],
            'name_en' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_bn' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'bin' => ['nullable', 'string', 'max:32'],
            'tin' => ['nullable', 'string', 'max:32'],

            // প্রধান শাখা — কোম্পানির সাথেই, পরে নয়
            // শাখার কোডও — খালি হলে শাখার নাম থেকে
            'branch_code' => ['nullable', 'string', 'max:16', 'alpha_dash'],
            'branch_name_en' => ['required', 'string', 'max:160'],
            'branch_name_bn' => ['nullable', 'string', 'max:160'],

            'year_name' => ['required', 'string', 'max:32'],
            'year_starts_on' => ['required', 'date'],
            'year_ends_on' => ['required', 'date', 'after:year_starts_on'],
        ]);

        /*
         * কোড না লিখলে নাম থেকে — মালিকের নিয়ম, ২ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ কোম্পানির কোড টেন্যান্টের সীমার **বাইরে** অনন্য হতে হয়,
         * কারণ তখনো কোনো কোম্পানি প্রসঙ্গ নেই — এটাই তো প্রথম কোম্পানি
         * বানানোর মুহূর্ত। তাই [[CodeSuggester]] নয়,
         * [[CodeFromName::forQuery()]] সরাসরি: স্কোপটা এখানে গোটা
         * টেবিল, আর সেটা এখানে **ইচ্ছাকৃত**।
         */
        $companyCode = trim((string) ($data['code'] ?? '')) !== ''
            ? strtoupper($data['code'])
            : CodeFromName::forQuery($data['name_en'], Company::query());

        $branchCode = trim((string) ($data['branch_code'] ?? '')) !== ''
            ? strtoupper($data['branch_code'])
            : CodeFromName::forQuery(
                $data['branch_name_en'],
                Branch::query()->withoutGlobalScopes(),
            );

        /*
         * ⚠️ নাম থেকে কোড না বসলে **থামা** — খালি স্ট্রিং সংরক্ষণ নয়।
         *
         * ── কী ঘটত ───────────────────────────────────────────────────
         * `CodeFromName::base()` ইংরেজি অক্ষর ছাড়া সব ফেলে দেয় (তার
         * নিজের মন্তব্যে: "বাংলা নামে কিছুই টেকে না")। তাই পুরো বাংলা
         * নাম দিয়ে কোডের ঘর খালি রাখলে সে **খালি স্ট্রিং** ফেরত দিত,
         * আর সেটা নীরবে বসে যেত।
         *
         * কোথাও কিছু ভাঙত না। শুধু কোম্পানির কোড প্রতিটা ডকুমেন্ট
         * নম্বরে বসে, তাই **প্রতিটা চালান-বিলের নম্বর একটা হাইফেন দিয়ে
         * শুরু হত** — আর ধরা পড়ত ছয় মাস পর, যখন নম্বরগুলো আর বদলানো
         * যায় না।
         *
         * ── কেন কোড বানানোর চেষ্টা করা হয় না ─────────────────────────
         * মালিকের সিদ্ধান্ত, ৩ সেপ্টেম্বর ২০২৬: **"কোনো কোড বাংলাতে
         * দেওয়ার দরকার নাই"** — কোড সবসময় ইংরেজি অক্ষরে, কারণ ওটা
         * রিপোর্ট, ছাপা আর রপ্তানির ফাইলে যায়। বাংলা নাম থেকে ASCII
         * সংক্ষেপ বানানো যায় না, আর `CMP-0001` বসানোও নিষেধ। তাই
         * একমাত্র সৎ পথ: **মানুষকে কোডটা জিজ্ঞেস করা**।
         *
         * ── কেন এটা এখানে নতুন করে লেখা ──────────────────────────────
         * নিয়মটা রিপোতে আগে থেকেই আছে আর ঠিকভাবেই লেখা —
         * [[MasterListService::assertCodeIsFree()]] খালি কোডে
         * `ValidationException` ছোঁড়ে। **শুধু এই কন্ট্রোলারটাই ওটা
         * মানত না** (৩ সেপ্টেম্বর ২০২৬-এ গুনে দেখা: বাকি চারটা ব্যবহারই
         * সুরক্ষিত)।
         */
        $this->assertCodeExists($companyCode, 'code', $data['name_en']);
        $this->assertCodeExists($branchCode, 'branch_code', $data['branch_name_en']);

        $company = $provisioner->create(
            data: [
                'code' => $companyCode,
                'name_en' => $data['name_en'],
                'name_bn' => $data['name_bn'] ?? null,
                'legal_name' => $data['legal_name'] ?? null,
                'address_en' => $data['address_en'] ?? null,
                'address_bn' => $data['address_bn'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'bin' => $data['bin'] ?? null,
                'tin' => $data['tin'] ?? null,
            ],
            branch: [
                'code' => $branchCode,
                'name_en' => $data['branch_name_en'],
                'name_bn' => $data['branch_name_bn'] ?? null,
                'is_default' => true,
            ],
            year: [
                'name' => $data['year_name'],
                'starts_on' => $data['year_starts_on'],
                'ends_on' => $data['year_ends_on'],
            ],
        );

        /*
         * যিনি বানালেন, তিনি ঢুকতে পারবেন।
         *
         * এটা না করলে নতুন কোম্পানিটা তালিকায় দেখা যেত কিন্তু সুইচারে
         * আসত না — আর কেন আসছে না তার কোনো ব্যাখ্যাও পর্দায় থাকত না।
         */
        $provisioner->grantAccess($company, $request->user());

        return redirect()
            ->route('system_admin.company.index')
            ->with('saved', __('system_admin::message.company_created', ['name' => $company->name()]));
    }

    /**
     * কোডটা সত্যিই বসেছে কি — না বসলে কারণসহ থামা।
     *
     * ── কেন বার্তাটা নামটা ফেরত বলে ──────────────────────────────────
     * "কোড দিতে হবে" পড়ে মানুষ ভাবেন ঘরটা বাধ্যতামূলক, অথচ পাশের
     * কোম্পানিটা কোড ছাড়াই খুলেছিল। **নিয়মটা ঘরের নয়, নামের** — তাই
     * বার্তায় নামটা দেখিয়ে বলা হয় কেন এই নামটা থেকে কোড বসল না।
     *
     * @throws ValidationException
     */
    private function assertCodeExists(string $code, string $field, string $name): void
    {
        if ($code !== '') {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('system_admin::validation.code_needs_latin', ['name' => $name]),
        ]);
    }

    public function edit(Request $request, Company $company): View
    {
        return view('system_admin::company.form', [
            'menu' => $this->menu->forUser($request->user()),
            'company' => $company,
            'year' => null,
            'branches' => CompanyContext::forCompany(
                $company->id,
                fn () => Branch::query()->orderBy('code')->get(),
            ),
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            /*
             * কোডটা বদলানো যায় না।
             *
             * ছাপা কাগজে, রপ্তানি করা ফাইলে আর ব্যাংকের বিবরণীতে ওটা
             * বসে যায়। বদলালে পুরনো কাগজ আর নতুন খাতা দুইটা আলাদা
             * প্রতিষ্ঠানের মতো দেখাত।
             */
            'name_en' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_bn' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'bin' => ['nullable', 'string', 'max:32'],
            'tin' => ['nullable', 'string', 'max:32'],
        ]);

        $company->update($data);

        return redirect()
            ->route('system_admin.company.index')
            ->with('saved', __('system_admin::message.company_updated'));
    }

    /** নতুন শাখা — চলতি নয়, যে কোম্পানির পাতা খোলা আছে তার। */
    public function storeBranch(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'alpha_dash'],
            'name_en' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        CompanyContext::forCompany($company->id, function () use ($data) {
            $exists = Branch::query()->where('code', strtoupper($data['code']))->exists();

            if ($exists) {
                abort(422, __('system_admin::message.branch_code_taken'));
            }

            Branch::create([
                ...$data,
                'code' => strtoupper($data['code']),

                // প্রথম শাখাটাই ডিফল্ট — নইলে নতুন লেনদেনে কোনটা বসবে
                // তা নির্ধারিত থাকত না
                'is_default' => ! Branch::query()->where('is_default', true)->exists(),
            ]);
        });

        return back()->with('saved', __('system_admin::message.branch_created'));
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয়।
     *
     * চলতি কোম্পানিটা নিষ্ক্রিয় করা যায় না: করলে ব্যবহারকারী ঠিক ওই
     * মুহূর্তে এমন একটা কোম্পানিতে বসে থাকতেন যেটা আর নেই, আর পরের
     * ক্লিকেই সব পর্দা ভাঙত।
     */
    public function toggle(Company $company): RedirectResponse
    {
        if ($company->id === CompanyContext::id() && $company->is_active) {
            return back()->withErrors([
                'is_active' => __('system_admin::message.cannot_disable_current'),
            ]);
        }

        $company->update(['is_active' => ! $company->is_active]);

        return back()->with('saved', $company->is_active
            ? __('system_admin::message.company_enabled')
            : __('system_admin::message.company_disabled'));
    }
}
