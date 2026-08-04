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
        });
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
