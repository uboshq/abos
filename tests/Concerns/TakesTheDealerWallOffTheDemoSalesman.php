<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⓘ ডেমোর বিক্রয়কর্মী এখন ডিলারের দেয়ালের ভিতরে (মালিকের উত্তর "ক", ৩ অক্টোবর ২০২৬; ⛔১৬) — কেবল
 * বাঁধা ডিলার দেখেন, আর অর্ডার ছাড়া কিছু তৈরি করেন না।
 *
 * ⚠️ যে পরীক্ষা `sales@abos.test`-কে কেবল "একজন কর্মী" হিসেবে ব্যবহার করে — বিল কাটা, কাউন্টার, ফোনের
 * আদায়, নিজের তৈরি নতুন গ্রাহক — তার প্রশ্ন দেয়াল নয়। সেখানে চিহ্নটা `salesman` রোল থেকে তোলা হয়,
 * যাতে পরীক্ষাটা নিজের প্রশ্নই মাপে। ⓘ দেয়ালের দাবি আলাদা ফাইলে
 * ([[ASalesmanSeesOnlyHisOwnDealersTest]], [[TheSaleBelongsToTheSalesmanBoundThatDayTest]])।
 */
trait TakesTheDealerWallOffTheDemoSalesman
{
    protected function takeTheDealerWallOffTheDemoSalesman(string $companyCode = 'TDEPOT'): void
    {
        $company = Company::query()->where('code', $companyCode)->firstOrFail();

        CompanyContext::forCompany((int) $company->id, function (): void {
            Role::findByName('salesman', 'web')->revokePermissionTo(DealerScope::OWN);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();
    }
}
