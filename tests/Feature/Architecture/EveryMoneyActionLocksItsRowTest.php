<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * নতুন নিশ্চিত/অনুমোদন/বাতিল সারিতে তালা ছাড়া নয় — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ── ⛔ কেন ─────────────────────────────────────────────────────────────
 * চূড়ান্ত অডিটে (⛔৭, ⛔৮, ⛔১১) আর আজকের কাজে বারবার একই ছাঁচ: কাগজের অবস্থা দেখা হত হাতের কপি থেকে, লেনদেনের
 * বাইরে — দুই ট্যাব বা দুই ক্লিকে দ্বিতীয়টাও পার হত। সারাই একটাই: লেনদেনের ভিতরে সারিতে তালা দিয়ে অবস্থা তাজা
 * ([[ReadsTheRowUnderLock]], `lockForUpdate()`, বা সীমার তালা)। ⓘ এই পাহারা মডিউলের `Services`-এর প্রতিটা
 * `confirm/approve/cancel/post/close/reverse/finish/reopen/dispose/payout/declare` পড়ে, আর শরীরে (বা একই ক্লাসের
 * সরাসরি ডাকা সহায়কে) তালা না পেলে লাল।
 *
 * ── ⚠️ "এখনো বাকি" তালিকা ──────────────────────────────────────────────
 * আজ ৩০টা তালা ছাড়া — একসাথে সারানো ঝুঁকি, তাই তালিকায়। ⭐ তালিকা কেবল ছোট হয়: কেউ একটা সারালে এই পাহারাই বলে
 * "তালিকা থেকে তুলুন"; আর তালিকার বাইরে নতুন একটা এলে লাল।
 */
final class EveryMoneyActionLocksItsRowTest extends TestCase
{
    private const NAMES = ['confirm', 'approve', 'cancel', 'post', 'close', 'reverse', 'finish', 'finishHeld', 'reopen', 'dispose', 'payout', 'declare'];

    private const LOCK = '/lockFresh\(|lockForUpdate\(|assertRoomLocked\(|lockCustomer\(|lockPending\(|->lock\(|CashOnHand::lock|sharedLock\(/';

    /** ⚠️ ২ অক্টোবর ২০২৬-এর তালা-ছাড়া কাজ — কেবল ছোট হয়। */
    private const NOT_YET = [
        'Accounts/Services/BankReconciliationService.php::confirm',
        'Accounts/Services/BankReconciliationService.php::reopen',
        'Accounts/Services/ChequeService.php::cancel',
        'Accounts/Services/FixedAssetService.php::dispose',
        'Accounts/Services/YearEndService.php::close',
        'Accounts/Services/YearEndService.php::reopen',
        'Finance/Services/BankFacilityService.php::close',
        'Finance/Services/DepositService.php::cancel',
        'Hr/Services/LeaveService.php::approve',
        'Hr/Services/LeaveService.php::cancel',
        'Inventory/Services/StockService.php::reverse',
        'Promotion/Services/PromotionLifecycle.php::approve',
        'Promotion/Services/PromotionLifecycle.php::cancel',
        'Purchase/Services/PaymentService.php::cancel',
        'Purchase/Services/PurchaseOrderService.php::confirm',
        'Purchase/Services/PurchaseOrderService.php::cancel',
        'Purchase/Services/PurchaseReceiptService.php::cancel',
        'Purchase/Services/PurchaseRequisitionService.php::approve',
        'Purchase/Services/PurchaseRequisitionService.php::cancel',
        // ⓘ নিচের তিনটা নিজে কিছু লেখে না — ডাকে এমন সেবাকে যেটা তালা দেয়; তবু পাহারা শরীর পড়ে, তাই তালিকায়
        'Sales/Services/HeldCounterSaleFinisher.php::finish',
        'Sales/Services/SalesQuotationService.php::approve',
        'Sales/Services/SalesQuotationService.php::cancel',
        'Sales/Services/SchemeService.php::cancel',
        'Sales/Services/ShipmentService.php::close',
        'Sales/Services/ShipmentService.php::cancel',
        'Sales/Services/SignedChallanConfirmer.php::confirm',
    ];

    public function test_no_new_money_action_skips_the_row_lock(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 3)).'/app/Modules/';
        $missing = [];

        foreach (glob($root.'*/Services/*.php') ?: [] as $file) {
            foreach ($this->unlocked((string) file_get_contents($file)) as $method) {
                $missing[] = str_replace($root, '', str_replace('\\', '/', $file)).'::'.$method;
            }
        }

        $new = array_values(array_diff($missing, self::NOT_YET));
        $fixed = array_values(array_diff(self::NOT_YET, $missing));

        $this->assertSame([], $new, "⛔ নতুন কাজ সারিতে তালা ছাড়া — লেনদেনের ভিতরে lockFresh()/lockForUpdate() দিয়ে অবস্থা আবার দেখুন:\n".implode("\n", $new));
        $this->assertSame([], $fixed, "⭐ এগুলো এখন তালা দেয় — NOT_YET থেকে তুলুন:\n".implode("\n", $fixed));
    }

    /** ⚠️ পাহারা যেন অন্ধ না হয় — তালা-ছাড়া কাজ সত্যিই ধরা পড়ে, তালাওয়ালা পার হয়, আর সহায়কের তালাও গোনা হয়। */
    public function test_the_scanner_sees_the_dangerous_shape_and_nothing_else(): void
    {
        $code = <<<'PHP'
        <?php
        final class X
        {
            public function confirm($doc)
            {
                if ($doc->status !== 'draft') { throw new Exception(); }
                return DB::transaction(fn () => $doc->update(['status' => 'confirmed']));
            }

            public function cancel($doc)
            {
                return DB::transaction(function () use ($doc) { $this->lockFresh($doc); $doc->update([]); });
            }

            public function approve($doc)
            {
                return DB::transaction(fn () => $this->settle($doc));
            }

            public function show($doc)
            {
                return $doc;
            }

            private function settle($doc)
            {
                Doc::query()->whereKey($doc->id)->lockForUpdate()->first();
            }
        }
        PHP;

        $this->assertSame(['confirm'], $this->unlocked($code));
    }

    /** @return list<string> তালা ছাড়া কাজের নাম */
    private function unlocked(string $source): array
    {
        $out = [];

        preg_match_all('/\n    public function (\w+)\(/', $source, $methods, PREG_OFFSET_CAPTURE);

        foreach ($methods[1] as [$name, $offset]) {
            if (! in_array($name, self::NAMES, true)) {
                continue;
            }

            $body = $this->bodyAt($source, $offset);

            preg_match_all('/\$this->(\w+)\(/', $body, $calls);

            foreach (array_unique($calls[1]) as $helper) {
                if (preg_match('/\n    (?:private|protected|public) function '.$helper.'\(/', $source, $h, PREG_OFFSET_CAPTURE) === 1) {
                    $body .= $this->bodyAt($source, $h[0][1] + strlen($h[0][0]));
                }
            }

            if (preg_match(self::LOCK, $body) !== 1) {
                $out[] = $name;
            }
        }

        return $out;
    }

    private function bodyAt(string $source, int $from): string
    {
        $next = preg_match('/\n    (?:public|private|protected) function /', $source, $m, PREG_OFFSET_CAPTURE, $from + 1) === 1
            ? $m[0][1]
            : strlen($source);

        return substr($source, $from, $next - $from);
    }
}
