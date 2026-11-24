<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

/**
 * খাতার "purchase_voucher" নামের ঘর থেকে ভাউচারে ফেরার পথ — স্টক ছাড়া ক্রয় (PUV); একই টেবিল, কেবল খাতার নামটা নিজের।
 *
 * ⓘ লেখা হয় সবসময় [[TradeVoucher]] দিয়ে; এই ক্লাস কেবল ড্রিলের মানচিত্রে ([[AssetDisposalPaper]]-এর একই পথ)।
 */
final class PurchaseVoucherPaper extends TradeVoucher
{
    public static function drillSourceType(): string
    {
        return 'purchase_voucher';
    }
}
