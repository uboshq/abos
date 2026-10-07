<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Contracts\Drillable;
use App\Models\FinancialYear;
use App\Modules\Accounts\Services\YearEndService;

/**
 * ⭐ বছরশেষের সমাপনী ভাউচার — দেখা যায় এমন কাগজ (ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩ঙ, ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ সমাপনীর দাখিলা খাতায় বসে উৎস `year_close` আর বছরের id ধরে ([[YearEndService::close()]]); আলাদা কোনো সারি বা টেবিল নেই।
 * এই মডেল সেই বছরটাকেই কাগজ হিসেবে চেনায়: খাতা, ডে বুক বা লেজারের যেকোনো সারি থেকে "YC-…" চাপলে সমাপনীর পাতা খোলে।
 * ⓘ `financial_years` টেবিলের উপর, কেবল পড়ার জন্য — বছর বন্ধ-খোলা আগের মতোই [[YearEndService]]-এর।
 */
class YearClosing extends FinancialYear implements Drillable
{
    protected $table = 'financial_years';

    public static function drillSourceType(): string
    {
        return YearEndService::CLOSE_SOURCE;
    }

    public function drillDocumentNo(): string
    {
        return app(YearEndService::class)->closingNumberOf($this) ?? YearEndService::closingNumber($this);
    }

    public function drillLabel(): string
    {
        return __('accounts::voucher.closing_voucher').' — '.$this->drillDocumentNo();
    }

    public function drillRoute(): array
    {
        return ['accounts.year_end.closing', ['year' => $this->id]];
    }
}
