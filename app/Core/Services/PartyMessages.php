<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\PaperShareController;
use App\Core\Support\Money;
use App\Core\Support\PartyLedger;
use App\Models\Company;
use App\Models\DocumentShare;
use App\Models\LedgerEntry;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ⭐ পার্টিকে WhatsApp — কাগজের পার্টি, তার নম্বর, বেছে নেওয়া লেখা আসল মান বসিয়ে, আর খাতায় লেখা।
 * (মালিকের "ok", ১০ নভেম্বর ২০২৬; নমুনা U1X687u5MkwVfEL7ci7MGv)
 *
 * ⓘ কোর কোনো মডিউলের নাম জানে না: পার্টি আসে কাগজের নিজের ঘর থেকে ([[PaperParties::keyOf()]]), নম্বর আসে পার্টির
 * নিজের ঘর থেকে ([[PartyRegistry::paperFacts()]]), টাকা `total` বা `amount`, তারিখ `trx_date`।
 */
final class PartyMessages
{
    /** @var array<string, ?string> পার্টি ধরে বাকি — এক অনুরোধে একবার */
    private array $dues = [];

    public function __construct(
        private readonly PaperParties $papers,
        private readonly PartyRegistry $parties,
    ) {}

    /**
     * কাগজটার পার্টি — নাম আর নম্বর (নম্বর আন্তর্জাতিক রূপে, না থাকলে null)।
     *
     * @return array{type: ?string, id: ?int, name: string, phone: ?string}
     */
    public function partyOf(Model $document): array
    {
        $key = $this->papers->keyOf($document);

        if ($key === null) {
            return ['type' => null, 'id' => null, 'name' => '', 'phone' => null];
        }

        [$type, $id] = $key;
        $facts = $this->parties->paperFacts($type, $id);

        return [
            'type' => $type,
            'id' => $id,
            'name' => (string) ($facts['name'] ?? ''),
            'phone' => self::phone($facts['phone'] ?? null),
        ];
    }

