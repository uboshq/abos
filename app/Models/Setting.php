<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * একটা সেটিং। company_id null মানে প্রোডাক্টের ডিফল্ট, রো থাকলে কোম্পানির পছন্দ।
 *
 * BelongsToCompany ব্যবহার করা হয়নি ইচ্ছাকৃতভাবে: ডিফল্ট রো-গুলোর company_id
 * null, আর গ্লোবাল স্কোপ সেগুলো ঢেকে ফেলত — তখন কোনো কোম্পানি কিছু ওভাররাইড
 * না করলে সেটিংসের কোনো মানই পাওয়া যেত না।
 */
class Setting extends Model
{
    use HasFactory;
    use HasPublicId;

    protected $fillable = ['company_id', 'module', 'key', 'type', 'value', 'group'];

    /** স্ট্রিং হিসেবে সংরক্ষিত মানকে তার আসল ধরনে ফেরানো। */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'decimal' => (string) $this->value,   // টাকার মান কখনো float-এ নয়
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
