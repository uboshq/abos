<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Approval\ApprovalRules;
use App\Core\Module\ModuleRegistry;
use App\Models\Approval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * ⛔ পুরনো অনুরোধের চাবির পেছনের অঙ্ক — খোলা ঘর থেকে এনক্রিপ্ট করা `payload`-এ (a6, ২১ অক্টোবর ২০২৬; fe-র উত্তর, বেতনের রান)।
 *
 * ⓘ নতুন অনুরোধ লেখার মুহূর্তেই ঢাকা পড়ে ([[ApprovalEngine::sealed()]])। ডিপ্লয়ের আগে বসা বেতনের রানের অনুরোধে নিট খোলা
 * `approvals.amount`-এ — এই কাজ সেগুলো সরায়। ⓘ মাইগ্রেশন নয় (মালিকের নিয়ম), কাজ; বারবার চালানো নিরাপদ (খালি ঘর বাদ)।
 * ⓘ `DB::table`, মডেল নয় — মডেলে সারিটা [[IsAudited]], আর বদলের অডিট-সারিতে পুরনো অঙ্কটা আবার খোলা লেখা হত।
 * ⚠️ অনুরোধের **তৈরির** পুরনো অডিট-সারিতে (`audit_field_changes`, ঘর `amount`) অঙ্কটা থেকে যায় — অডিট বদলানো যায় না;
 * সংখ্যাটা এই কাজ বলে দেয়।
 */
class SealGuardedApprovalAmounts extends Command
{
    protected $signature = 'abos:seal-approval-amounts {--dry : কেবল গোনা, কিছু বদলায় না}';

    protected $description = 'চাবির পেছনের কাজের (যেমন বেতনের রান) পুরনো অনুরোধের অঙ্ক খোলা ঘর থেকে এনক্রিপ্ট করা payload-এ সরায়';

    public function handle(ModuleRegistry $modules, ApprovalRules $rules): int
    {
        $sealed = 0;
        $audited = 0;

        // ⓘ কোম্পানি ধরে, একটা একটা — প্রতিটা প্রশ্নে কোম্পানির দেয়াল ([[EveryRawQueryNamesItsCompanyTest]])
        foreach (DB::table('companies')->pluck('id') as $companyId) {
        foreach ($modules->all() as $module) {
            foreach (array_keys($module->approvalRules) as $action) {
                if ($rules->amountKey($module->code, (string) $action) === null) {
                    continue;
                }

                $rows = DB::table('approvals')
                    ->where('company_id', $companyId)
                    ->where('module', $module->code)
                    ->where('action', $action)
                    ->whereNotNull('amount')
                    ->get(['id', 'amount', 'payload']);

                foreach ($rows as $row) {
                    $payload = json_decode((string) ($row->payload ?? 'null'), true);
                    $payload = is_array($payload) ? $payload : [];
                    $payload[Approval::SEALED_AMOUNT] = Crypt::encryptString((string) $row->amount);

                    if (! $this->option('dry')) {
                        DB::table('approvals')->where('company_id', $companyId)->where('id', $row->id)->update([
                            'amount' => null,
                            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        ]);
                    }

                    $sealed++;
                }

                $audited += DB::table('audit_field_changes as f')
                    ->join('audit_trails as t', 't.id', '=', 'f.audit_trail_id')
                    ->join('approvals as a', 'a.id', '=', 't.auditable_id')
                    ->where('a.company_id', $companyId)
                    ->where('t.auditable_type', Approval::class)
                    ->where('a.module', $module->code)
                    ->where('a.action', $action)
                    ->where('f.field', 'amount')
                    ->whereNotNull('f.new_value')
                    ->count();
            }
        }
        }

        $this->info(($this->option('dry') ? 'ঢাকা হবে' : 'ঢাকা হলো').": {$sealed} অনুরোধ।");
        $this->warn("পুরনো অডিট-সারিতে খোলা অঙ্ক রয়ে গেছে: {$audited} (অডিট বদলানো যায় না)।");

        return self::SUCCESS;
    }
}
