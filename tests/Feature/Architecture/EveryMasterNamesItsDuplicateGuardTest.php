<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Engines\Duplication\DuplicationRegistry;
use App\Models\Branch;
use App\Models\Company;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use App\Modules\MasterData\Models\EmploymentType;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\TransferMode;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\MasterData\Models\VehicleType;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * নাম-ওয়ালা প্রতিটা মাস্টার তার নকল-পাহারা ঘোষণা করে — নিজে থেকে।
 *
 * ── কেন এই পাহারাটা লাগল ─────────────────────────────────────────────
 * [[DuplicateGuard]] বহু দিন ছিল, কিন্তু পণ্য ওটা ডাকত না — আর সেটা ধরা
 * পড়েছিল লাইভে, হুবহু এক নামে দুইটা পণ্য বসে যাওয়ার পর। "ইঞ্জিন আছে, দরজা
 * নেই" — এই একই ভুল এক দিনেই পাঁচবার পাওয়া গেছে (owner রোল · ব্যাকআপ ·
 * রিপোর্ট · …)। তালিকা ধরে পাহারা দিলে ষষ্ঠবারও ঘটত।
 *
 * তাই তালিকাটা স্কিমা থেকে আসে, হাতে লেখা নয় ([[PublicIdTest]]-এর ধাঁচে):
 * name_en **আর** name_bn — দুইটাই আছে এমন প্রতিটা মডেল হয় নকল-নিয়ম
 * ঘোষণা করেছে (module.php-র `duplicates`), নয় নিচের তালিকায় কারণসহ ছাড়
 * পেয়েছে। নতুন মাস্টার যোগ হলে সে দুইয়ের কোনোটাই না হলে এই টেস্ট লাল —
 * ঠিক যেদিন ভুলটা ঘটে, তার আগে নয়।
 */
class EveryMasterNamesItsDuplicateGuardTest extends TestCase
{
    /**
     * নাম-ওয়ালা যেসব মডেল এখনো নকল-নিয়ম ঘোষণা করে না — আর কেন।
     *
     * তিনটা কারণ, তিনটাই সৎ:
     *  ক · এটা ব্যবহারকারীর বানানো মাস্টার তালিকা নয় (পরিচয় · লেনদেনের লাইন)
     *  খ · অন্যভাবে সুরক্ষিত — নিজের সার্ভিসে সরাসরি DuplicateGuard ডাকে
     *  গ · ব্যাকলগ — দরজা ফেজ ১খ-তে বসবে (MasterListService), তখন এখান থেকে সরবে
     *
     * @var array<class-string, string>
     */
    private const EXEMPT = [
        // ক · মাস্টার তালিকা নয়
        Company::class => 'signup-এ তৈরি পরিচয়, ব্যবহারকারীর বানানো তালিকা নয়',
        Branch::class => 'প্রতিষ্ঠানের গড়ন, সেটআপে বসে; নাম-নকল অর্থহীন',
        PayslipLine::class => 'বেতনের লাইন — লেনদেনের বিস্তারিত, মাস্টার নয়',
        Employee::class => 'মানুষ একই নাম রাখে ("মোঃ রহিম" বহু); নামে নকল ধরা ভুল হত',

        // খ · অন্যভাবে সুরক্ষিত — সরাসরি DuplicateGuard ডাকে
        Customer::class => 'CustomerService নিজে DuplicateGuard ডাকে (ফোন হার্ড, নাম নরম)',
        Supplier::class => 'SupplierService নিজে DuplicateGuard ডাকে',

        // গ · ব্যাকলগ — ফেজ ১খ-তে MasterListService/নিজ-সার্ভিসে দরজা বসবে
        Brand::class => 'ব্যাকলগ ১খ — MasterListService',
        ProductCategory::class => 'ব্যাকলগ ১খ — MasterListService',
        Unit::class => 'ব্যাকলগ ১খ — MasterListService',
        Tax::class => 'ব্যাকলগ ১খ — MasterListService',
        PaymentTerm::class => 'ব্যাকলগ ১খ — MasterListService',
        PartyType::class => 'ব্যাকলগ ১খ — MasterListService',
        PriceList::class => 'ব্যাকলগ ১খ — MasterListService',
        ReasonCode::class => 'ব্যাকলগ ১খ — MasterListService',
        Currency::class => 'ব্যাকলগ ১খ — MasterListService',
        Department::class => 'ব্যাকলগ ১খ — MasterListService',
        Designation::class => 'ব্যাকলগ ১খ — MasterListService',
        EmploymentType::class => 'ব্যাকলগ ১খ — MasterListService',
        VehicleType::class => 'ব্যাকলগ ১খ — MasterListService',
        TransferMode::class => 'ব্যাকলগ ১খ — MasterListService',
        Vehicle::class => 'ব্যাকলগ ১খ — নিজ-সার্ভিস',
        PaymentMethod::class => 'ব্যাকলগ ১খ — নিজ-সার্ভিস',
        Location::class => 'ব্যাকলগ ১খ — LocationService (স্তরভিত্তিক)',
        Warehouse::class => 'ব্যাকলগ ১খ — WarehouseService',
        Account::class => 'ব্যাকলগ — Accounts মডিউল, নিজ-সার্ভিস',
        CashTill::class => 'ব্যাকলগ — Accounts মডিউল',
        CostCenter::class => 'ব্যাকলগ — Accounts মডিউল',
        DepositKind::class => 'ব্যাকলগ — Finance মডিউল',
        LeaveType::class => 'ব্যাকলগ — Hr মডিউল',
        SalaryHead::class => 'ব্যাকলগ — Hr মডিউল',
    ];

