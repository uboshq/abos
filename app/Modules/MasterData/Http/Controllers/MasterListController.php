<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CodeFromName;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use App\Modules\MasterData\Models\EmploymentType;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\MasterData\Models\VehicleType;
use App\Modules\MasterData\Services\MasterListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ছয়টা মাস্টার তালিকা, একটা কন্ট্রোলার।
 *
 * ছয়টার আকার এক: কোড, দুই ভাষার নাম, কয়েকটা নিজস্ব ঘর, সক্রিয়তা,
 * ডিফল্ট। ছয়টা আলাদা কন্ট্রোলার লিখলে ছয়বার একই তালিকা-ফর্ম-সংরক্ষণ
 * লিখতে হত, আর সপ্তমটা যোগ করার সময় কেউ একটা ধাপ ভুলে যেত।
 *
 * তালিকাটা URL-এ (/master-data/units, /master-data/taxes), তাই মেনুর
 * ছয়টা সারি ছয়টা আলাদা ঠিকানায় যায় — কিন্তু কোড এক।
 *
 * প্রতিটার নিজস্ব ঘরগুলো KINDS-এ ঘোষিত, আর ফর্মটা সেখান থেকেই তৈরি হয়।
 * নতুন একটা মাস্টার যোগ করতে একটা মডেল, একটা সারি, আর দুইটা ভাষার
 * লাইন — কোনো নতুন স্ক্রিন নয়।
 */
class MasterListController extends Controller implements HasMiddleware
{
    use SortsLists;

