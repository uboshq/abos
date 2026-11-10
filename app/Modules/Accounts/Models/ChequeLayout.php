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
     * ⭐ বাংলাদেশ ব্যাংকের চেকের মান — "Handbook for Cheque Design Standard's Specifications" (BACH, ২৪ ফেব্রুয়ারি ২০০৯), Figure 2:
     * পাতা 7.5" × 3.5" (190.5 × 88.9 mm), সব ব্যাংকের MICR চেকে একই (IBBL, DBBL, Prime, EBL, BRAC — মালিক, ১০ নভেম্বর ২০২৬: "online
     * theke calect kore naw")। ঘর: উপরের সারি 1.05", "Pay To" 0.40", "Sum of Taka" দুই লাইন 0.35" করে; টাকার ঘর 4.75" থেকে, Tk চিহ্নের
     * পরে, 1.55"–2.05" উঁচুতে; তারিখ ডান-উপরে আট ঘর (DDMMYYYY); নিচের 0.625" MICR পট্টি ফাঁকা। ⛔ ধারা 2.2: দাগ (A/C Payee)
     * "only … at the top left corner", দুই সমান্তরাল কোনাকুনি দাগ, নাম বা কথার ঘর ছোঁবে না — তাই বাঁ কোণে। পরীক্ষার ছাপে মিলিয়ে
     * ব্যাংক ধরে সরানো যায় (পুরো ছাপা ডানে/নিচে, বা ঘর ধরে)।
     *
     * @return array{width_mm: string, height_mm: string, fields: array<string, array<string, float|int>>}
     */
    public static function standard(): array
    {
        return [
            'width_mm' => '190.5',
            'height_mm' => '88.9',
            'fields' => [
                // ⓘ তারিখ: আট ঘর (দিন দিন মাস মাস বছর×৪), এক ঘর `box` mm (0.25") — Figure 2-এর ঘরের সারি 5.24" থেকে
                'date' => ['x' => 133.1, 'y' => 15.0, 'w' => 50.8, 'box' => 6.35, 'size' => 12],
                // ⓘ "Pay To The Order Of" লেখার পরে, 1.05"–1.45" সারিতে, নিচের দাগের ঠিক ওপরে
                'payee' => ['x' => 44.0, 'y' => 30.5, 'w' => 140.0, 'size' => 11],
                // ⓘ "Pay The Sum of Taka"-র পরে; দুই লাইন 0.35" (8.9 mm) ফাঁকে, টাকার ঘরের আগে শেষ
                'words' => ['x' => 44.0, 'y' => 39.5, 'w' => 75.0, 'size' => 10, 'line' => 8.9],
                // ⓘ টাকার ঘর 4.75" থেকে, ভিতরের Tk চিহ্নের (0.25") পরে
                'figures' => ['x' => 128.0, 'y' => 42.0, 'w' => 55.0, 'size' => 12],
                // ⓘ বাঁ কোণে কোনাকুনি, কিনারার ভিতরে আর "Pay To"-র সারির ওপরে (দাগ ছবিতে মেলানো, ১০ নভেম্বর ২০২৬)
                'crossing' => ['x' => 4.0, 'y' => 12.0, 'w' => 36.0, 'angle' => 35, 'size' => 7],
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