    /**
     * ⭐ নম্বরটা WhatsApp যেভাবে চায় — কেবল অঙ্ক, দেশের কোড সহ (8801712345678)।
     *
     * ⓘ বাংলাদেশের ১১ অঙ্কের মোবাইল (01…) হলে সামনে 88; +880… বা 880… হলে যেমন আছে। বাংলা অঙ্কও চেনে।
     * ⛔ মোবাইল বলে চেনা না গেলে null — ভুল নম্বরে লেখা খোলার চেয়ে মানুষটাকে নম্বর লিখতে বলা ভালো।
     */
    public static function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', strtr((string) $raw, array_combine(
            ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ))) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (preg_match('/^01[3-9]\d{8}$/', $digits) === 1) {
            return '88'.$digits;
        }

        if (preg_match('/^8801[3-9]\d{8}$/', $digits) === 1) {
            return $digits;
        }

        // ⓘ বিদেশের নম্বর — দেশের কোড সহ লেখা থাকলে (৮০০-এর মতো ছোট বা ভুল নয়)
        if (! str_starts_with($digits, '0') && ! str_starts_with($digits, '880') && strlen($digits) >= 10 && strlen($digits) <= 15) {
            return $digits;
        }

        return null;
    }

    /**
     * ⭐ পার্টির যত মোবাইল নম্বর ব্যবস্থা জানে — পাঠানোর পপআপের "নম্বর" (ধাপ ৩, নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব ক)।
     *
     * ⓘ কোর মডিউলের নাম জানে না: পার্টির নিজের সারির ঘরগুলো — `mobile`, `phone`, `whatsapp`, `owner_phone`, `contact_phone` —
     *   যেটা আছে আর মোবাইল বলে চেনা যায়। পাশের নাম: মালিকের নাম (`owner_name`) বা যোগাযোগের মানুষ (`contact_person`)।
     * ⓘ একই নম্বর দুইবার নয়; ক্রম: পার্টির খাতার মূল নম্বর আগে।
     *
     * @return list<array{phone: string, shown: string, label: string}>
     */
    public function phonesOf(Model $document): array
    {
        $key = $this->papers->keyOf($document);
        $row = $key === null ? null : $this->parties->find($key[0], $key[1]);

        if ($row === null) {
            return [];
        }

        $owner = (string) ($row->getAttribute('owner_name') ?? '');
        $contact = (string) ($row->getAttribute('contact_person') ?? '');
        $fields = [
            'mobile' => $owner, 'phone' => $owner, 'whatsapp' => $owner, 'owner_phone' => $owner, 'contact_phone' => $contact,
        ];

        $out = [];

        foreach ($fields as $field => $who) {
            $phone = self::phone((string) ($row->getAttribute($field) ?? ''));

            if ($phone === null || isset($out[$phone])) {
                continue;
            }

            $out[$phone] = ['phone' => $phone, 'shown' => self::shown($phone), 'label' => $who];
        }

        return array_values($out);
    }

    /** ⓘ পর্দায় নম্বর যেভাবে মানুষ লেখেন — দেশের মোবাইল 01…, বিদেশের + সহ */
    public static function shown(string $phone): string
    {
        return str_starts_with($phone, '880') ? substr($phone, 2) : '+'.$phone;
    }

    /**
     * ⭐ পার্টির এখনকার মোট বাকি — {due} (ধাপ ৩)।
     *
     * ⓘ পক্ষের খাতার একই ভিত্তি যা হিসাব বিবরণী আর গ্রাহকের পাতা পড়ে ([[Customer::outstanding()]], [[PartyLedger]]): পক্ষের সব
     *   দাখিলা, সব শাখার — পার্টির কাছে তার পুরো বাকিটাই যায়, পাঠানো মানুষটার শাখারটা নয়। সম্পাদনার উল্টো-জোড়া মিলে শূন্য।
     * ⓘ গ্রাহক (ও বাকিরা): ডেবিট − ক্রেডিট; সরবরাহকারী: ক্রেডিট − ডেবিট (আমাদের দেনা)। শূন্যের নিচে (অগ্রিম) হলে 0।
     */
    public function dueOf(?string $type, ?int $id): ?string
    {
        if ($type === null || $id === null || $id <= 0) {
            return null;
        }

        $net = (string) (PartyLedger::withoutUndoneEdits(LedgerEntry::query()->forParty($type, $id))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net') ?? '0');

        $due = $type === 'supplier' ? bcsub('0', $net, 4) : bcadd($net, '0', 4);

        return bccomp($due, '0', 4) > 0 ? $due : '0';
    }

    /**
     * ⭐ লেখা বাছার ধরন — কাগজের ধরন, কেবল একটা ব্যতিক্রম: টাকা পাওয়ার ভাউচার (RCV) "রসিদ"-এর লেখা পায়।
     *
     * ⓘ টাকা ঢোকার কাগজ এখন একটাই — RCV (মালিক, ২০ নভেম্বর ২০২৬), আর সেটা ভাউচার; ভাউচারের "পরিশোধের খবর" লেখা ("আপনাকে পরিশোধ
     *   করেছে") রসিদে উল্টো কথা বলত। তাই টাকা-পাওয়ার ভাউচার রসিদের ধরন (`sales_collection`, পর্দায় "রসিদ (RCV)") নেয়।
     */
    public static function messageType(Model $document, string $documentType): string
    {
        return $documentType === 'accounts_voucher' && $document->getAttribute('type') === 'receipt'
            ? 'sales_collection'
            : $documentType;
    }

    /**
     * লেখায় বসানোর মান — চাওয়া ভাষায়।
     *
     * @return array<string, string>
     */
    public function values(Model $document, DocumentShare $share, string $locale): array
    {
        /*
         * ⓘ পার্টির নামও লেখার ভাষায় — ইংরেজি লেখায় "Rahim Traders", বাংলায় "রহিম ট্রেডার্স"।
         * ⚠️ পর্দার ভাষা ধরলে বাংলা পর্দা থেকে পাঠানো ইংরেজি লেখায় বাংলা নাম যেত।
         */
        $screen = app()->getLocale();
        app()->setLocale($locale);

        try {
            $of = $this->partyOf($document);
            $party = $of['name'];
        } finally {
            app()->setLocale($screen);
        }

        // ⓘ দুই ভাষায় একই বাকি — একবারই গোনা
        $dueKey = $of['type'].':'.$of['id'];
        $due = $this->dues[$dueKey] ??= $this->dueOf($of['type'], $of['id']);

        $amount = $document->getAttribute('total') ?? $document->getAttribute('amount');
        $date = $document->getAttribute('trx_date');
        $company = Company::query()->find(CompanyContext::id());

        return [
            'party' => $party,
            'paper_no' => (string) ($share->document_no ?? $document->getAttribute('document_no') ?? ''),
            'amount' => $amount === null ? '' : self::taka((string) $amount),
            'due' => $due === null ? '' : self::taka($due),
            'date' => $date === null ? '' : Carbon::parse($date)->format('d-m-Y'),
            'company' => (string) ($company === null ? '' : ($locale === 'en' ? ($company->name_en ?: $company->name_bn) : ($company->name_bn ?: $company->name_en))),
            'link' => route('paper.shared', $share->token),
        ];
    }

    /**
     * ⓘ টাকা বার্তার মতো — "86,400", পয়সা থাকলে "86,400.50" (নমুনা HUXqpfhxng8823eEFrvH54: ৳86,400)।
     */
    public static function taka(string $amount): string
    {
        $text = Money::format($amount);

        return str_ends_with($text, '.00') ? substr($text, 0, -3) : $text;
    }

    /** @param  array<string, string>  $values */
    public static function render(string $text, array $values): string
    {
        $out = preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', function (array $m) use ($values): string {
            if (! in_array($m[1], PartyMessageTemplate::VARIABLES, true)) {
                return $m[0];
            }

            $value = $values[$m[1]] ?? '';

            return $value === '' ? '—' : $value;
        }, $text) ?? $text;

        return trim($out);
    }

    /**
     * ঐ কাগজের জন্য চালু লেখাগুলো, দুই ভাষায় আসল মান বসিয়ে — বাক্সে দেখানোর জন্য।
     *
     * ⭐ পথ ধরে (ধাপ ৩): WhatsApp-এর পপআপে WhatsApp-এর লেখা, SMS-এ SMS-এর।
     * ⓘ কাগজের নিজের লেখা আগে, তারপর "পার্টির হিসাব"-এর (বাকির তাগাদা, হিসাব বিবরণী — পার্টি জানা থাকলেই), শেষে "যেকোনো কাগজ"।
     *
     * @return list<array{id: int, name: string, bn: string, en: string}>
     */
    public function choicesFor(Model $document, DocumentShare $share, string $channel = PartyMessageTemplate::WHATSAPP): array
    {
        PartyMessageTemplate::ensureStarters((int) $share->company_id);

        $bn = $this->values($document, $share, 'bn');
        $en = $this->values($document, $share, 'en');

        return PartyMessageTemplate::query()
            ->usableFor($channel, $this->typesFor($document, $share))
            ->get()
            ->map(fn (PartyMessageTemplate $t): array => [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'bn' => self::render($t->body('bn'), $bn),
                'en' => self::render($t->body('en'), $en),
            ])
            ->values()
            ->all();
    }

    /**
     * ⓘ ঐ কাগজে যে ধরনের লেখা চলে — কাগজের নিজের ধরন ([[messageType()]]), আর পার্টি জানা থাকলে "পার্টির হিসাব"।
     * ⓘ পাঠানোর দরজাও ([[PaperShareController]]) ঠিক এটাই মাপে — বাক্সে যা দেখা যায়, কেবল তা-ই পাঠানো যায়।
     *
     * @return list<string>
     */
    public function typesFor(Model $document, DocumentShare $share): array
    {
        $types = [self::messageType($document, (string) $share->document_type)];

        if ($this->papers->keyOf($document) !== null) {
            $types[] = PartyMessage::PARTY_ACCOUNT;
        }

        return $types;
    }

    /**
     * "পাঠান"-এর বাক্সের জন্য — লিংকটা যে কাগজের, তার পার্টি, নম্বরগুলো আর দুই পথের লেখা।
     *
     * ⓘ কাগজটা দেখার নাগালের বাইরে বা লিংক মরা হলে null — বাক্সে তখন পুরনো নম্বরহীন বোতামটাই থাকে।
     * ⭐ ধাপ ৩: `wame` / `sms` — কোন পথ খোলা ([[PartyMessageDispatcher::wameOn()]] / [[PartyMessageDispatcher::smsOn()]]),
     *   `phones` — পার্টির সব মোবাইল, `office_phone` — যে অফিসের ফোনের সিম থেকে SMS যাবে।
     *
     * @return array{share: DocumentShare, party: array{type: ?string, id: ?int, name: string, phone: ?string}, choices: list<array{id: int, name: string, bn: string, en: string}>, sms_choices: list<array{id: int, name: string, bn: string, en: string}>, phones: list<array{phone: string, shown: string, label: string}>, wame: bool, sms: bool, office_phone: ?string, locale: string}|null
     */
    public function box(int $shareId): ?array
    {
        $share = DocumentShare::query()->find($shareId);

        if ($share === null || ! $share->isAlive()) {
            return null;
        }

        try {
            $document = PaperShareController::visibleDocument((string) $share->document_type, (int) $share->document_id);
        } catch (HttpException) {
            return null;
        }

        $party = $this->partyOf($document);
        $phones = $this->phonesOf($document);

        // ⓘ কাগজের পার্টির খাতার নম্বরটা না পেলে (অন্য ঘরে লেখা) সেটাও তালিকায়
        if ($party['phone'] !== null && ! in_array($party['phone'], array_column($phones, 'phone'), true)) {
            array_unshift($phones, ['phone' => $party['phone'], 'shown' => self::shown($party['phone']), 'label' => '']);
        }

        $dispatcher = app(PartyMessageDispatcher::class);
        $sms = $dispatcher->smsOn();

        return [
            'share' => $share,
            'party' => $party,
            'choices' => $this->choicesFor($document, $share),
            'sms_choices' => $sms ? $this->choicesFor($document, $share, PartyMessageTemplate::SMS) : [],
            'phones' => $phones,
            'wame' => $dispatcher->wameOn(),
            // ⓘ SMS কেবল তখন, যখন পথটা চালু আর কোম্পানির অফিসের ফোন বসানো আছে (নমুনা ধাপ ২, ধাপ ৩ ট্যাব ঘ)
            'sms' => $sms,
            'office_phone' => $sms ? $this->officePhone() : null,
            'locale' => $dispatcher->defaultLocale(),
        ];
    }

    /** ⓘ যে অফিসের ফোন এখন সারি নেয় — সবচেয়ে সম্প্রতি দেখা চালু ফোনের নাম */
    private function officePhone(): ?string
    {
        $name = \App\Models\SmsGatewayPhone::query()->where('is_active', true)
            ->orderByDesc('last_seen_at')->orderBy('id')->value('name');

        return $name === null ? null : (string) $name;
    }

    /** ⭐ খাতায় লেখা — লেখাটা সার্ভারেই বানানো, পাতা থেকে পাঠানো লেখা নয় */
    public function record(Model $document, DocumentShare $share, PartyMessageTemplate $template, string $locale, string $phone, string $channel = PartyMessageTemplate::WHATSAPP): PartyMessage
    {
        $party = $this->partyOf($document);

        return PartyMessage::query()->create([
            'company_id' => $share->company_id,
            'branch_id' => $document->getAttribute('branch_id') ?? $share->branch_id,
            // ⓘ WhatsApp (খোলা হয়েছে) বা SMS (অফিসের ফোনের সারিতে) — একই লেখা, একই খাতা
            'channel' => $channel,
            'document_type' => $share->document_type,
            'document_id' => $share->document_id,
            'document_no' => $share->document_no,
            'party_type' => $party['type'],
            'party_id' => $party['id'],
            'party_name' => $party['name'] === '' ? null : mb_substr($party['name'], 0, 191),
            'phone' => $phone,
            'locale' => $locale,
            'template_id' => $template->id,
            'body' => self::render($template->body($locale), $this->values($document, $share, $locale)),
            'share_id' => $share->id,
            'sent_by' => Actor::userId(),
        ]);
    }
}
