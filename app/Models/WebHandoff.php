<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ অ্যাপের ভিতরে ওয়েব খোলার একবারের চাবি — [[WebHandoffApiController]] বানায়, [[WebHandoffController]] খরচ করে।
 *
 * ⛔ `BelongsToCompany` নেই, ইচ্ছা করে: চাবি খরচ হয় **লগইনের আগে**, যখন কোনো কোম্পানির প্রসঙ্গই নেই — খোঁজাটা চাবির
 * ছাপ ধরে, আর ছাপটা গোটা টেবিলে একক (`web_handoffs_token_once`)। কোম্পানি তবু সারিতে লেখা, আর খরচের সময় ঠিক সেই
 * কোম্পানিতেই ঢোকানো হয় ([[User::switchCompany()]] সদস্যপদ আবার দেখে)।
 *
 * ⓘ অডিট নেই — সারিটা একটা ঘটনা, কেবল যন্ত্র লেখে; ঢোকাটা লগইনের খাতায় ([[LoginJournal]]) যায়, যেমন বাকি সব দরজার।
 */
class WebHandoff extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'branch_id', 'user_id', 'token_hash', 'device_hash',
        'target', 'ip_address', 'expires_at', 'consumed_at', 'consumed_ip',
    ];

    protected $hidden = ['token_hash', 'device_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
