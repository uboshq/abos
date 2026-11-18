<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\DayChecklist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ বিকেলে কর্মীর হাতে কোম্পানির নগদ — সেই কর্মীকেই খবর। মালিক, ১৮ নভেম্বর ২০২৬:
 * *"মালিক এবং ব্যাংক ছাড়া যদি কর্মীর হাতে … টাকা থাকে, সেটার ওয়ার্নিংটাও ওই লোকটাকে দিবে বিকেলবেলায়"*।
 *
 * ⓘ নিয়ম [[DayChecklist::staffCash()]]-এর — নগদের খাত, আজকের শেষ জের শূন্যের বেশি, জিম্মাদার মালিক (সুপার অ্যাডমিন) নন।
 * ⓘ দিনে একবার, খাত ধরে (idempotency চাবি); জিম্মাদার নেই এমন নগদের খবর কাউকে যায় না — সেটা দিন বন্ধের নোটিশে থাকে।
 * ⛔ কেবল খবর — কিছু আটকায় না, কিছু বদলায় না।
 */
final class StaffCashAfternoon extends Command
{
    protected $signature = 'abos:staff-cash-afternoon';

    protected $description = 'Tell each staff member who still holds company cash this afternoon (owner, 18 Nov 2026)';

    public function handle(DayChecklist $check, NotificationService $notify): int
    {
        $today = now()->toDateString();
        $sent = 0;

        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyContext::forCompany((int) $companyId, function () use ($check, $notify, $today, &$sent) {
                foreach ($check->staffCash($today) as $row) {
                    if ($row['holder_id'] === null) {
                        continue;
                    }

                    $user = User::query()->find($row['holder_id']);

                    if ($user === null) {
                        continue;
                    }

                    $locale = (string) ($user->locale ?: config('app.locale'));
                    $amount = Money::format($row['amount']);
                    $notify->send(
                        $user,
                        'accounts.staff_cash_held',
                        __('accounts::dayclose.staff_cash.notice_title', ['amount' => $amount], $locale),
                        __('accounts::dayclose.staff_cash.notice_body', ['amount' => $amount, 'account' => $row['account']], $locale),
                        Route::has('accounts.custody') ? route('accounts.custody') : null,
                        evenToSelf: true,
                        key: 'accounts:staff_cash:'.$today.':'.$row['account_id'],
                    );
                    $sent++;
                }
            });
        }

        $this->info("staff cash notices: {$sent}");

        return self::SUCCESS;
    }
}
