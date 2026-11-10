<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ এক ব্যাংকের চেকের পাতার মাপ — পাতার চওড়া-উঁচু, প্রতিটা ঘর কোথায় (mm), আর পুরো ছাপা সরানোর ডান-নিচ (মালিকের নমুনা,
 * ১০ নভেম্বর ২০২৬, পাতা ৪)। ⓘ ঘরের নাম [[FIELDS]]; ছাপে [[ChequePrinter]]।
 *
 * @property array<string, array<string, float|int>> $fields
 */
class ChequeLayout extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** ⓘ ছাপা হয় যে ঘরগুলো — সই নয় (হাতে) */
    public const FIELDS = ['date', 'payee', 'words', 'figures', 'crossing'];

    protected $table = 'acc_cheque_layouts';

    protected $fillable = ['company_id', 'name', 'width_mm', 'height_mm', 'fields', 'offset_x', 'offset_y', 'created_by'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'width_mm' => 'decimal:1', 'height_mm' => 'decimal:1', 'offset_x' => 'decimal:1', 'offset_y' => 'decimal:1'];
    }

    /**
     * ⭐ বাংলাদেশের সাধারণ চেকের পাতা, 203 × 89 mm — নমুনার মাপ; A/C Payee ওপরে মাঝখানে, অল্প হেলানো (মালিক, ১০ নভেম্বর ২০২৬:
     * "maje like"; NI Act ১২৩ ধারা জায়গা বাঁধে না — তারিখ, টাকার ঘর, সই আর MICR পট্টি থেকে দূরে)। ব্যাংকের আসল পাতা মেপে বদলানো যায়।
     *
     * @return array{width_mm: string, height_mm: string, fields: array<string, array<string, float|int>>}
     */
    public static function standard(): array
    {
        return [
            'width_mm' => '203.0',
            'height_mm' => '89.0',
            'fields' => [
                // ⓘ তারিখ: আট ঘর (দিন দিন মাস মাস বছর×৪), এক ঘর `box` mm চওড়া
                'date' => ['x' => 150.0, 'y' => 8.0, 'w' => 46.0, 'box' => 5.75, 'size' => 12],
                'payee' => ['x' => 24.0, 'y' => 24.0, 'w' => 150.0, 'size' => 11],
                'words' => ['x' => 22.0, 'y' => 34.0, 'w' => 125.0, 'size' => 10],
                'figures' => ['x' => 152.0, 'y' => 37.0, 'w' => 44.0, 'size' => 12],
                'crossing' => ['x' => 80.0, 'y' => 6.0, 'w' => 44.0, 'angle' => 12, 'size' => 8],
            ],
        ];
    }

    /**
     * একটা ঘরের মাপ, mm-এ, স্ট্রিং হিসেবে (bcmath — টাকার নিয়মের মতো এখানেও ভাসমান সংখ্যা নয়); অচেনা মান শূন্য।
     *
     * @return array<string, string>
     */
    public function field(string $name): array
    {
        $given = (array) ($this->fields[$name] ?? self::standard()['fields'][$name] ?? []);

        return array_map(fn ($v) => is_numeric($v) ? bcadd((string) $v, '0', 2) : '0.00', $given);
    }
}