    /**
     * ছয়টা তালিকার সংজ্ঞা।
     *
     * প্রতিটার: মডেল, রুটের নাম, আর যে ঘরগুলো ফর্মে দেখাবে। ঘরের ধরন
     * ('text', 'number', 'select', 'switch') ফর্ম ও যাচাই দুইটাই ঠিক
     * করে, তাই দুই জায়গায় দুইবার লিখতে হয় না।
     *
     * @var array<string, array<string, mixed>>
     */
    private const KINDS = [
        /*
         * ব্র্যান্ড ও শ্রেণি — বাড়তি কোনো ঘর নেই, কেবল নাম।
         *
         * আগে দুইটাই পণ্যের ফর্মে মুক্ত লেখা ছিল, আর তাতে একই ব্র্যান্ড
         * কয়েক বানানে বসত ("Nestle", "nestle", "নেসলে")। রোজকার কাজে
         * কেউ টের পেত না; টের পাওয়া যেত ব্র্যান্ড ধরে বিক্রয় খুললে —
         * এক ব্র্যান্ড চার সারিতে ভাগ হয়ে যেত।
         */
        /*
         * খরচের কেন্দ্র — "নেত্রকোনা রুট", "গুদাম", "অফিস"।
         *
         * খাত বলে কী খরচ হয়েছে, কেন্দ্র বলে কোথায়। দুইটা মিলিয়ে তবেই
         * "কোন রুট লাভজনক" প্রশ্নের উত্তর হয়।
         */
        'cost-centers' => [
            'model' => CostCenter::class,
            'route' => 'cost_center',
            'title' => 'master_data::menu.cost_centers',
            'fields' => [],
            'columns' => [],
        ],

        'brands' => [
            'model' => Brand::class,
            'route' => 'brand',
            'title' => 'master_data::menu.brands',
            'fields' => [],
            'columns' => [],
        ],

        'product-categories' => [
            'model' => ProductCategory::class,
            'route' => 'product_category',
            'title' => 'master_data::menu.product_categories',
            'fields' => [],
            'columns' => [],
        ],

        'units' => [
            'model' => Unit::class,
            'route' => 'unit',
            'title' => 'master_data::menu.units',
            'fields' => [
                'base_unit_id' => ['type' => 'select', 'label' => 'master_data::field.base_unit', 'options' => 'units'],
                'factor' => ['type' => 'number', 'label' => 'master_data::field.factor', 'step' => '0.000001'],
                'allows_fraction' => ['type' => 'switch', 'label' => 'master_data::field.allows_fraction'],
            ],
            'columns' => ['factor', 'allows_fraction'],
        ],

        'taxes' => [
            'model' => Tax::class,
            'route' => 'tax',
            'title' => 'master_data::menu.taxes',
            'fields' => [
                'rate' => ['type' => 'number', 'label' => 'master_data::field.rate', 'step' => '0.0001'],
                'kind' => ['type' => 'select', 'label' => 'master_data::field.kind', 'options' => 'tax_kinds', 'labels' => 'kind'],
                'is_inclusive' => ['type' => 'switch', 'label' => 'master_data::field.is_inclusive'],
                'account_id' => ['type' => 'select', 'label' => 'master_data::field.account', 'options' => 'accounts'],
            ],
            'columns' => ['rate', 'kind', 'is_inclusive'],
        ],

        /*
         * কাউন্টারে টাকা নেওয়ার উপায় — নগদ, বিকাশ, কার্ড।
         *
         * খাতটা বাধ্যতামূলক, আর সেটাই সারিটার একমাত্র কাজ: POS ওই খাত
         * দেখেই টাকা বসায়। খাত ছাড়া সারিটা কিছুই বলে না।
         *
         * ── এই মন্তব্যটা জানত, কোডটা জানত না — ২৯ আগস্ট ২০২৬ ────────
         * উপরের লাইনটা প্রথম দিন থেকেই লেখা ছিল, অথচ ঘরটার কোনো
         * `rules` ছিল না — অর্থাৎ যাচাইয়ে ওটা `nullable`। ডাটাবেজে
         * কলামটা `NOT NULL`, তাই নাম লিখে Save চাপলেই **HTTP 500**।
         *
         * HP-র ২৫ আগস্টের রিপোর্ট এটাকে "সবচেয়ে বড় খোলা সমস্যা" বলেছে,
         * আর মালিক নিজে লাইভে "bKash" বসাতে গিয়ে এতেই আটকেছিলেন।
         *
         * শিক্ষাটা মন্তব্য লেখার নয় — **মন্তব্য যা দাবি করে, কোডকে
         * সেটা বলতে হবে।** নিচের `rules` লাইনটাই সেই কথা।
         */
        'payment-methods' => [
            'model' => PaymentMethod::class,
            'route' => 'payment_method',
            'title' => 'master_data::menu.payment_methods',
            'fields' => [
                'account_id' => ['type' => 'select', 'label' => 'master_data::field.money_account',
                    'options' => 'money_accounts', 'rules' => ['required']],
                'needs_reference' => ['type' => 'switch', 'label' => 'master_data::field.needs_reference'],
                'fee_percent' => ['type' => 'number', 'label' => 'master_data::field.fee_percent', 'step' => '0.0001'],
            ],
            'columns' => ['account_id', 'needs_reference', 'fee_percent'],
        ],

        'payment-terms' => [
            'model' => PaymentTerm::class,
            'route' => 'term',
            'title' => 'master_data::menu.payment_terms',
            'fields' => [
                'days' => ['type' => 'number', 'label' => 'master_data::field.days', 'step' => '1'],
                'early_discount_percent' => ['type' => 'number', 'label' => 'master_data::field.early_discount_percent', 'step' => '0.01'],
                'early_discount_days' => ['type' => 'number', 'label' => 'master_data::field.early_discount_days', 'step' => '1'],
            ],
            'columns' => ['days', 'early_discount_percent'],
        ],

        'price-lists' => [
            'model' => PriceList::class,
            'route' => 'price_list',
            'title' => 'master_data::menu.price_lists',
            'fields' => [
                'party_type_id' => ['type' => 'select', 'label' => 'master_data::field.party_type', 'options' => 'party_types'],
            ],
            'columns' => ['party_type_id'],
        ],

        'party-types' => [
            'model' => PartyType::class,
            'route' => 'party_type',
            'title' => 'master_data::menu.party_types',
            'fields' => [
                'applies_to' => ['type' => 'select', 'label' => 'master_data::field.applies_to', 'options' => 'applies', 'labels' => 'applies'],
            ],
            'columns' => ['applies_to'],
        ],

        'reason-codes' => [
            'model' => ReasonCode::class,
            'route' => 'reason',
            'title' => 'master_data::menu.reason_codes',
            'fields' => [
                /*
                 * প্রসঙ্গটা বাধ্যতামূলক — একই কারণে যে কারণে খাতটা।
                 *
                 * "কোথায় খাটবে" না বললে সারিটা কোনো ড্রপডাউনেই ওঠে না,
                 * আর ডাটাবেজে কলামটা `NOT NULL` — তাই খালি রেখে Save
                 * চাপলে ৫০০। ধরা পড়েছে ২৯ আগস্ট ২০২৬-এ, নতুন পাহারাটা
                 * ([[HalfAFormShouldNeverBeAServerErrorTest]]) বসানোর
                 * প্রথম দৌড়েই — HP-র রিপোর্টে এটার নামই ছিল না।
                 */
                'context' => ['type' => 'select', 'label' => 'master_data::field.context',
                    'options' => 'contexts', 'labels' => 'context', 'rules' => ['required']],
                'returns_to_stock' => ['type' => 'switch', 'label' => 'master_data::field.returns_to_stock'],
                'needs_approval' => ['type' => 'switch', 'label' => 'master_data::field.needs_approval'],
            ],
            'columns' => ['context', 'returns_to_stock'],
        ],

        /*
         * মুদ্রা।
         *
         * হারগুলো এখানে নেই — ওগুলোর নিজের পর্দা, কারণ একটা মুদ্রার
         * হার একটা নয়, তারিখে-তারিখে অনেকগুলো। সারির পাশের "হার"
         * লিংকটা (extra_action) সেখানেই নিয়ে যায়।
         */
        'currencies' => [
            'model' => Currency::class,
            'route' => 'currency',
            'title' => 'master_data::menu.currencies',
            'fields' => [
                'symbol' => ['type' => 'text', 'label' => 'master_data::field.symbol',
                    'rules' => ['nullable', 'string', 'max:8']],
                'decimal_places' => ['type' => 'number', 'label' => 'master_data::field.decimal_places', 'step' => '1',
                    'rules' => ['required', 'integer', 'min:0', 'max:6']],
            ],
            'columns' => ['symbol', 'decimal_places'],
            'extra_action' => ['route' => 'master_data.currency.rates', 'label' => 'master_data::action.rates'],
            'setting' => 'master_data.multi_currency_enabled',
        ],

        /*
         * প্রতিষ্ঠানের গড়ন — তিনটাই সরল তালিকা, নিজস্ব ঘর ছাড়া।
         *
         * সুইচের পেছনে নয়: বিভাগ ও পদবি ছাড়া কর্মীর তালিকাই লেখা যায় না,
         * আর যে প্রতিষ্ঠানে কর্মী নেই সেখানে HR মডিউলটাই বন্ধ থাকে।
         */
        'departments' => [
            'model' => Department::class,
            'route' => 'department',
            'title' => 'master_data::menu.departments',
            'fields' => [],
            'columns' => [],
        ],

        'designations' => [
            'model' => Designation::class,
            'route' => 'designation',
            'title' => 'master_data::menu.designations',
            'fields' => [],
            'columns' => [],
        ],

        'employment-types' => [
            'model' => EmploymentType::class,
            'route' => 'employment_type',
            'title' => 'master_data::menu.employment_types',
            'fields' => [],
            'columns' => [],
        ],

        'vehicle-types' => [
            'model' => VehicleType::class,
            'route' => 'vehicle_type',
            'title' => 'master_data::menu.vehicle_types',
            'fields' => [],
            'columns' => [],
            'setting' => 'master_data.vehicle_enabled',
        ],

        'vehicles' => [
            'model' => Vehicle::class,
            'route' => 'vehicle',
            'title' => 'master_data::menu.vehicles',
            'fields' => [
                'registration_no' => ['type' => 'text', 'label' => 'master_data::field.registration_no',
                    'rules' => ['required', 'string', 'max:64']],
                'vehicle_type_id' => ['type' => 'select', 'label' => 'master_data::field.vehicle_type', 'options' => 'vehicle_types'],
                'capacity_kg' => ['type' => 'number', 'label' => 'master_data::field.capacity_kg', 'step' => '0.001'],
                'owner_type' => ['type' => 'select', 'label' => 'master_data::field.owner_type', 'options' => 'owner_types', 'labels' => 'kind',
                    'rules' => ['required']],
                'driver_name' => ['type' => 'text', 'label' => 'master_data::field.driver_name'],
                'driver_phone' => ['type' => 'text', 'label' => 'master_data::field.driver_phone'],
            ],
            'columns' => ['registration_no', 'vehicle_type_id', 'owner_type'],
            'setting' => 'master_data.vehicle_enabled',
        ],
    ];

