<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ পার্টিকে পাঠানোর লেখা — বাংলা আর ইংরেজি, কোন কাগজের জন্য, কোন পথে (WhatsApp; SMS পরের ধাপে)।
 * ⓘ লেখার ভিতরে {party} {paper_no} {amount} {date} {company} {link} — পাঠানোর সময় আসল মান বসে ([[PartyMessages]])।
 */
class PartyMessageTemplate extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const WHATSAPP = 'whatsapp';

    public const SMS = 'sms';

    public const CHANNELS = [self::WHATSAPP, self::SMS];

    /**
     * ⓘ লেখায় যে নামগুলো বসানো যায় — এর বাইরের নাম রাখা যায় না।
     * ⭐ {due} — পার্টির এখনকার মোট বাকি (ধাপ ৩, নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব খ; [[PartyMessages::dueOf()]])।
     */
    public const VARIABLES = ['party', 'paper_no', 'amount', 'due', 'date', 'company', 'link'];

    protected $fillable = ['company_id', 'channel', 'document_type', 'name', 'body_bn', 'body_en', 'is_active', 'sort', 'created_by'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * ⭐ লেখা বদলালে Meta-র অনুমোদন আর খাটে না — নতুন লেখাটা আবার অনুমোদনে যেতে হয় (ধাপ ৩)।
     * ⓘ মডেলেই, কন্ট্রোলারে নয়: যে দরজা দিয়েই লেখা বদলাক, পুরনো "অনুমোদিত" দাগ নতুন লেখায় লেগে থাকে না।
     */
    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            if ($template->exists && $template->isDirty(['body_bn', 'body_en'])) {
                $template->setAttribute('wa_status', null);
            }
        });
    }

    /**
     * ঐ কাগজে চলে এমন চালু লেখাগুলো — কাগজের নিজেরটা আগে, তারপর "যেকোনো কাগজ"।
     *
     * ⓘ কয়েকটা ধরন একসাথে দেওয়া যায় (কাগজের নিজের ধরন আর "পার্টির হিসাব") — দেওয়া ক্রমেই আগে-পরে।
     *
     * @param  Builder<self>  $query
     * @param  string|list<string>  $documentType
     * @return Builder<self>
     */
    public function scopeUsableFor(Builder $query, string $channel, string|array $documentType): Builder
    {
        $types = array_values(array_unique((array) $documentType));

        $query->where('channel', $channel)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereIn('document_type', $types)->orWhereNull('document_type'))
            ->orderByRaw('document_type IS NULL');

        if (count($types) > 1) {
            $query->orderByRaw('CASE '.implode(' ', array_map(fn (int $i): string => 'WHEN document_type = ? THEN '.$i, array_keys($types))).' ELSE '.count($types).' END', $types);
        }

        return $query->orderBy('sort')->orderBy('id');
    }

    /**
     * ⭐ শুরুর লেখা — কাগজের ধরন, নাম, বাংলা, English, (পথ — না দিলে WhatsApp)। ⓘ অঙ্ক ইংরেজিতে, বাংলা লেখাতেও (মালিক, ২৪ অক্টোবর ২০২৬)।
     * ⓘ শেষের তিনটা ধাপ ৩-এর (নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব খ); আগে থেকে লেখা থাকা কোম্পানিতে migration
     *   2027_05_10_120000 বসায়, নামে মিলিয়ে।
     *
     * @var list<array{0: ?string, 1: string, 2: string, 3: string, 4?: string}>
     */
    public const STARTERS = [
        [null, 'সাধারণ — যেকোনো কাগজ',
            "আসসালামু আলাইকুম {party},\n{company} থেকে আপনার কাগজ {paper_no} পাঠানো হলো। দেখতে ও নামাতে:\n{link}\nলিংকটি 30 দিন খোলা থাকবে।",
            "Dear {party},\nPlease find your document {paper_no} from {company}:\n{link}\nThe link stays open for 30 days."],
        ['sales_invoice', 'বিক্রয় বিল',
            "প্রিয় {party},\n{company}-এর বিক্রয় বিল {paper_no} ({date}), মোট ৳{amount}।\nবিলটি দেখুন: {link}\nধন্যবাদ।",
            "Dear {party},\nInvoice {paper_no} from {company}, dated {date}, total Tk {amount}.\nView the invoice: {link}\nThank you."],
        ['sales_collection', 'টাকা পাওয়ার রসিদ',
            "প্রিয় {party},\nআপনার ৳{amount} পেয়েছি — রসিদ {paper_no} ({date})।\nরসিদ: {link}\nধন্যবাদ, {company}",
            "Dear {party},\nWe have received Tk {amount}, receipt {paper_no} dated {date}.\nReceipt: {link}\nThank you, {company}"],
        ['purchase_order', 'ক্রয় আদেশ',
            "প্রিয় {party},\n{company}-এর ক্রয় আদেশ {paper_no} ({date}) পাঠানো হলো:\n{link}\nঅনুগ্রহ করে মাল পাঠানোর তারিখ জানাবেন।",
            "Dear {party},\nPlease find purchase order {paper_no} from {company}, dated {date}:\n{link}\nKindly confirm the delivery date."],
        ['accounts_voucher', 'পরিশোধের খবর',
            "প্রিয় {party},\n{company} আপনাকে ৳{amount} পরিশোধ করেছে — ভাউচার {paper_no} ({date})।\nবিস্তারিত: {link}",
            "Dear {party},\n{company} has paid you Tk {amount}, voucher {paper_no} dated {date}.\nDetails: {link}"],
        [PartyMessage::PARTY_ACCOUNT, 'বাকির তাগাদা',
            "প্রিয় {party},\n{company}-এ আপনার বাকি ৳{due} (সবচেয়ে পুরনো বিল {paper_no}, {date})।\nবিস্তারিত: {link}\nধন্যবাদ।",
            "Dear {party},\nYour balance with {company} is Tk {due} (oldest bill {paper_no}, {date}).\nDetails: {link}\nThank you.",
            self::SMS],
        [PartyMessage::PARTY_ACCOUNT, 'হিসাব বিবরণী',
            "প্রিয় {party},\n{company}-এ আপনার হিসাব বিবরণী — এখনকার বাকি ৳{due}।\nদেখতে ও নামাতে: {link}\nধন্যবাদ।",
            "Dear {party},\nYour account statement with {company} — balance now Tk {due}.\nView and download: {link}\nThank you."],
        ['sales_collection', 'টাকা পেয়েছি (ছোট)',
            "প্রিয় {party},\nআপনার ৳{amount} পেয়েছি — রসিদ {paper_no} ({date})। বাকি এখন ৳{due}।\nধন্যবাদ, {company}",
            "Dear {party},\nWe received Tk {amount} — receipt {paper_no} ({date}). Your balance now is Tk {due}.\nThank you, {company}",
            self::SMS],
    ];

    /**
     * ⭐ কোম্পানির একটাও লেখা না থাকলে শুরুর লেখাগুলো বসানো — প্রথম যেদিন বাক্স বা লেখার পাতা খোলে।
     *
     * ⓘ "একটাও না" মানে বন্ধ করা লেখাও গোনা হয়: মালিক সব বন্ধ করে রাখলে আবার ফিরে আসে না।
     * ⓘ migration-এ বসানো হয়নি, কারণ তাহলে পরে খোলা কোম্পানির কোনো লেখা থাকত না।
     */
    public static function ensureStarters(int $companyId): void
    {
        if (static::query()->where('company_id', $companyId)->exists()) {
            return;
        }

        foreach (self::STARTERS as $i => $starter) {
            [$type, $name, $bn, $en] = $starter;

            static::query()->create([
                'company_id' => $companyId, 'channel' => $starter[4] ?? self::WHATSAPP, 'document_type' => $type,
                'name' => $name, 'body_bn' => $bn, 'body_en' => $en, 'is_active' => true, 'sort' => ($i + 1) * 10,
            ]);
        }
    }

    public function body(string $locale): string
    {
        return (string) ($locale === 'en' ? $this->body_en : $this->body_bn);
    }

    /** @return list<string> লেখায় থাকা অচেনা নাম */
    public static function unknownIn(?string $text): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', (string) $text, $m);

        return array_values(array_diff(array_unique($m[1]), self::VARIABLES));
    }
}
