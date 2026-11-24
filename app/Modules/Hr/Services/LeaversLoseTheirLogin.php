<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\Ownership;
use App\Core\Support\CompanyContext;
use App\Http\Middleware\RefuseInactiveAccounts;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ চাকরি ছাড়লে লগইনও বন্ধ — joiner-mover-leaver (ISO 27001 A.5.18, A.6.5; ঘাটতির তালিকা #২০, fe, ২০ অক্টোবর ২০২৬)।
 *
 * <p>⛔ আগে ছাড়ার তারিখ বসালে কেবল কর্মীর সারি নিষ্ক্রিয় হত — তাঁর সাথে বাঁধা লগইন চালু থাকত: চলে যাওয়া মানুষ ফোন আর
 * ওয়েবে ঢুকে গ্রাহক, দাম, বাকি দেখতে পারতেন, এমনকি অর্ডার বসাতে।
 *
 * <p>⭐ ছাড়ার দিন পেরোলে (দিনটা পর্যন্ত কাজ — [[EmployeeService::stillHere()]]-এর নিয়ম):
 *  - এই কোম্পানিতে তাঁর প্রবেশ বন্ধ (`company_user.is_active`) — অন্য বোন-কোম্পানিতে কাজ থাকলে সেখানে থাকে;
 *  - আর কোথাও সক্রিয় প্রবেশ না থাকলে পুরো লগইন নিষ্ক্রিয়;
 *  - দুই ক্ষেত্রেই তাঁর চলতি সেশন আর ফোনের টোকেন বাতিল ([[RefuseInactiveAccounts::revokeStandingAccess()]]);
 *  - নিরীক্ষার খাতায় একটা লাইন।
 * ⓘ মালিক কখনো এভাবে বন্ধ হন না (নিজের কোম্পানিতে তালা পড়ত)। ⓘ আবার যোগ দিলে লগইন নিজে খোলে না — প্রশাসক খোলেন, জেনে।
 * ⓘ আগের তারিখ দিলে সাথে সাথে; ভবিষ্যতের তারিখ হলে রোজকার কাজ ([[sweep()]]) দিন পেরোনোর পরের ভোরে।
 */
final class LeaversLoseTheirLogin
{
    /** @return bool কিছু বন্ধ হলো কি না */
    public function apply(Employee $employee): bool
    {
        $userId = $employee->user_id;
        $leaving = $employee->leaving_date;

        if ($userId === null || $leaving === null || ! Carbon::parse($leaving)->startOfDay()->lt(Carbon::today())) {
            return false;
        }

        $user = User::query()->find($userId);
        $companyId = (int) $employee->company_id;

        if ($user === null || app(Ownership::class)->isOwnerIn($user, $companyId)) {
            return false;
        }

        $closed = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        // ⓘ এই কোম্পানি ছাড়া আর কোথাও — এখানকার সারি উপরেই বন্ধ
        $elsewhere = DB::table('company_user')->where('company_id', '!=', $companyId)
            ->where('user_id', $user->id)->where('is_active', true)->exists();
        $deactivated = false;
        if (! $elsewhere && $user->is_active) {
            $user->forceFill(['is_active' => false])->save();
            $deactivated = true;
        }

        if ($closed === 0 && ! $deactivated) {
            return false;
        }

        RefuseInactiveAccounts::revokeStandingAccess($user);

        app(AuditEngine::class)->recordAction($employee, 'leaver_login_closed', __('hr::message.leaver_login_closed', [
            'name' => $user->name,
            'date' => Carbon::parse($leaving)->toDateString(),
        ]));

        return true;
    }

    /**
     * ⭐ রোজকার কাজ — সব কোম্পানির যাঁদের ছাড়ার দিন পেরিয়েছে অথচ প্রবেশ এখনো খোলা (ভবিষ্যতের তারিখে নোটিশ দেওয়া কর্মী)
     *
     * @return int কয়জনের বন্ধ হলো
     */
    public function sweep(): int
    {
        $closed = 0;

        Employee::query()->withoutGlobalScopes()
            ->whereNotNull('user_id')
            ->whereNotNull('leaving_date')
            ->whereDate('leaving_date', '<', Carbon::today())
            ->whereExists(fn ($q) => $q->from('company_user')
                ->whereColumn('company_user.user_id', 'hr_employees.user_id')
                ->whereColumn('company_user.company_id', 'hr_employees.company_id')
                ->where('company_user.is_active', true))
            ->orderBy('id')
            ->each(function (Employee $employee) use (&$closed): void {
                $closed += CompanyContext::forCompany((int) $employee->company_id, fn (): bool => $this->apply($employee)) ? 1 : 0;
            });

        return $closed;
    }
}
