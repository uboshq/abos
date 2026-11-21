<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Approval\SignedPaperFinisher;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Illuminate\Console\Command;

/**
 * ⭐ এককালীন — সই পড়া অথচ পোস্ট না হওয়া রসিদ আবার পোস্টের দরজায় (মালিক, ২১ নভেম্বর ২০২৬)।
 *
 * ⓘ লাইভে RCV-0537: সই পড়ল, অথচ শেষ সইয়ের পোস্ট ([[PostTheVoucherOnTheLastSignature]]) থামল "লেনদেন নম্বর 175300556 আগেই
 * বসেছে — ভাউচার RCV-0283"। নিয়মটা এখন বদলেছে (জমার ভাগ বৈধ — [[VoucherService::sameDeposit()]]), কিন্তু শেষ সই আর আসবে না;
 * রসিদটা খসড়াই থাকত।
 *
 * ⭐ একই দরজা, একই পাহারা — শেষ সইয়ের শ্রোতার হুবহু পথ: [[SignedPaperFinisher::finish()]] (লেখকের নামে, সইকারীর পুরনো তারিখের
 * অনুমতিসহ, কাগজের শাখায়) → [[VoucherWriter::post()]] (তালা, সই যাচাই, তারপর [[VoucherService::post()]]-এর সব যাচাই)। ⛔ কোনো
 * যাচাই এড়ানো হয় না, কোনো সারি হাতে লেখা হয় না।
 *
 * ⓘ `--apply` ছাড়া কিছুই লেখে না — কেবল বলে কী হবে: কাগজের অবস্থা, সই, আর একই জমার প্রশ্নের উত্তর। টাকা আছে কি না, মাস খোলা
 * কি না ইত্যাদি বাকি যাচাই কেবল আসল পোস্টে চলে; থামলে কারণটা ছাপা হয়, কাগজ খসড়াই থাকে।
 */
class PostHeldReceipt extends Command
{
    protected $signature = 'accounts:post-held-receipt
        {document_no : রসিদের নম্বর, যেমন RCV-0537}
        {--company= : কোম্পানির কোড (বাধ্যতামূলক), যেমন ADI}
        {--apply : সত্যিই পোস্ট করে; না দিলে কেবল দেখায়}';

    protected $description = 'Post an approved receipt that is still a draft, through the normal post and every check (dry run unless --apply)';

