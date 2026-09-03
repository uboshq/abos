<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * গণনার একটা লাইন — একটা পণ্য: খাতায় কত, হাতে কত, পার্থক্য কত।
 *
 * book_qty গণনার মুহূর্তে খাতার সংখ্যার snapshot, চলতি হিসাব নয় — কারণ
 * অনুমোদন পরদিন হলেও "গণনার সময় পার্থক্য কত ছিল" প্রশ্নের উত্তর যেন
 * হারিয়ে না যায় ([[StockCount]]-এর টীকা দেখুন)।
 *
 * reason_code_id ফাঁকা থাকে গণনার সময় — কেন পার্থক্য (নষ্ট · চুরি · গণনা-
 * ভুল) সেটা অনুমোদনকারী বসান, মাস্টার তালিকা থেকে, মুক্ত লেখা নয়।
 */
class StockCountLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'inv_stock_count_lines';

    protected $fillable = [
        'company_id', 'stock_count_id', 'product_id',
        'book_qty', 'counted_qty', 'difference', 'unit_cost', 'reason_code_id',
    ];

    protected function casts(): array
    {
        return [
            'book_qty' => 'decimal:4',
            'counted_qty' => 'decimal:4',
            'difference' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class, 'reason_code_id');
    }

    /** হাতে খাতার চেয়ে বেশি পাওয়া গেছে — উদ্বৃত্ত। */
    public function isSurplus(): bool
    {
        return bccomp((string) $this->difference, '0', 4) > 0;
    }

    /**
     * পার্থক্যের টাকা — কত টাকার মাল কম বা বেশি।
     *
     * দর জানা না থাকলে টাকা বলা যায় না, তখন খালি। অঙ্কটা সবসময় ধনাত্মক
     * (কম না বেশি সেটা isSurplus বলে), কারণ তালিকায় "৳১২,০০০ গরমিল"
     * পড়তে সহজ, "−৳১২,০০০" নয়।
     */
    public function varianceValue(): ?string
    {
        if ($this->unit_cost === null) {
            return null;
        }

        return bcmul(ltrim((string) $this->difference, '-'), (string) $this->unit_cost, 4);
    }
}
