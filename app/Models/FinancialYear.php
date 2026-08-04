<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * অর্থবছর — কোন তারিখে এন্ট্রি নেওয়া যাবে তার সীমা।
 *
 * বন্ধ বছরে কোনো এন্ট্রি নয়। এটা শুধু নিয়ম নয়, হিসাবের ভিত্তি: বছর বন্ধ করে
 * ব্যালেন্স শিট দেওয়ার পর কেউ পুরনো তারিখে একটা ভাউচার বসালে সেই ব্যালেন্স
 * শিট আর সত্য থাকে না, অথচ সেটা ইতিমধ্যে ছাপা হয়ে ব্যাংকে চলে গেছে।
 */
class FinancialYear extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'company_id', 'name', 'starts_on', 'ends_on',
        'is_closed', 'closed_at', 'closed_by', 'is_current',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_closed' => 'boolean',
            'is_current' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function contains(Carbon|string $date): bool
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $date->betweenIncluded($this->starts_on, $this->ends_on);
    }

    public function isOpen(): bool
    {
        return ! $this->is_closed;
    }

    /** যে তারিখের এন্ট্রি, সেই তারিখ যে বছরে পড়ে — সেই বছরেই বসবে। */
    public static function forDate(Carbon|string $date): ?self
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return static::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();
    }
}
