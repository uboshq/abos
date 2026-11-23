<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\PartyAutoSend;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * ⭐ বাকির তাগাদা — নিজে থেকে (নমুনা HUXqpfhxng8823eEFrvH54, ট্যাব ঙ; [[PartyAutoSend::runDue()]])।
 *
 * ⓘ কয়েক মিনিট পরপর চলে, ঘড়ির এক মিনিটে নয় — ব্যাকআপের শিক্ষা ([[routes/console.php]]): ফসকানো মিনিটে গোটা দিনের তাগাদা বাদ
 *   যেত। কাজটা অবস্থা দেখে: কোম্পানির (বা শাখার) সময় হয়েছে কি, আর কে আজ পেয়ে গেছেন — তাই বারবার চললেও একজন দিনে একবারই পান।
 * ⚠️ কোম্পানি ধরে ধরে, `forCompany()` দিয়ে ([[ApprovalsDue]]-এর একই কারণ); এক কোম্পানি ভাঙলে বাকিরা থামে না।
 */
final class PartyReminders extends Command
{
    protected $signature = 'abos:party-reminders';

    protected $description = 'Send the automatic due reminders to overdue parties when each company\'s time has come';

    public function handle(PartyAutoSend $auto): int
    {
        $sent = 0;

        foreach (Company::query()->pluck('id') as $companyId) {
            try {
                $sent += (int) CompanyContext::forCompany((int) $companyId, fn () => $auto->runDue(now()));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("sent={$sent}");

        return self::SUCCESS;
    }
}