    public function test_every_name_bearing_master_is_declared_or_exempt(): void
    {
        $declared = app(DuplicationRegistry::class)->declaredModels();
        $exempt = array_keys(self::EXEMPT);

        $unguarded = [];

        foreach ($this->nameBearingModels() as $model) {
            if (in_array($model, $declared, true) || in_array($model, $exempt, true)) {
                continue;
            }

            $unguarded[] = $model;
        }

        $this->assertSame([], $unguarded, implode("\n", [
            'এই মাস্টারগুলোয় name_en ও name_bn আছে, অথচ নকল-পাহারা ঘোষিত নয়।',
            'module.php-র duplicates-এ যোগ করুন (ইঞ্জিন DuplicationEngine),',
            'নয়তো EXEMPT তালিকায় কারণসহ রাখুন।',
            ...$unguarded,
        ]));
    }

    /**
     * ছাড়ের তালিকাটা যেন পচে না যায় — মুছে ফেলা মডেল এখানে থেকে গেলে ধরা পড়ে।
     */
    public function test_the_exempt_list_names_only_real_models(): void
    {
        $stale = array_filter(
            array_keys(self::EXEMPT),
            static fn (string $model): bool => ! class_exists($model),
        );

        $this->assertSame([], array_values($stale),
            'EXEMPT তালিকায় এমন ক্লাস আছে যা আর নেই: '.implode(', ', $stale));
    }

    /**
     * name_en আর name_bn — দুইটাই আছে এমন প্রতিটা মডেল, স্কিমা থেকে।
     *
     * ফাইল উৎস পড়ে দেখা (PublicIdTest যেভাবে দেখে), কারণ মডেলটা তুলতে
     * DB লাগে না — শুধু নামটা লাগে।
     *
     * @return list<class-string>
     */
    private function nameBearingModels(): array
    {
        $models = [];

        foreach ([app_path('Models'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), 'Models')) {
                    continue;
                }

                $source = File::get($file->getPathname());

                if (! str_contains($source, "'name_en'") || ! str_contains($source, "'name_bn'")) {
                    continue;
                }

                $class = $this->classOf($source);

                if ($class !== null && class_exists($class)) {
                    $models[] = $class;
                }
            }
        }

        return array_values(array_unique($models));
    }

    /** ফাইলের namespace + class মিলিয়ে পূর্ণ নাম। */
    private function classOf(string $source): ?string
    {
        if (! preg_match('/namespace\s+([^;]+);/', $source, $ns)) {
            return null;
        }

        if (! preg_match('/(?:^|\n)(?:final\s+|abstract\s+)?class\s+(\w+)/', $source, $cls)) {
            return null;
        }

        return trim($ns[1]).'\\'.$cls[1];
    }
}
