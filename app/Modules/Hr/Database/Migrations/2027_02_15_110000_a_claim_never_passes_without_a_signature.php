<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Hr\Support\ClaimSigner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ⛔ খরচের দাবি আর অগ্রিম কখনো সই ছাড়া নয় — চালু প্রতিটা কোম্পানিতে মালিকের সইয়ের ছক, যেখানে কোনো ছক নেই (সমন্বয়কের আদেশ,
 * অ্যাপ-অডিট ৭ অক্টোবর ২০২৬; [[ClaimSigner]], [[AClaimNeverPassesWithoutASignatureTest]])।
 *
 * ⓘ কোম্পানির নিজের ছক থাকলে ছোঁয়া হয় না। মালিকের রোল না পেলে সেই কোম্পানি বাদ, লগে নাম — দাবি তখন পাঠানোর মুহূর্তে থামে।
 * পাঠানোর মুহূর্তেও একই কাজ হয়, তাই এটা কেবল আগাম বসানো।
 */
return new class extends Migration
{
    public function up(): void
    {
        $made = [];
        $skipped = [];

        foreach (Company::query()->orderBy('id')->get() as $company) {
            foreach (ClaimSigner::ACTIONS as $action) {
                try {
                    if (CompanyContext::forCompany((int) $company->id, fn () => app(ClaimSigner::class)->ensure($action))) {
                        $made[] = $company->code.':'.$action;
                    }
                } catch (ValidationException) {
                    $skipped[] = $company->code.':'.$action;
                }
            }
        }

        Log::info('Expense claims: owner signature flows set', ['made' => $made, 'no_owner_role' => $skipped]);
    }

    public function down(): void
    {
        // ⓘ ছক একবার বসলে কোম্পানির নিজের হয়ে যায় — নামানো হয় না
    }
};
