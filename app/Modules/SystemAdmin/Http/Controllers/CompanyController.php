<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Image\ImageEngine;
use App\Core\Services\CompanyProvisioner;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CodeFromName;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
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
                /*
                 * ⛔⛔ কেবল **আপনার** কোম্পানিগুলো — ২১ সেপ্টেম্বর ২০২৬।
                 *
                 * ⚠️ উপরের মন্তব্যটা লেখা ছিল "যাঁর কাছে সব কোম্পানিই নিজের",
                 * আর ঐ অনুমানটাই ভুল ছিল: বহু-কোম্পানির ইনস্টলে **প্রতিটা
                 * কোম্পানির নিজের super_admin** এই অনুমতি ধরে রাখেন। ⛔ ফলে
                 * ক কোম্পানির মালিক খ কোম্পানির নাম, কোড, বিআইএন ও টিআইএন
                 * তালিকাতেই পড়ে ফেলতেন।
                 *
                 * ⓘ সুইচ করার যুক্তিটা এতে ভাঙে না — যে কোম্পানিতে আপনি
                 * সত্যিই আছেন, সেটা তালিকায় থাকেই ([[User::canAccessCompany()]]
                 * একই প্রশ্ন, একই উত্তর)।
                 */
                ->whereIn('id', $request->user()?->companies()->pluck('companies.id') ?? [])
                ->withCount(['branches' => fn ($q) => $q->withoutGlobalScopes()])

                /*
                 * টুলবারের খোঁজা — কোড বা নাম (ইংরেজি ও বাংলা দুইটাই),
                 * ১৯ সেপ্টেম্বর ২০২৬।
                 *
                 * ⓘ পর্দায় নামটা ভাষা ধরে বদলায় ([[Company::name()]]), তাই
                 * খোঁজাও দুই ঘরেই — নাহলে বাংলা পর্দায় যে নামটা চোখে দেখা
                 * যাচ্ছে সেটা লিখে কিছুই মিলত না।
                 */
                ->when(trim((string) $request->query('q')) !== '', function ($query) use ($request) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->query('q'))).'%';

                    $query->where(fn ($inner) => $inner
                        ->where('code', 'like', $like)
                        ->orWhere('name_en', 'like', $like)
                        ->orWhere('name_bn', 'like', $like));
                })
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

    /**
     * ⛔⛔ এই কোম্পানিটা কি আদৌ আপনার — ২১ সেপ্টেম্বর ২০২৬।
     *
     * ── ⚠️ যা ভাঙা ছিল ──────────────────────────────────────────
     * `Company`-তে টেন্যান্ট স্কোপ নেই, আর সেটা ইচ্ছাকৃত (অন্য কোম্পানিতে
     * যেতে হলে আগে তাকে দেখতে পাওয়া লাগে)। ⛔ কিন্তু তার মানে রুট
     * বাইন্ডিং ইনস্টলের **যেকোনো** কোম্পানি ধরে আনত, আর একমাত্র পাহারা
     * ছিল `can:system_admin.company.manage` — যেটা **প্রতিটা কোম্পানির
     * নিজের super_admin ধরে রাখেন**।
     *
     * ⓘ ফল: ক কোম্পানির মালিক খ কোম্পানির নাম, বিআইএন, টিআইএন ও ঠিকানা
     * পড়তে ও বদলাতে পারতেন, ভিতরে শাখা বানাতে পারতেন, আর নিষ্ক্রিয়
     * করে খ-এর সব ব্যবহারকারীকে তালাবন্ধ করে দিতে পারতেন।
     *
     * ── কেন "চলতি কোম্পানি" নয়, "আপনার কোম্পানিগুলো" ──────────
     * ⓘ একজন মানুষ সত্যিই দুইটা কোম্পানি চালাতে পারেন, আর তখন অন্যটার
     * সেটিংস খোলার জন্য আগে সেখানে সুইচ করা অর্থহীন হত। ⚠️ তাই প্রশ্নটা
     * "এটা কি চলতি কোম্পানি" নয় — "এটা কি আপনারগুলোর একটা"।
     *
     * ⭐ আর সেই প্রশ্নটার উত্তর আগে থেকেই ছিল: [[User::canAccessCompany()]],
     * ঠিক যেটা কোম্পানি বদলানোর সময় মাপা হয়। দুই দরজায় দুই নিয়ম থাকলে
     * একদিন একটা শিথিল হত।
     *
     * ⚠️ ৪০৪, ৪০৩ নয় — যে কোম্পানিতে আপনার কিছু নেই, তার অস্তিত্ব আছে
     * কি না সেটাও আপনার জানার কথা নয়।
     */
    private function mustBeYourCompany(Request $request, Company $company): void
    {
        abort_unless($request->user()?->canAccessCompany((int) $company->id), 404);
    }

    public function edit(Request $request, Company $company): View
    {
        $this->mustBeYourCompany($request, $company);

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
        $this->mustBeYourCompany($request, $company);

        $data = $request->validate([
            /*
             * ⭐ কোডটা বদলানো যায় — কিন্তু কেবল কাগজ বেরোনোর আগে পর্যন্ত।
             *
             * ⓘ আগে একদম আটকানো ছিল, আর কারণটা ন্যায্য: ছাপা কাগজে, রপ্তানি
             * করা ফাইলে আর ব্যাংকের বিবরণীতে কোডটা বসে যায়। ⛔ কিন্তু ঐ
             * কারণটা কেবল তখনই সত্যি যখন একটা নম্বরও ইস্যু হয়েছে — খালি
             * কোম্পানিতে নিয়মটা কেবল বিরক্তি। মালিক: *"code poriborton
             * hoyna keno?"* (২০ সেপ্টেম্বর ২০২৬)।
             *
             * ⚠️ শর্তটা [[Company::canChangeCode()]]-এ, আর সেটাই একমাত্র
             * জায়গা: পর্দা ঘরটা লুকায় ঐ উত্তর দেখে, আর এখানেও একই উত্তর।
             */
            'code' => [
                Rule::excludeIf(! $this->mayChangeCode($request, $company)),
                'required', 'string', 'max:16', 'alpha_dash',
                Rule::unique('companies', 'code')->ignore($company->id),
            ],

            /*
             * ⚠️ কাগজ বেরিয়ে যাওয়ার পরে কোড বদলাতে হলে পুরনো কোডটা হুবহু
             * লিখতে হয় — বছর খোলার মতোই। ⛔ শর্তটা না থাকলে একটা ভুল ক্লিকে
             * প্রতিষ্ঠানের পরিচয় বদলে যেত, আর ছাপা কাগজের সাথে আর মিলত না।
             */
            'code_confirm' => [
                Rule::requiredIf(fn () => $company->codeChangeNeedsSuperAdmin()
                    && filled($request->input('code'))
                    && strtoupper(trim((string) $request->input('code'))) !== $company->code),
                'nullable', 'string',
            ],

            'name_en' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_bn' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'bin' => ['nullable', 'string', 'max:32'],
            'tin' => ['nullable', 'string', 'max:32'],

            /*
             * প্রতিষ্ঠানের লোগো — ৫ সেপ্টেম্বর ২০২৬।
             *
             * ── কী ছিল, আর কী ছিল না ────────────────────────────────
             * পড়ার দিকটা অনেক আগেই সম্পূর্ণ: `companies.logo_path`
             * কলাম, [[Company::logoUrl()]] পর্দার জন্য, আর
             * [[Company::logoData()]] ছাপার জন্য (base64, কারণ নামে
             * স্পেস থাকলে mPDF পথটা ভেঙে ফেলত)।
             *
             * ⛔ **কেবল ভরার কোনো উপায় ছিল না।** গোটা রিপোতে
             * `logo_path`-এ কিছু লেখা হত এমন একটাও জায়গা ছিল না —
             * ডেমো সিডার ছাড়া। অর্থাৎ যে গ্রাহক ABOS কিনতেন, তাঁর
             * প্রতিটা চালান-বিল ছাপা হত **লোগো ছাড়া**, আর সেটা বসানোর
             * কোনো পথও থাকত না।
             *
             * ⚠️ এটা বিক্রির পণ্যে ছোট ফাঁক নয় — প্রথম দিনেই ধরা পড়ে।
             *
             * ── কেন এখানে, `Attachment` ইঞ্জিনে নয় ──────────────────
             * ⓘ ঐ ইঞ্জিনটা **কাগজের সাথে জোড়া নথি**র জন্য (চালানের
             * ছবি, চুক্তিপত্র) — সেখানে চেকসাম, একাধিক ফাইল, ইতিহাস
             * সবই দরকার। লোগো তার কোনোটাই নয়: একটাই ফাইল, একটাই পথ,
             * আর সেটা কোম্পানির **পরিচয়ের অংশ**, তার কোনো নথি নয়।
             *
             * ⚠️ `svg` ইচ্ছাকৃতভাবে বাদ: SVG-তে স্ক্রিপ্ট বসানো যায়,
             * আর ফাইলটা পরে সরাসরি ব্রাউজারে পরিবেশিত হয়। ⓘ এটা
             * সুবিধার প্রশ্ন নয়, নিরাপত্তার।
             */
            // ⓘ ১২ MB — ইঞ্জিন নিজেই নামিয়ে আনে বলে দরজাটা আর সরু রাখার
            // দরকার নেই; সীমাটা কেবল অস্বাভাবিক ফাইল ঠেকাতে।
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:12288'],

            /*
             * লোগো সরানোর ঘর — আলাদা, কারণ "নতুন ফাইল দিইনি" আর
             * "লোগোটা তুলে দাও" এক কথা নয়। ⛔ এক করলে প্রতিবার সেভ
             * করলেই লোগো মুছে যেত।
             */
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $logo = $this->keepLogo($request, $company);

        unset($data['logo'], $data['remove_logo']);

        /* ⓘ কোডটা সবসময় বড় হাতের — তালিকা, ছাপা কাগজ আর রপ্তানিতে একরকম দেখায় */
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));

            if ($data['code'] !== $company->code && $company->codeChangeNeedsSuperAdmin()
                && trim((string) $request->input('code_confirm')) !== $company->code) {
                return back()->withInput()->withErrors([
                    'code_confirm' => __('system_admin::message.code_confirm_old', ['code' => $company->code]),
                ]);
            }
        }

        unset($data['code_confirm']);

        $company->update([...$data, ...$logo]);

        return redirect()
            ->route('system_admin.company.index')
            ->with('saved', __('system_admin::message.company_updated'));
    }

    /**
     * লোগোর ফাইলটা রাখা — বা তুলে দেওয়া।
     *
     * ── ⚠️ পুরনো ফাইলটা মোছা হয় না, ইচ্ছাকৃতভাবে ────────────────────
     * নতুন লোগো বসালে পুরনোটা ডিস্কে থেকে যায়। প্রথম ঝোঁক হয় ওটা মুছে
     * ফেলার — জায়গা বাঁচে। ⛔ কিন্তু [[IsAudited]] `logo_path`-এর
     * আগের ও পরের মান রাখে, আর ফাইলটা মুছে ফেললে ঐ অডিট সারিটা এমন
     * একটা পথের দিকে দেখাত যেখানে কিছুই নেই।
     *
     * ⓘ "গত বছরের চালানে কোন লোগো ছিল" — এই প্রশ্নটা বিরল, কিন্তু
     * যেদিন ওঠে সেদিন উত্তরটা না থাকলে আর কোনোদিন পাওয়া যায় না। একটা
     * PNG-র দাম তার চেয়ে কম।
     *
     * ── নামটা কেন নতুন করে বানানো হয় ────────────────────────────────
     * ব্যবহারকারীর ফাইলের নাম যা-ই হোক (`লোগো (১).png`, `Trade
     * Depot.png`), সংরক্ষিত নামটা কোম্পানির কোড + সময় + এক্সটেনশন।
     * ⚠️ পুরনো একটা বাগ ঠিক এখানেই ছিল: নামে স্পেস থাকায় mPDF পথটা
     * মাঝপথে কেটে ফেলত ([[Company::logoData()]] দেখুন)। নাম নিজে
     * বানালে ঐ শ্রেণির ভুল আর জন্মায়ই না।
     *
     * @return array<string, string|null>
     */
    private function keepLogo(Request $request, Company $company): array
    {
        if ($request->boolean('remove_logo')) {
            return ['logo_path' => null];
        }

        $file = $request->file('logo');

        if ($file === null) {
            return [];
        }

        /*
         * ⭐ ১৪ সেপ্টেম্বর ২০২৬: এখানে আগে কাঁচা ফাইলটাই বসত।
         *
         * ⓘ মালিকের কথা ছিল *"যেকোনো ফটো আপলোডের সময়"* — লোগোও একটা ফটো
         * আপলোড। ⚠️ আর এই একটা ফাইলের ওজন সবচেয়ে বেশি ছড়ায়, কারণ ওটা
         * **প্রতিটা ছাপা কাগজে** base64 হয়ে যায়।
         *
         * ⛔ ব্যর্থ হলে কাঁচা ফাইলটাই বসে — একটা বড় লোগো থাকা, কোম্পানির
         * লোগো না থাকার চেয়ে ভালো।
         */
        $source = $file->getRealPath();

        if ($source !== false) {
            try {
                $mark = (new ImageEngine)->mark($source);

                $name = $company->code.'-'.now()->format('Ymd-His').'.'.$mark['extension'];
                $path = 'logos/'.$name;

                Storage::disk('public')->put($path, $mark['bytes']);

                return ['logo_path' => $path];
            } catch (\Throwable) {
                // নিচে পড়ে যায়।
            }
        }

        /*
         * ⛔ প্রক্রিয়া হয়নি, অথচ ফাইলটা বড় — নীরবে রাখা যায় না।
         *
         * ⚠️ দরজার সীমা ১২ MB করা হয়েছে এই ভরসায় যে আমরাই ছোট করব।
         * ⓘ সেই কাজটা না হলে ভরসাটা মিথ্যা, আর তখন একটা ১২ MB লোগো
         * **প্রতিটা ছাপা কাগজে** base64 হয়ে বসত (≈১৬ MB)।
         */
        if ((int) $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'logo' => __('core.image.logo_not_processed'),
            ]);
        }

        $name = $company->code.'-'.now()->format('Ymd-His').'.'.$file->extension();

        return ['logo_path' => $file->storeAs('logos', $name, ['disk' => 'public'])];
    }

    /** নতুন শাখা — চলতি নয়, যে কোম্পানির পাতা খোলা আছে তার। */
    public function storeBranch(Request $request, Company $company): RedirectResponse
    {
        $this->mustBeYourCompany($request, $company);

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
    public function toggle(Request $request, Company $company): RedirectResponse
    {
        $this->mustBeYourCompany($request, $company);

        if ($company->id === CompanyContext::id() && $company->is_active) {
            return back()->withErrors([
                'is_active' => __('system_admin::message.cannot_disable_current'),
            ]);
        }

        /*
         * ⛔ শেষ সচল কোম্পানিটা নিষ্ক্রিয় করা যায় না — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ কেন উপরের পাহারাটা যথেষ্ট নয় ─────────────────────────
         * ওটা কেবল **চলতি** কোম্পানিকে বাঁচায়। ⓘ কিন্তু কেউ A-তে
         * দাঁড়িয়ে B নিষ্ক্রিয় করতে পারেন, তারপর B-তে গিয়ে A — আর
         * দুই ধাপে দুইটাই বন্ধ।
         *
         * ⛔ তখন **কেউ কোথাও ঢুকতে পারতেন না**: [[ResolveCompanyContext]]
         * কোনো সচল কোম্পানি না পেলে প্রসঙ্গ খালি রাখে, আর প্রতিটা
         * পর্দা ফাঁকা। ⚠️ ফেরার একমাত্র পথ তখন ডাটাবেজ — অর্থাৎ
         * একটা ক্লিকে নিজেকে বাইরে তালাবদ্ধ করে ফেলা।
         *
         * ⓘ পাহারাটা এখানে, পর্দায় নয়: বোতামটা লুকিয়ে রাখলে ঠিকানা
         * দিয়ে পাঠালে কাজটা তবু হত — আজ রাতেই শাখার মডিউলে ঠিক ঐ
         * ভুলটা ধরা পড়েছে (সুইচ ছিল আড়াল, বাধা নয়)।
         */
        if ($company->is_active && Company::query()->where('is_active', true)->count() <= 1) {
            return back()->withErrors([
                'is_active' => __('system_admin::message.cannot_disable_last_company'),
            ]);
        }

        $company->update(['is_active' => ! $company->is_active]);

        return back()->with('saved', $company->is_active
            ? __('system_admin::message.company_enabled')
            : __('system_admin::message.company_disabled'));
    }

    /**
     * এই অনুরোধে কোডটা বদলানো যাবে কি না।
     *
     * ⓘ দুইটা পথ: কোম্পানিতে এখনো কিছু ঘটেনি (তখন যে কেউ), অথবা
     * সুপার অ্যাডমিন — আর তখন পুরনো কোডটা হুবহু লিখে নিশ্চিত করতে হয়।
     */
    private function mayChangeCode(Request $request, Company $company): bool
    {
        if ($company->canChangeCode()) {
            return true;
        }

        return $request->user()?->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE) ?? false;
    }
}
