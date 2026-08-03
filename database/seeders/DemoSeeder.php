<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\NumberSeries;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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
            'code' => 'ALPHA',
            'name_en' => 'Alpha Traders Ltd.',
            'name_bn' => 'আলফা ট্রেডার্স লিমিটেড',
            'address_en' => '12 Motijheel C/A, Dhaka 1000',
            'address_bn' => '১২ মতিঝিল বা/এ, ঢাকা ১০০০',
            'phone' => '+8801700000000',
            'bin' => '000123456-0101',
            'currency' => 'BDT',
            'locale' => 'bn',
        ]);

        $beta = Company::create([
            'code' => 'BETA',
            'name_en' => 'Beta Distribution',
            'name_bn' => 'বিটা ডিস্ট্রিবিউশন',
            'address_en' => '44 Agrabad, Chattogram',
            'address_bn' => '৪৪ আগ্রাবাদ, চট্টগ্রাম',
            'currency' => 'BDT',
            'locale' => 'bn',
        ]);

        foreach (['owner', 'accountant', 'salesman'] as $role) {
            Role::findOrCreate($role);
        }

        $this->setUpCompany($alpha, [
            ['code' => 'DHK', 'name_en' => 'Dhaka Head Office', 'name_bn' => 'ঢাকা প্রধান কার্যালয়', 'is_default' => true],
            ['code' => 'CTG', 'name_en' => 'Chattogram Depot', 'name_bn' => 'চট্টগ্রাম ডিপো'],
            ['code' => 'SYL', 'name_en' => 'Sylhet Depot', 'name_bn' => 'সিলেট ডিপো'],
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
                ['Al-Amin Shuvo', 'owner@abos.test', 'owner', 'ALPHA + BETA'],
                ['হিসাবরক্ষক', 'accounts@abos.test', 'accountant', 'ALPHA'],
                ['বিক্রয়কর্মী', 'sales@abos.test', 'salesman', 'ALPHA'],
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

            // প্রতিটা ডকুমেন্ট টাইপের সিরিজ। শাখাভিত্তিক নয় — কোম্পানি-ব্যাপী,
            // কারণ শুরুতে বেশিরভাগ প্রতিষ্ঠান এটাই চায়; শাখা আলাদা করতে চাইলে
            // Master Data থেকে যোগ করা যাবে।
            $types = [
                'SI' => ['sales', 'INV'],
                'SR' => ['sales', 'SRT'],
                'PI' => ['purchase', 'PUR'],
                'RV' => ['accounts', 'RCV'],
                'PV' => ['accounts', 'PAY'],
                'JV' => ['accounts', 'JRN'],
                'CV' => ['accounts', 'CON'],
                'MT' => ['accounts', 'TRF'],
                'CC' => ['accounts', 'CNT'],
                'CUS' => ['customer', 'CUS'],
            ];

            foreach ($types as $docType => [$module, $prefix]) {
                NumberSeries::create([
                    'module' => $module,
                    'doc_type' => $docType,
                    'prefix' => $prefix,
                    'format' => '{PREFIX}-{FY}-{SEQ}',
                    'padding' => 4,
                    'next_number' => 1,
                    'start_number' => 1,
                    'financial_year_id' => $year->id,
                ]);
            }
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