    public function handle(VoucherService $vouchers, SignedPaperFinisher $finisher): int
    {
        $code = trim((string) $this->option('company'));
        $number = trim((string) $this->argument('document_no'));
        $apply = (bool) $this->option('apply');

        if ($code === '') {
            $this->error('--company= লাগবে (যেমন --company=ADI)।');

            return self::FAILURE;
        }

        $company = Company::query()->where('code', $code)->first();

        if ($company === null) {
            $this->error("কোম্পানি {$code} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        return CompanyContext::forCompany((int) $company->id, fn (): int => $this->postOne($vouchers, $finisher, $company, $number, $apply));
    }

    private function postOne(VoucherService $vouchers, SignedPaperFinisher $finisher, Company $company, string $number, bool $apply): int
    {
        $voucher = Voucher::acrossBranches()->where('document_no', $number)->with('lines.account')->first();

        if ($voucher === null) {
            $this->error("{$company->code}: {$number} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $this->line(sprintf('%s  %s  type=%s  status=%s  party=%s#%s  amount=%s  number=%s  value_date=%s  transfer_mode=%s',
            $company->code, $voucher->document_no, $voucher->type, $voucher->status,
            $voucher->party_type ?? '-', $voucher->party_id ?? '-', (string) $voucher->amount,
            $voucher->instrument_no ?? '-', $vouchers->depositFacts($voucher)['date'] ?? '-', $voucher->transfer_mode_id ?? '-'));

        if ($voucher->type !== Voucher::RECEIPT) {
            $this->error('এটা রসিদ নয় — এই কমান্ড কেবল রসিদের জন্য।');

            return self::FAILURE;
        }

        if (! $voucher->isDraft()) {
            $this->error('রসিদটা খসড়া নয় ('.$voucher->status.') — পোস্ট করার কিছু নেই।');

            return self::FAILURE;
        }

        // ⓘ সই — সব অনুরোধের শেষটা অনুমোদিত, আর কোনোটা অপেক্ষায় নয়; নাহলে এটা "সই পড়া রসিদ" নয়, সাধারণ পথেই যাবে
        $approvals = Approval::query()
            ->whereIn('approvable_type', array_unique([Voucher::class, $voucher->getMorphClass()]))
            ->where('approvable_id', $voucher->id)
            ->orderByDesc('id')
            ->get();
        $latest = $approvals->first();

        if ($latest === null) {
            $this->error('এই রসিদে কোনো সইয়ের অনুরোধ নেই — এই কমান্ড কেবল সই পড়া অথচ পোস্ট না হওয়া রসিদের জন্য। সাধারণ পোস্টের পর্দা ব্যবহার করুন।');

            return self::FAILURE;
        }

        if ($approvals->contains(fn (Approval $a) => $a->status === Approval::PENDING) || $latest->status !== Approval::APPROVED) {
            $this->error("সই এখনো শেষ হয়নি (শেষ অনুরোধ #{$latest->id}: {$latest->status}) — পোস্ট নয়।");

            return self::FAILURE;
        }

        $signerId = ApprovalDecision::query()->where('approval_id', $latest->id)->where('decision', ApprovalDecision::APPROVED)
            ->orderByDesc('id')->value('user_id');
        $signer = $signerId === null ? null : User::query()->find((int) $signerId);
        $this->line(sprintf('approval #%d  %s  module=%s  action=%s  signer=%s  decided_at=%s',
            $latest->id, $latest->status, $latest->module, $latest->action, $signer?->name ?? '-', (string) ($latest->decided_at ?? '-')));

        // ⓘ একই জমার প্রশ্ন — পোস্টের একই উত্তর ([[VoucherService::sameDeposit()]]); কেবল দেখানোর জন্য, পোস্ট নিজে আবার জিজ্ঞেস করে
        $account = $voucher->lines->map(fn (VoucherLine $line) => $line->account)
            ->first(fn (?Account $a) => $a !== null && ($a->isBank() || $a->isMfs()));

        if ($account !== null && filled($voucher->instrument_no)) {
            $found = $vouchers->sameDeposit((int) $account->id, (string) $voucher->instrument_no, (int) $voucher->id, $vouchers->depositFacts($voucher));

            if ($found['twin'] !== null) {
                $this->warn("একই টাকা আগেই বসেছে — {$found['twin']->document_no} (একই পক্ষ, খাত, তারিখ): পোস্ট থামবে।");
            }

            if ($found['shared']->isNotEmpty()) {
                $this->line('জমার ভাগ — একই জমা আরও যে রসিদে: '.$found['shared']->pluck('document_no')->implode(', '));
            }
        }

        if (! $apply) {
            $this->info("Dry run: {$voucher->document_no} সাধারণ পোস্টের দরজায় যাবে (VoucherWriter::post, সব যাচাইসহ)। কিছুই লেখা হয়নি — চালাতে --apply দিন।");

            return self::SUCCESS;
        }

        // ⭐ শেষ সইয়ের শ্রোতার হুবহু পথ ([[PostTheVoucherOnTheLastSignature]])
        $reason = $finisher->finish(
            $voucher,
            $signer,
            route('accounts.voucher.show', $voucher),
            fn ($paper) => app(VoucherWriter::class)->post(Voucher::query()->withoutGlobalScope('user-branch')->findOrFail($paper->getKey())),
        );

        $fresh = Voucher::query()->withoutGlobalScope('user-branch')->find($voucher->id);

        if ($reason !== null || $fresh === null || ! $fresh->isPosted()) {
            $this->error("{$voucher->document_no} পোস্ট হয়নি — ".($reason ?? 'অবস্থা: '.($fresh->status ?? '?')));

            return self::FAILURE;
        }

        $this->info("{$voucher->document_no} পোস্ট হয়েছে।");

        return self::SUCCESS;
    }
}