    public function __construct(
        private readonly MasterListService $lists,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:master_data.view', only: ['index']),
            new Middleware('can:master_data.manage', only: [
                'create', 'store', 'edit', 'update', 'destroy', 'activate',
                'makeDefault', 'installDefaults',
            ]),

            // মোছার চাবি আলাদা — module.php-তে কারণ
            new Middleware('can:master_data.delete', only: ['purge']),
        ];
    }

    public function index(Request $request, string $kind): View
    {
        $spec = $this->spec($kind);
        $model = $spec['model'];

        $query = $model::query()
            ->search($request->query('q'))
            ->when(! $request->boolean('inactive'), fn ($q) => $q->active());

        $sort = $this->applySort($query, $request, $this->sorts($model));

        $records = $query->get();

        return view('master_data::list.index', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => $kind,
            'spec' => $spec,
            'records' => $records,
            /*
             * "প্রমিত তালিকা বসান" কেবল সেখানেই, যেখানে বসানোর মতো
             * কিছু আছে।
             *
             * ── কী ভাঙা ছিল ────────────────────────────────────────
             * শর্তটা ছিল শুধু `$records->isEmpty()` — অর্থাৎ **যেকোনো**
             * খালি তালিকায় বোতামটা উঠত। কিন্তু `installDefaults()`
             * বসায় ছয়টা তালিকা, আর পর্দাটা দেখায় সতেরোটা।
             *
             * ফলে Brands-এর মতো খালি পর্দায় লেখা উঠত "তালিকাগুলো
             * এখনো খালি — একক, কর, শর্ত ও কারণ কোড ছাড়া প্রথম বিলটাই
             * লেখা যায় না", অথচ ওই তিনটাই ভরা। বার্তাটা মিথ্যা, আর
             * বোতামে চাপলে কিছুই হত না।
             *
             * নিয়মটা কোডের উপরের মন্তব্যেই লেখা ছিল। মন্তব্য নিয়ম নয়।
             */
            'canInstallDefaults' => $records->isEmpty()
                && in_array($spec['kind'], MasterListService::HAS_DEFAULTS, true)
                && ! $request->boolean('inactive'),
            'q' => $request->query('q'),
            'options' => $this->options(),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
        ]);
    }

    /**
     * ডিফল্ট সারিটা আগে — সেটাই প্রতিদিন সবচেয়ে বেশি খোঁজা হয়।
     *
     * ── কেন মডেলটা লাগে ────────────────────────────────────────────
     * সব তালিকায় "ডিফল্ট" বলে কিছু নেই — এককে বা কারণ কোডে ডিফল্ট
     * ধারণাটাই অর্থহীন। যাদের নেই তাদের জন্য defaultFirst() কল করলে
     * অস্তিত্বহীন কলামে ORDER BY যেত।
     *
     * @param  class-string  $model
     * @return array<string, \Closure>
     */
    private function sorts(string $model): array
    {
        $first = $model::supportsDefault()
            ? ['default' => fn ($q) => $q->defaultFirst()]
            : [];

        return [
            ...$first,
            'code' => fn ($q) => $q->orderBy('code'),
            'name' => fn ($q) => $q->orderBy('name_en'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'default' => __('master_data::sort.default_first'),
            'code' => __('master_data::field.code'),
            'name' => __('master_data::field.name'),
        ];
    }

    public function create(Request $request, string $kind): View
    {
        $spec = $this->spec($kind);

        return view('master_data::list.form', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => $kind,
            'spec' => $spec,
            'record' => new $spec['model'](['is_active' => true]),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        $spec = $this->spec($kind);

        $this->lists->create($spec['model'], $this->validated($request, $spec));

        return redirect()
            ->route('master_data.'.$spec['route'].'.index')
            ->with('saved', __('master_data::message.created'));
    }

    public function edit(Request $request, int|string $id, string $kind): View
    {
        $spec = $this->spec($kind);

        return view('master_data::list.form', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => $kind,
            'spec' => $spec,
            'record' => $spec['model']::query()->findOrFail($id),
            'options' => $this->options(),
        ]);
    }

    public function update(Request $request, int|string $id, string $kind): RedirectResponse
    {
        $spec = $this->spec($kind);

        $record = $spec['model']::query()->findOrFail($id);

        $this->lists->update($record, $this->validated($request, $spec, $id));

        return redirect()
            ->route('master_data.'.$spec['route'].'.index')
            ->with('saved', __('master_data::message.updated'));
    }

    /** নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)। */
    public function destroy(int|string $id, string $kind): RedirectResponse
    {
        $spec = $this->spec($kind);

        $this->lists->deactivate($spec['model']::query()->findOrFail($id));

        return back()->with('saved', __('master_data::message.deactivated'));
    }

    /**
     * সত্যিই মুছে ফেলা — কেবল যেটা কোথাও ব্যবহার হয়নি।
     *
     * ব্যবহার হয়ে থাকলে সার্ভিস থামিয়ে দেয় আর কোথায় ব্যবহার হয়েছে
     * সেটা বলে দেয়, যাতে "কেন হল না" প্রশ্নটা না থাকে।
     */
    public function purge(int|string $id, string $kind): RedirectResponse
    {
        $spec = $this->spec($kind);

        $removed = $this->lists->delete($spec['model']::query()->findOrFail($id));

        /*
         * কী ঘটল সেটা বলে দেওয়া হয়, কারণ দুইটা আলাদা জিনিস ঘটতে পারে
         * আর ব্যবহারকারী কোনটা চেয়েছিলেন তা সে জানে না। "মুছে ফেলুন"
         * চেপে সারিটা তালিকায় ধূসর হয়ে পড়ে থাকতে দেখলে মনে হত কাজটাই
         * হয়নি।
         */
        return redirect()
            ->route('master_data.'.$spec['route'].'.index')
            ->with('saved', $removed
                ? __('master_data::message.deleted')
                : __('master_data::message.in_use_deactivated'));
    }

    /** আবার সক্রিয় — নিষ্ক্রিয় করা একমুখী দরজা হতে পারে না। */
    public function activate(int|string $id, string $kind): RedirectResponse
    {
        $spec = $this->spec($kind);

        $this->lists->activate($spec['model']::query()->findOrFail($id));

        return back()->with('saved', __('master_data::message.activated'));
    }

    public function makeDefault(int|string $id, string $kind): RedirectResponse
    {
        $spec = $this->spec($kind);

        $record = $spec['model']::query()->findOrFail($id);
        $record->makeDefault();

        return back()->with('saved', __('master_data::message.is_default_now', ['name' => $record->name()]));
    }

    /** প্রমিত তালিকাগুলো — খালি কোম্পানির শুরুর অবস্থা। */
    public function installDefaults(string $kind): RedirectResponse
    {
        $this->lists->installDefaults();

        return back()->with('saved', __('master_data::message.defaults_installed'));
    }

    /**
     * ঘোষিত ঘরগুলোই যাচাই হয়, তার বাইরে কিছু নয়।
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    /**
     * @param  int|string|null  $id  সম্পাদনার সময় নিজের সারিটা — কোডের অনন্যতা মেলানোর জন্য
     *
     * ── কেন `?int` নয় ───────────────────────────────────────────────
     * ঘরটা আগে `?int` ছিল, আর `update()` তাকে দিত রুটের প্যারামিটার —
     * যা HTTP-তে **সবসময় স্ট্রিং**। `declare(strict_types=1)` চালু
     * থাকায় PHP ওটাকে int-এ বদলায় না, ছুঁড়ে দেয়:
     *
     *     Argument #3 ($id) must be of type ?int, string given
     *
     * ফল: **সতেরোটা মাস্টার তালিকার প্রতিটাতেই সম্পাদনা ৫০০**। শুধু
     * সংরক্ষণের পথ, তাই তালিকা ও ফর্ম দিব্যি খুলত — ভাঙত সেভ চাপার পর।
     *
     * ধরা পড়েছে ২৫ আগস্ট ২০২৬, bKash-এর পেমেন্ট মেথডটা খাতের সাথে
     * বাঁধতে গিয়ে।
     *
     * জোর করে `(int)` কাস্ট করা যেত, কিন্তু সেটা মিথ্যা: `$id` এখানে
     * কেবল `whereKeyNot()`-এ যায়, আর সে দুইটাই নেয়। তাই ঘরটা যা আসে
     * তাই বলে।
     */
    private function validated(Request $request, array $spec, int|string|null $id = null): array
    {
        $rules = [
            /*
             * কোড আর বাধ্যতামূলক নয় — খালি রাখলে নাম থেকে বসে।
             *
             * মালিকের সিদ্ধান্ত (২০২৬-০৮-০৯): "সব কোড আগে নিজে বসবে,
             * তারপর কারো লাগলে এডিট করে নতুন কোড বসাবে।"
             *
             * ফর্মে ঘরটা টাইপ করার সাথে সাথেই ভরে যায়, তাই সচরাচর
             * এখানে খালি আসে না। কিন্তু নিয়মটা সার্ভারেও থাকতে হবে:
             * ব্রাউজারের JS বন্ধ থাকলে, বা কেউ ঘরটা মুছে দিলে, অথবা
             * ইমপোর্টে কোড ছাড়া সারি এলে — তিন ক্ষেত্রেই সার্ভারই শেষ
             * কথা।
             */
            'code' => ['nullable', 'string', 'max:32'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'is_default' => ['nullable', 'boolean'],
        ];

        $switches = [];
        $options = $this->options();

        foreach ($spec['fields'] as $name => $field) {
            $rules[$name] = match ($field['type']) {
                'number' => ['nullable', 'numeric'],
                'switch' => ['nullable', 'boolean'],
                'select' => ['nullable', Rule::in($this->choices($options, $field['options']))],
                default => ['nullable', 'string', 'max:191'],
            };

            /*
             * ঘোষণায় বাড়তি নিয়ম দিলে সেগুলোও যোগ হয়।
             *
             * গাড়ির নম্বরপ্লেট ঐচ্ছিক হলে চলত না — ওটাই চালানে ছাপা
             * হয়, আর খালি প্লেট নিয়ে গাড়ি গেটে দাঁড়ালে কাগজটা
             * অকেজো। কিন্তু "সব ঘর বাধ্যতামূলক" করলে বাকি তালিকাগুলো
             * ভাঙত, তাই নিয়মটা ঘরের সাথেই ঘোষিত।
             */
            if ($field['rules'] ?? false) {
                $rules[$name] = array_values(array_diff($rules[$name], ['nullable']));
                $rules[$name] = [...$rules[$name], ...$field['rules']];
            }

            if ($field['type'] === 'switch') {
                $switches[$name] = $request->boolean($name);
            }
        }

        // চেকবক্স না দেখালে ব্রাউজার কিছুই পাঠায় না — তখন "মিথ্যা" আর
        // "দেওয়া হয়নি" আলাদা করা যায় না, আর সম্পাদনায় সুইচটা নিজে
        // থেকে বন্ধ হয়ে যেত
        $request->merge($switches + ['is_default' => $request->boolean('is_default')]);

        $data = $request->validate($rules);

        /*
         * খালি সংখ্যার ঘর ডাটাবেজে যায় না।
         *
         * ── কী ভেঙেছিল ─────────────────────────────────────────────
         * নতুন একক বানানোর সময় Conversion ঘরটা খালি রাখলে সাদা
         * "500 Server Error" আসত। ঘরটায় লাল তারকা নেই, তাই খালি রাখা
         * বৈধ মনে হয় — আর হওয়াও উচিত।
         *
         * ব্রাউজার খালি ঘরের জন্য খালি স্ট্রিং পাঠায়, `nullable` সেটা
         * মেনে নেয়, তারপর সেটা decimal কলামে যায় আর পরে
         * `bccomp('')` PHP 8-এ ValueError ছোড়ে। ব্যবহারকারী দেখেন
         * শুধু একটা সাদা পাতা।
         *
         * ঘরটা বাদ দিলে নতুন সারিতে কলামের নিজের ডিফল্ট বসে (একক ৩৩২
         * রূপান্তরে ১), আর সম্পাদনায় আগের মানটা থেকে যায় — দুইটাই
         * "খালি" শব্দটার সবচেয়ে স্বাভাবিক অর্থ।
         */
        foreach ($spec['fields'] as $name => $field) {
            if ($field['type'] === 'number' && ($data[$name] ?? null) === null) {
                unset($data[$name]);
            }
        }

        /*
         * কোড খালি হলে নামটাই কোড হয়ে বসে।
         *
         * যাচাইয়ের পরে, কারণ নামটা তখনই নিশ্চিতভাবে আছে — আগে করলে
         * নাম ছাড়া অনুরোধে খালি নাম থেকে খালি কোড বানানোর চেষ্টা হত।
         *
         * সম্পাদনার সময় নিজের সারিটা বাদ, নইলে কোড না বদলে সেভ করলেই
         * নিজের কোডটাকে "নেওয়া হয়ে গেছে" ধরে CAR2 বসিয়ে দিত।
         */
        if (($data['code'] ?? '') === '') {
            $scope = $spec['model']::query();

            if ($id !== null) {
                $scope->whereKeyNot($id);
            }

            $data['code'] = CodeFromName::forQuery((string) $data['name_en'], $scope);
        }

        return $data;
    }

    /**
     * একটা ড্রপডাউনে সত্যিই যে মানগুলো বসতে পারে।
     *
     * ── কেন যাচাইটা তালিকা থেকেই আসে ───────────────────────────────
     * আগে select-এর নিয়ম ছিল শুধু 'nullable', তাই ফর্ম বাইপাস করে
     * applies_to=যা-খুশি পাঠালে সেটা বসে যেত — আর তালিকার পর্দায়
     * অচেনা মানটা ফাঁকা দেখাত, যেন ঘরটা ভরা হয়নি।
     *
     * নিয়মটা হাতে না লিখে তালিকা থেকে নেওয়া হয়, নাহলে একদিন একটা
     * নতুন ধরন যোগ হত আর যাচাইয়ের তালিকাটা পুরনো থেকে যেত।
     *
     * @param  array<string, mixed>  $options
     * @return list<string|int>
     */
    private function choices(array $options, string $key): array
    {
        $list = $options[$key] ?? [];

        // মডেলের তালিকা হলে id, ধ্রুবকের তালিকা হলে মানগুলোই
        return $list instanceof Collection
            ? $list->pluck('id')->all()
            : array_values($list);
    }

    /**
     * ড্রপডাউনে যা যা আসবে।
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'units' => Unit::query()->active()->orderBy('code')->get(),
            'party_types' => PartyType::query()->active()->orderBy('code')->get(),
            'accounts' => Account::query()->postable()->active()->orderBy('code')->get(),

            /*
             * টাকার খাতগুলোই — নগদ ও ব্যাংক/MFS।
             *
             * পুরো ছক দেখালে কেউ ভুল করে "বিক্রয়" বা "ভাড়া" বেছে
             * ফেলতে পারতেন, আর তখন প্রতিটা বিক্রয়ের টাকা আয়ের খাতে
             * দুইবার বসত। তালিকাটা ছোট রাখাই এখানে পাহারা।
             */
            'money_accounts' => Account::query()->money()->postable()->active()->orderBy('code')->get(),
            'tax_kinds' => Tax::KINDS,
            'applies' => PartyType::APPLIES,
            'contexts' => ReasonCode::CONTEXTS,
            'vehicle_types' => VehicleType::query()->active()->orderBy('code')->get(),
            'owner_types' => Vehicle::OWNER_TYPES,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(string $kind): array
    {
        abort_unless(isset(self::KINDS[$kind]), 404);

        $spec = self::KINDS[$kind];

        /*
         * বন্ধ করা তালিকা শুধু মেনু থেকে সরে না, ঠিকানাটাও বন্ধ হয়।
         *
         * মেনু থেকে সরানোই যথেষ্ট মনে হয়, কিন্তু নয়: বুকমার্ক থেকে যায়,
         * পুরনো লিংক ঘোরে, আর কেউ ঠিকানা টাইপ করলে বন্ধ করা পর্দাটা
         * খুলে যেত — তখন সুইচটা কেবল লুকানোর ভান করত।
         */
        if (isset($spec['setting'])) {
            abort_unless((bool) $this->settings->get($spec['setting']), 404);
        }

        return $spec + ['kind' => $kind];
    }

    /**
     * প্রতিটা তালিকার URL অংশ — রুট ফাইলের জন্য।
     *
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return array_map(fn (array $spec) => $spec['route'], self::KINDS);
    }
}
