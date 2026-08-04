<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
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
                ->where('name', 'like', 'sales.%')
                ->orWhere('name', 'like', 'customer.%')
                // ছাড়ের সীমা অতিক্রমের অনুমতি বিক্রয়কর্মীর নেই — সেটাই
                // অনুমোদন চাওয়ার কারণ।
                ->where('name', '!=', 'customer.credit_limit.override')
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
            'name_en' => 'Akij Food & Beverage',
            'name_bn' => 'আকিজ ফুড অ্যান্ড বেভারেজ',
            'phone' => '+8801711000002',
            'address_en' => 'Charpara, Mymensingh',
            'address_bn' => 'চরপাড়া, ময়মনসিংহ',
        ]);
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

        $rows = [
            ['Fine Rice 50kg', 'মিনিকেট চাল ৫০ কেজি', $sack, '3400', '3550', '8901000000017'],
            ['Soyabean Oil 5L', 'সয়াবিন তেল ৫ লিটার', $unit, '820', '880', '8901000000024'],
            ['Lentil 1kg', 'মসুর ডাল ১ কেজি', $unit, '135', '150', '8901000000031'],
            ['Sugar 1kg', 'চিনি ১ কেজি', $unit, '118', '128', '8901000000048'],
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

        [$rice, $oil, $lentil, $sugar] = $made;

        // খোলা মজুদ — উৎস 'opening', যাতে ড্রিল-ডাউনে "কোথা থেকে এল"
        // প্রশ্নের একটা উত্তর থাকে
        foreach ([[$rice, '120'], [$oil, '75'], [$lentil, '240'], [$sugar, '180']] as [$product, $qty]) {
            $stock->move(
                product: $product, warehouse: $main,
                sourceType: 'opening', sourceId: $product->id,
                floor: $qty, narration: 'খোলা মজুদ',
            );
        }

        $stock->move(
            product: $rice, warehouse: $netrakona,
            sourceType: 'opening', sourceId: $rice->id,
            floor: '40', narration: 'খোলা মজুদ',
        );

        // অর্ডারে ধরা — মাল তাকেই আছে, কিন্তু অন্যের নামে
        $stock->move(
            product: $lentil, warehouse: $main,
            sourceType: 'sales_order', sourceId: 1,
            reserved: '60', documentNo: 'SO-000001',
        );

        $stock->move(
            product: $sugar, warehouse: $main,
            sourceType: 'sales_order', sourceId: 2,
            reserved: '25', documentNo: 'SO-000002',
        );

        // আটকানো — দুইটা কারণে, আর কারণ দুইটা এক জিনিস নয়
        $stock->hold($rice, $main, '30', $this->reason('HOLD-PRICE'));
        $stock->hold($sugar, $main, '12', $this->reason('HOLD-DMG'));
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
