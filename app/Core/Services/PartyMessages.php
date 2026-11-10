<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\PaperShareController;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\DocumentShare;
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
            $party = $this->partyOf($document)['name'];
        } finally {
            app()->setLocale($screen);
        }

        $amount = $document->getAttribute('total') ?? $document->getAttribute('amount');
        $date = $document->getAttribute('trx_date');
        $company = Company::query()->find(CompanyContext::id());

        return [
            'party' => $party,
            'paper_no' => (string) ($share->document_no ?? $document->getAttribute('document_no') ?? ''),
            'amount' => $amount === null ? '' : Money::format((string) $amount),
            'date' => $date === null ? '' : Carbon::parse($date)->format('d-m-Y'),
            'company' => (string) ($company === null ? '' : ($locale === 'en' ? ($company->name_en ?: $company->name_bn) : ($company->name_bn ?: $company->name_en))),
            'link' => route('paper.shared', $share->token),
        ];
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
     * @return list<array{id: int, name: string, bn: string, en: string}>
     */
    public function choicesFor(Model $document, DocumentShare $share): array
    {
        PartyMessageTemplate::ensureStarters((int) $share->company_id);

        $bn = $this->values($document, $share, 'bn');
        $en = $this->values($document, $share, 'en');

        return PartyMessageTemplate::query()
            ->usableFor(PartyMessageTemplate::WHATSAPP, (string) $share->document_type)
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
     * "পাঠান"-এর বাক্সের জন্য — লিংকটা যে কাগজের, তার পার্টি আর লেখাগুলো।
     *
     * ⓘ কাগজটা দেখার নাগালের বাইরে বা লিংক মরা হলে null — বাক্সে তখন পুরনো নম্বরহীন বোতামটাই থাকে।
     *
     * @return array{share: DocumentShare, party: array{type: ?string, id: ?int, name: string, phone: ?string}, choices: list<array{id: int, name: string, bn: string, en: string}>}|null
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

        return [
            'share' => $share,
            'party' => $this->partyOf($document),
            'choices' => $this->choicesFor($document, $share),
        ];
    }

    /** ⭐ খাতায় লেখা — লেখাটা সার্ভারেই বানানো, পাতা থেকে পাঠানো লেখা নয় */
    public function record(Model $document, DocumentShare $share, PartyMessageTemplate $template, string $locale, string $phone): PartyMessage
    {
        $party = $this->partyOf($document);

        return PartyMessage::query()->create([
            'company_id' => $share->company_id,
            'branch_id' => $document->getAttribute('branch_id') ?? $share->branch_id,
            'channel' => PartyMessageTemplate::WHATSAPP,
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
