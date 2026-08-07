<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Services\LocationService;
use App\Modules\MasterData\Services\MasterListService;
use App\Modules\Supplier\Services\SupplierService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * চালিয়ে দেখার মতো একটা অবস্থা — দুইটা কোম্পানি, শাখা, অর্থবছর, নম্বর সিরিজ,
 * অনুমোদনের ছক আর কয়েকজন ব্যবহারকারী।
 *
 * দুইটা কোম্পানি ইচ্ছাকৃত: একটা দিয়ে টেন্যান্ট আলাদা থাকার ব্যাপারটা হাতে-কলমে
 * দেখা যায় না। সুইচ করে দেখলেই বোঝা যায় এক কোম্পানির ডাটা অন্যটায় নেই।
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $alpha = Company::create([
            'code' => 'TDEPOT',
            'name_en' => 'Trade Depot',
            'name_bn' => 'ট্রেড ডিপো',
            'address_en' => 'Ganginar Par, Mymensingh 2200',
            'address_bn' => 'গাঙ্গিনার পাড়, ময়মনসিংহ ২২০০',
            'phone' => '+8801700000000',
            'bin' => '000123456-0101',
            'logo_path' => 'logos/Trade Depot.png',
            'currency' => 'BDT',
            'locale' => 'bn',
        ]);

        $beta = Company::create([
            'code' => 'FMART',
            'name_en' => 'Family Mart',
            'name_bn' => 'ফ্যামিলি মার্ট',
            'address_en' => 'Charpara, Mymensingh 2200',
            'address_bn' => 'চরপাড়া, ময়মনসিংহ ২২০০',
            'phone' => '+8801700000001',
            'logo_path' => 'logos/FamilyMart.png',
            'currency' => 'BDT',
            'locale' => 'bn',
        ]);

        // প্রতিটা module.php-তে ঘোষিত অনুমতি ডাটাবেজে বসাও — নাহলে মেনু
        // ফাঁকা থাকবে, কারণ মেনু অনুমতি দেখে ফিল্টার হয়।
        app(PermissionSyncer::class)->sync();

        $roles = [];

        foreach (['owner', 'accountant', 'salesman'] as $role) {
            $roles[$role] = Role::findOrCreate($role);
        }

        // মালিক সব পারেন। বাকিদের সীমা module.php-র prefix ধরে —
        // হিসাবরক্ষক accounts.*, বিক্রয়কর্মী sales.* ও customer.*।
        $roles['owner']->syncPermissions(Permission::all());

        $roles['accountant']->syncPermissions(
            Permission::query()->where('name', 'like', 'accounts.%')->get()
        );

        $roles['salesman']->syncPermissions(
            Permission::query()
                /*
                 * দুইটা উপসর্গ বন্ধনীর ভেতরে — নাহলে AND আগে বাঁধে।
                 *
                 * আগে এটা ->where(...)->orWhere(...)->where('name','!=',...)
                 * ছিল, আর SQL দাঁড়াত: sales.% OR (customer.% AND নয়-এটা)।
                 * অর্থাৎ বাদ দেওয়ার নিয়মটা কেবল দ্বিতীয় উপসর্গে খাটত, আর
                 * বিক্রয়কর্মী চিরকাল ধারের সীমা পার করার অনুমতি পেয়ে
                 * এসেছেন — মন্তব্যে ঠিক উল্টোটা লেখা থাকা সত্ত্বেও।
                 */
                ->where(fn ($q) => $q->where('name', 'like', 'sales.%')
                    ->orWhere('name', 'like', 'customer.%'))
                /*
                 * সীমা অতিক্রমের অনুমতিগুলো বিক্রয়কর্মীর নেই — সেটাই
                 * অনুমোদন চাওয়ার কারণ।
                 *
                 * তালিকাটা আলাদা করে লেখা, কারণ "sales.%" ধরনের ঢালাও
                 * অনুমতি নতুন কিছু যোগ হলেই তাকেও দিয়ে দেয়। ঠিক সেটাই
                 * হয়েছিল: Sales মডিউল sales.discount.override ঘোষণা করল,
                 * আর বিক্রয়কর্মী নীরবে ধারের সীমা পার করার ক্ষমতা পেয়ে
                 * গেলেন — কোনো ভুল বার্তা ছাড়াই।
                 */
                ->whereNotIn('name', [
                    'customer.credit_limit.override',
                    'sales.discount.override',
                ])
                ->get()
        );

        // একটা ডিপোর প্রধান কার্যালয় ও তিনটা উপজেলা শাখা — বাস্তব বিন্যাস।
        // শাখা আলাদা করে না রাখলে সব এন্ট্রি একটাতেই বসে, যা DMS-এ একটা
        // আলাদা ফিক্স লেগেছিল।
        $this->setUpCompany($alpha, [
            ['code' => 'MMS', 'name_en' => 'Main Mymensingh', 'name_bn' => 'প্রধান ময়মনসিংহ', 'is_default' => true],
            ['code' => 'NTK', 'name_en' => 'Netrakona', 'name_bn' => 'নেত্রকোনা'],
            ['code' => 'DMD', 'name_en' => 'Dumdy', 'name_bn' => 'ডুমডি'],
            ['code' => 'KDA', 'name_en' => 'Kendua', 'name_bn' => 'কেন্দুয়া'],
        ]);

        $this->setUpCompany($beta, [
            ['code' => 'MAIN', 'name_en' => 'Main Office', 'name_bn' => 'প্রধান কার্যালয়', 'is_default' => true],
        ]);

        $owner = $this->user('Al-Amin Shuvo', 'owner@abos.test', 'owner');
        $accountant = $this->user('হিসাবরক্ষক', 'accounts@abos.test', 'accountant');
        $salesman = $this->user('বিক্রয়কর্মী', 'sales@abos.test', 'salesman');

        // মালিক দুই কোম্পানিতেই — সুইচার পরীক্ষা করার জন্য এটাই দরকার।
        $owner->companies()->attach([$alpha->id, $beta->id]);
        $accountant->companies()->attach([$alpha->id]);
        $salesman->companies()->attach([$alpha->id]);

        $owner->switchCompany($alpha->id);
        $accountant->switchCompany($alpha->id);
        $salesman->switchCompany($alpha->id);

        // ছাড়ে অনুমোদন — ১,০০০ টাকার উপরে মালিকের সম্মতি লাগবে
        CompanyContext::forCompany($alpha->id, function () use ($owner) {
            $flow = ApprovalFlow::create([
                'module' => 'sales',
                'action' => 'discount',
                'threshold_amount' => '1000.0000',
            ]);

            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $owner->id,
            ]);
        });

        // সরবরাহকারী, গুদাম, পণ্য আর চারটা অবস্থাতেই কিছু মাল — নাহলে
        // মজুদের পর্দা খুললে ফাঁকা টেবিল, আর ফাঁকা টেবিল দেখে বোঝা যায় না
        // অঙ্কটা ঠিক আছে কি না।
        CompanyContext::forCompany($alpha->id, function () {
            $this->setUpSuppliers();
            $this->setUpCustomers();
            $this->setUpStock();
        });

        CompanyContext::clear();

        $this->command?->info('ডেমো ডাটা তৈরি হয়েছে।');
        $this->command?->table(
            ['ব্যবহারকারী', 'ইমেইল', 'রোল', 'কোম্পানি'],
            [
                ['Al-Amin Shuvo', 'owner@abos.test', 'owner', $alpha->code.' + '.$beta->code],
                ['হিসাবরক্ষক', 'accounts@abos.test', 'accountant', $alpha->code],
                ['বিক্রয়কর্মী', 'sales@abos.test', 'salesman', $alpha->code],
            ],
        );
        $this->command?->comment('সবার পাসওয়ার্ড: password');
    }

    private function setUpCompany(Company $company, array $branches): void
    {
        CompanyContext::forCompany($company->id, function () use ($branches) {
            foreach ($branches as $branch) {
                Branch::create($branch);
            }

            $year = FinancialYear::create([
                'name' => '2026-2027',
                'starts_on' => '2026-07-01',
                'ends_on' => '2027-06-30',
                'is_current' => true,
            ]);

            /*
             * নম্বর সিরিজ — মডিউলের ঘোষণা থেকে, হাতে লেখা তালিকা থেকে নয়।
             *
             * আগে এখানে একটা তালিকা ছিল, আর module.php-র ঘোষণার সাথে সেটা
             * মিলত না: খরচ ভাউচারের টাইপ ঘোষিত ছিল কিন্তু সিরিজ ছিল না,
             * তাই প্রথম খরচ ভাউচারটা লিখতে গিয়েই আটকে গেল।
             *
             * শাখাভিত্তিক নয় — কোম্পানি-ব্যাপী, কারণ শুরুতে বেশিরভাগ
             * প্রতিষ্ঠান এটাই চায়; শাখা আলাদা করতে চাইলে Master Data
             * থেকে যোগ করা যাবে।
             */
            app(NumberSeriesProvisioner::class)->provision($year);

            /*
             * হিসাবের প্রমিত ছক ও মাস্টার তালিকাগুলো।
             *
             * দুইটাই এতদিন ছিল, শুধু কেউ ডাকত না — আর সেটা ধরা পড়ল
             * সরবরাহকারীর খোলা ব্যালেন্স বসাতে গিয়ে: খাত নেই মানে
             * দাখিলাই বসে না। মানে একটা নতুন কোম্পানি খুললে তার হিসাবের
             * ছক ফাঁকা থাকত, একক ফাঁকা থাকত, শর্ত ফাঁকা থাকত — প্রথম
             * বিলটাই লেখা যেত না।
             *
             * নম্বর সিরিজের বেলায় ঠিক এই ভুলটাই হয়েছিল, তাই নিয়মটা
             * একই: কোম্পানি তৈরির অংশ হিসেবেই সব বসবে, পরে মনে করে নয়।
             */
            app(StandardChart::class)->install();
            app(MasterListService::class)->installDefaults();
        });
    }

    /**
     * দুইজন সরবরাহকারী।
     *
     * ক্রয়ের কোনো পর্দাই সরবরাহকারী ছাড়া খোলা যায় না — আদেশ, চালান,
     * বিল তিনটাতেই প্রথম ঘরটা সরবরাহকারীর। ফাঁকা তালিকা রেখে দিলে নতুন
     * কেউ ক্রয় দেখতে গিয়ে ভাবতেন মডিউলটা কাজ করে না।
     */
    private function setUpSuppliers(): void
    {
        $suppliers = app(SupplierService::class);

        $suppliers->create([
            'name_en' => 'Pran RFL Distribution',
            'name_bn' => 'প্রাণ আরএফএল ডিস্ট্রিবিউশন',
            'phone' => '+8801711000001',
            'address_en' => 'Ganginar Par, Mymensingh',
            'address_bn' => 'গাঙ্গিনার পাড়, ময়মনসিংহ',
        ]);

        $suppliers->create([
            'name_en' => 'Bismillah Distribution',
            'name_bn' => 'বিসমিল্লাহ ডিস্ট্রিবিউশন',
            'phone' => '+8801711000003',
            'address_en' => 'Kendua, Netrakona',
            'address_bn' => 'কেন্দুয়া, নেত্রকোনা',
        ]);

        $suppliers->create([
            'name_en' => 'Akij Food & Beverage',
            'name_bn' => 'আকিজ ফুড অ্যান্ড বেভারেজ',
            'phone' => '+8801711000002',
            'address_en' => 'Charpara, Mymensingh',
            'address_bn' => 'চরপাড়া, ময়মনসিংহ',
        ]);
    }

    /**
     * তিনজন গ্রাহক।
     *
     * সরবরাহকারীর মতোই কারণ: বিক্রয়ের চারটা পর্দার প্রথম ঘরটাই গ্রাহকের,
     * তাই তালিকা ফাঁকা থাকলে অর্ডারই খোলা যায় না।
     *
     * একজনের ধারের সীমা বসানো আছে — সীমার নিয়মটা চোখে দেখার জন্য।
     * সীমা ছাড়া সবাই সমান হলে ব্যাপারটা আছে কি না তা বোঝাই যেত না।
     */
    /**
     * ডিপোর নিজের এলাকা-ছক — এরিয়া আর তার নিচের পয়েন্ট।
     *
     * দেশ ও বিভাগ কোর নিজে বসায় (LocationService::installBangladesh),
     * কারণ ওগুলো সবার জন্য এক। এরিয়া থেকে নিচে ব্যবসার নিজের ছক, তাই
     * ডেমো ডিপোরটা এখানে।
     *
     * ── কেন এটা লাগল ────────────────────────────────────────────────
     * গ্রাহকের তালিকায় পয়েন্ট ও এরিয়ার কলাম আছে (মালিকের চাওয়া ক্রম),
     * আর ওগুলো ফাঁকা থাকলে কলাম দুইটা দেখেই বোঝা যেত না তারা কী দেখাবে।
     * ফাঁকা ড্যাশ ভরা একটা তালিকা দিয়ে ডিজাইন যাচাই করা যায় না।
     *
     * @return array<string, int> পয়েন্টের কোড => id
     */
    private function setUpLocations(): array
    {
        $locations = app(LocationService::class);
        $locations->installBangladesh();

        $mymensingh = Location::query()
            ->where('level', Location::DIVISION)
            ->where('code', 'MYM')
            ->firstOrFail();

        $points = [];

        /*
         * এরিয়া › টেরিটরি › পয়েন্ট — মাঝের ধাপটা বাদ দেওয়া যায় না।
         *
         * প্রথমে পয়েন্টগুলো সরাসরি এরিয়ার নিচে বসানো হয়েছিল, আর
         * LocationService থামিয়ে দিল: "উপরে টেরিটরি থাকার কথা, এরিয়া
         * নয়।" সে ঠিকই বলছিল — ধাপটা এই কোম্পানিতে চালু আছে, আর চালু
         * ধাপ এড়িয়ে গেলে গাছটার মাঝখানে ফাঁক পড়ত।
         *
         * বাস্তবেও ধাপটা কাজের: একজন এসআর একটা টেরিটরি চালান, আর তার
         * নিচে কয়েকটা বাজার (পয়েন্ট)।
         */
        foreach ([
            ['MYM-SAD', 'Mymensingh Sadar', 'ময়মনসিংহ সদর', [
                ['TR-MYM1', 'Mymensingh Town', 'ময়মনসিংহ শহর', [
                    ['PT-GNG', 'Ganginar Par', 'গাঙ্গিনার পাড়'],
                    ['PT-CHR', 'Charpara', 'চরপাড়া'],
                ]],
            ]],
            ['NTK', 'Netrakona', 'নেত্রকোনা', [
                ['TR-NTK1', 'Kendua Route', 'কেন্দুয়া রুট', [
                    ['PT-KDA', 'Kendua Bazar', 'কেন্দুয়া বাজার'],
                    ['PT-DMD', 'Dumdy Bazar', 'ডুমডি বাজার'],
                ]],
            ]],
        ] as [$areaCode, $areaEn, $areaBn, $territoryRows]) {
            $area = $locations->create([
                'code' => $areaCode,
                'name_en' => $areaEn,
                'name_bn' => $areaBn,
                'level' => Location::AREA,
                'parent_id' => $mymensingh->id,
            ]);

            foreach ($territoryRows as [$trCode, $trEn, $trBn, $pointRows]) {
                $territory = $locations->create([
                    'code' => $trCode,
                    'name_en' => $trEn,
                    'name_bn' => $trBn,
                    'level' => Location::TERRITORY,
                    'parent_id' => $area->id,
                ]);

                foreach ($pointRows as [$code, $en, $bn]) {
                    $points[$code] = $locations->create([
                        'code' => $code,
                        'name_en' => $en,
                        'name_bn' => $bn,
                        'level' => Location::POINT,
                        'parent_id' => $territory->id,
                    ])->id;
                }
            }
        }

        return $points;
    }

    private function setUpCustomers(): void
    {
        $customers = app(CustomerService::class);
        $points = $this->setUpLocations();

        /*
         * নামগুলো ubos-dms থেকে নেওয়া — বানানো নয়।
         *
         * দুইটা পণ্য একই ব্যবসার, তাই ডেমো ডাটাও এক রাখলে একটাতে দেখা
         * পর্দা অন্যটায় চিনতে অসুবিধা হয় না। আর DMS-এর নামগুলো আসল
         * ডিপোর তালিকা থেকে এসেছে, তাই সেগুলো দিয়ে পরীক্ষা করলে যা দেখা
         * যায় তা বাস্তবেও ওরকমই দেখাবে — "Test Customer 1" দিয়ে যা
         * কোনোদিন দেখা যেত না।
         */
        /*
         * মালিকের নাম আর পয়েন্ট — দোকানের নামের সাথে মিলিয়ে।
         *
         * "মায়ের দোয়া স্টোর"-এ ফোন করলে "রফিকুল ইসলাম"-কে চাইতে হয়;
         * দুইটা আলাদা তথ্য, তাই আলাদা ঘরে। আর পয়েন্টটা ঠিকানার সাথে
         * মেলানো — কেন্দুয়ার দোকান কেন্দুয়া পয়েন্টে, নইলে তালিকার
         * এরিয়া কলামটা ঠিকানার সাথে অমিল দেখাত।
         */
        foreach ([
            ['Rahim Traders', 'রহিম ট্রেডার্স', 'Md. Rahim Uddin', 'PT-KDA', '+8801811000001',
                'Kendua Bazar, Netrakona', 'কেন্দুয়া বাজার, নেত্রকোনা', '50000', 15],
            ['Karim Stores', 'করিম স্টোর্স', 'Abdul Karim', 'PT-DMD', '+8801811000002',
                'Dumdy Bazar, Mymensingh', 'ডুমডি বাজার, ময়মনসিংহ', '20000', 7],
            ['Bismillah Enterprise', 'বিসমিল্লাহ এন্টারপ্রাইজ', 'Shahidul Islam', 'PT-GNG', '+8801811000003',
                'Ganginar Par, Mymensingh', 'গাঙ্গিনার পাড়, ময়মনসিংহ', '75000', 30],
            ['Alam Store', 'আলম স্টোর', 'Nurul Alam', 'PT-KDA', '+8801811000004',
                'Kendua, Netrakona', 'কেন্দুয়া, নেত্রকোনা', '10000', 7],
            ['Niloy Store', 'নিলয় স্টোর', 'Niloy Chandra Das', 'PT-CHR', '+8801811000005',
                'Charpara, Mymensingh', 'চরপাড়া, ময়মনসিংহ', '0', 0],
        ] as [$en, $bn, $owner, $point, $phone, $addressEn, $addressBn, $limit, $days]) {
            $customers->create([
                'name_en' => $en,
                'name_bn' => $bn,
                'owner_name' => $owner,
                'location_id' => $points[$point] ?? null,
                'phone' => $phone,
                'address_en' => $addressEn,
                'address_bn' => $addressBn,
                'credit_limit' => $limit,
                'credit_days' => $days,
            ]);
        }

        /*
         * নগদ গ্রাহক — কাউন্টারের বিক্রি এই নামে বসে।
         *
         * POS-এ প্রতিবার গ্রাহক বাছতে বললে লাইন দাঁড়িয়ে যায়, তাই একজন
         * আগে থেকে বসানো থাকে। আলাদা POS-তালিকা নয়, এই একই মাস্টারেরই
         * একটা সারি — দুইটা তালিকা রাখলে একই দোকানের হিসাব দুই জায়গায়
         * ভাগ হয়ে যেত।
         */
        $walkin = $customers->create([
            'name_en' => 'Cash Customer',
            'name_bn' => 'নগদ গ্রাহক',
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);

        app(SettingsService::class)->set('sales.walkin_customer_id', $walkin->id);
    }

    /**
     * দুইটা গুদাম, কয়েকটা পণ্য, আর চারটা অবস্থাতেই মাল।
     *
     * পরিমাণগুলো ইচ্ছাকৃতভাবে আলাদা: একটা পণ্যে শুধু তাকের মাল, একটায়
     * অর্ডারে ধরা আছে, একটায় আটকানো, আর একটায় তিনটাই। তাতে মজুদের পর্দা
     * খুললেই Floor − Reserved − Hold = Available অঙ্কটা চোখে পড়ে, আর
     * ভুল হলে সেটাও চোখে পড়ে।
     */
    private function setUpStock(): void
    {
        $warehouses = app(WarehouseService::class);
        $products = app(ProductService::class);
        $stock = app(StockService::class);

        $main = $warehouses->create([
            'code' => 'WH-MMS',
            'name_en' => 'Mymensingh Store',
            'name_bn' => 'ময়মনসিংহ গুদাম',
            'branch_id' => Branch::query()->where('code', 'MMS')->value('id'),
            'is_default' => true,
        ]);

        $netrakona = $warehouses->create([
            'code' => 'WH-NTK',
            'name_en' => 'Netrakona Store',
            'name_bn' => 'নেত্রকোনা গুদাম',
            'branch_id' => Branch::query()->where('code', 'NTK')->value('id'),
        ]);

        $unit = Unit::query()->where('code', 'PCS')->value('id');
        $sack = Unit::query()->where('code', 'BAG')->value('id') ?? $unit;

        /*
         * পণ্যের নাম ও বাংলা রীতি ubos-dms থেকে — বিশেষ করে মাপের অংশটা।
         *
         * DMS-এর নাম-পরামর্শক "Milk Powder 1kg" থেকে "গুঁড়া দুধ ১ কেজি"
         * বানায়: সংখ্যাটা বাংলা অঙ্কে, আর এককটা বাংলা শব্দে ("gm" →
         * "গ্রাম", "জিএম" নয়)। একই ছক এখানেও, নাহলে এক পণ্যের বাংলা নাম
         * দুই পণ্যে দুই রকম হত।
         */
        $rows = [
            ['Miniket Rice 50kg', 'মিনিকেট চাল ৫০ কেজি', $sack, '3400', '3550', '8901000000017'],
            ['Soyabean Oil 5 ltr', 'সয়াবিন তেল ৫ লিটার', $unit, '820', '880', '8901000000024'],
            ['Milk Powder 1kg', 'গুঁড়া দুধ ১ কেজি', $unit, '690', '740', '8901000000031'],
            ['Cosmos Biscuit 40gm', 'কসমস বিস্কুট ৪০ গ্রাম', $unit, '8', '10', '8901000000048'],
            ['Premium Tea 250gm', 'প্রিমিয়াম চা ২৫০ গ্রাম', $unit, '145', '165', '8901000000055'],
            ['Soap 100gm', 'সাবান ১০০ গ্রাম', $unit, '42', '50', '8901000000062'],
        ];

        $made = [];

        foreach ($rows as [$en, $bn, $unitId, $buy, $sell, $barcode]) {
            $made[] = $products->create([
                'name_en' => $en,
                'name_bn' => $bn,
                'unit_id' => $unitId,
                'purchase_price' => $buy,
                'sale_price' => $sell,
                'reorder_level' => '20',
                'barcode' => $barcode,
            ]);
        }

        [$rice, $oil, $milk, $biscuit, $tea, $soap] = $made;

        // খোলা মজুদ — উৎস 'opening', যাতে ড্রিল-ডাউনে "কোথা থেকে এল"
        // প্রশ্নের একটা উত্তর থাকে
        foreach ([[$rice, '120'], [$oil, '75'], [$milk, '240'], [$biscuit, '1800'],
            [$tea, '260'], [$soap, '400']] as [$product, $qty]) {
            $this->openWith($product, $main, $qty);
        }

        $this->openWith($rice, $netrakona, '40');

        // অর্ডারে ধরা — মাল তাকেই আছে, কিন্তু অন্যের নামে
        $stock->move(
            product: $milk, warehouse: $main,
            sourceType: 'sales_order', sourceId: 1,
            reserved: '60', documentNo: 'SO-000001',
        );

        $stock->move(
            product: $biscuit, warehouse: $main,
            sourceType: 'sales_order', sourceId: 2,
            reserved: '25', documentNo: 'SO-000002',
        );

        // আটকানো — দুইটা কারণে, আর কারণ দুইটা এক জিনিস নয়
        $stock->hold($rice, $main, '30', $this->reason('HOLD-PRICE'));
        $stock->hold($soap, $main, '12', $this->reason('HOLD-DMG'));
    }

    /**
     * খোলা মজুদ — মাল আর তার দাম, একসাথে।
     *
     * ── কেন দামটাও ─────────────────────────────────────────────────────
     * খোলা মজুদ মানে "ব্যবসা শুরুর দিন তাকে যা ছিল", আর তাকে থাকা মালের
     * একটা দাম থাকেই — নইলে ওটা সম্পদ হিসেবে ব্যালেন্স শিটে বসত না।
     *
     * দামটা না বসালে প্রথম বিক্রয়েই আটকে যেত: FIFO জিজ্ঞেস করে "এই
     * মালটা কোন চালানে, কত দামে ঢুকেছিল?", আর খোলা মজুদের কোনো উত্তর
     * থাকত না। ঠিক এটাই ধরা পড়েছে — স্তর বসানোর পর ছয়টা পুরনো টেস্ট
     * লাল হয়ে গিয়েছিল, আর তারা ঠিকই বলছিল।
     *
     * বাস্তব ডিপোতেও নিয়মটা এক: পুরনো হিসাব থেকে ABOS-এ আসার দিন
     * প্রতিটা পণ্যের পরিমাণের পাশে তার দরও লিখতে হবে।
     */
    private function openWith(Product $product, Warehouse $warehouse, string $qty): void
    {
        app(StockService::class)->move(
            product: $product, warehouse: $warehouse,
            sourceType: 'opening', sourceId: $product->id,
            floor: $qty, narration: 'খোলা মজুদ',
        );

        app(CostLayerService::class)->receive(
            product: $product,
            qty: $qty,
            unitCost: (string) $product->purchase_price,
            sourceType: 'opening',
            sourceId: $product->id,
            documentNo: 'OPENING',
        );
    }

    private function reason(string $code): ReasonCode
    {
        return ReasonCode::query()->where('code', $code)->firstOrFail();
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'locale' => 'bn',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
