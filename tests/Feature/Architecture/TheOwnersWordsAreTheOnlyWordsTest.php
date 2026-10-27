<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Support\PaperStates;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * ⭐ এক ধারণা, এক শব্দ — ওয়েব আর অ্যাপে একই (মালিকের কথা, fe-র মাধ্যমে, ২১ অক্টোবর ২০২৬; অনুমোদনের শব্দ আন্তর্জাতিক
 * ERP-র — SAP, Oracle, Dynamics, Odoo: Approve · Approver · Pending approval — মালিক, ২৫ অক্টোবর ২০২৬: "int. standrad onuzayi
 * kajta ekhoni kore felo")।
 * উৎস: `resources/design/paper_states.json` v5 (a6 2acf887b6) — অ্যাপের চিপ আর ওয়েবের চিপ একই ফাইল পড়ে।
 *
 * মালিকের শব্দ:
 *  - অনুমোদনের বোতাম: "অনুমোদন দিন" (ছুটি আর চাহিদাতেও; "সই দিন" / "মঞ্জুর করুন" / "অনুমোদন করুন" নয়);
 *  - অপেক্ষা: "অনুমোদনের অপেক্ষায়" ("সইয়ের অপেক্ষায়" নয়); যিনি অনুমোদন দেন তিনি "অনুমোদনকারী";
 *  - "সই" কেবল আসল স্বাক্ষরে — কলমের (ছাপা কাগজ, চেক, পাওনার চিঠি) বা ডকুমেন্টের ডিজিটাল স্বাক্ষরে, নিচের ছাড়ে নাম-ধরে;
 *  - পোস্ট: "পোস্ট হয়েছে · খাতায় উঠেছে" (মজুদে "… · মজুদে উঠেছে", ছোট রূপ "পোস্ট হয়েছে") — "পাকা" আর নেই;
 *  - সংশোধনের জন্য ফেরত: "সংশোধনে ফেরত" (শুধু "ফেরত পাঠানো" নয়);
 *  - বিজ্ঞপ্তি: "বিজ্ঞপ্তি" ("নোটিফিকেশন" নয়, বিজ্ঞপ্তি মডিউলে "খবর" নয়);
 *  - হাজিরা: "হাজিরা" ("উপস্থিতি" নয়)।
 *
 * দাবি:
 *  - বাংলা ভাষা-ফাইলের (মডিউলের `Resources/lang/bn/*.php` আর `lang/bn/*.php`) কোনো দেখানো লেখায় পুরনো শব্দ নেই।
 *    ⓘ কেবল লেখা (PHP-র স্ট্রিং টোকেন) দেখা হয়, মন্তব্য নয় — মন্তব্য পর্দায় যায় না।
 *    ⛔ `lang/bn/core.php` আর `lang/bn/validation.php` বাদ — ওগুলো এই কাজে বদলানো নিষেধ (fe-র নিয়ম); ওদের পুরনো শব্দ fe-র তালিকায়।
 *  - যেখানে পুরনো শব্দটার মানে অন্য (মালের ফেরত, ডকুমেন্টের আলাদা অনুমোদন ধাপ ...), সেটা নিচের তালিকায় নাম-ধরে, কারণসহ;
 *    ⛔ তালিকার প্রতিটা ছাড় সত্যিই কোনো লেখায় লাগে — বাসি ছাড় পড়ে থাকে না।
 *  - পর্দায় যে অবস্থার লেখা দেখায় (posted, waiting, sent_back, rejected) তা ফাইলের লেখার হুবহু সমান, আর অনুমোদনের
 *    বোতাম হুবহু "অনুমোদন দিন" — ছুটি আর চাহিদাতেও।
 *  - core.php-র কেবল fe-র অনুমতির তিনটা জায়গা: status.awaiting_approval, notice.more_in_bell, বিজ্ঞপ্তির ব্লক।
 */
final class TheOwnersWordsAreTheOnlyWordsTest extends TestCase
{
    /** অনুমোদনের বোতাম — ফাইলে বোতামের লেখা নেই (ফাইলটা অবস্থার), তাই সিদ্ধান্তটা এখানে (মালিক, ২৫ অক্টোবর ২০২৬) */
    private const APPROVE = 'অনুমোদন দিন';

    /** ডকুমেন্ট মডিউলের "সই" আসল ডিজিটাল স্বাক্ষর (ফাইলের হ্যাশে বাঁধা) — অনুমোদন নয়, তাই "সই"-এর নিয়ম সেখানে খাটে না */
    private const SIGNATURE_MODULE = 'app/Modules/Documents/';

    /**
     * পুরনো শব্দ → কেন বাদ। নিয়মটা regex (u)।
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const RETIRED = [
        'posted_paka' => ['/পাকা/u', '"পাকা" মানে পোস্ট — এখন "পোস্ট হয়েছে" / "পোস্ট হওয়া" / "পোস্ট করুন"'],
        'approve_korun' => ['/অনুমোদন করুন/u', 'অনুমোদনের বোতাম "অনুমোদন দিন"'],
        // ⓘ আগে-পরে বাংলা অক্ষর থাকলে অন্য শব্দ ("জিজ্ঞেসই", "মাসই") — সেটা ধরে না
        'sign_for_approve' => ['/(?<![\x{0980}-\x{09FF}])সই/u', 'অনুমোদনের কাজ "অনুমোদন" ("সই" কেবল আসল স্বাক্ষরে)'],
        'manjur' => ['/মঞ্জুর(?!ি)/u', 'অনুমোদন "অনুমোদন দিন" / "অনুমোদিত", প্রত্যাখ্যান "প্রত্যাখ্যাত" ("মঞ্জুরি" — ব্যাংকের ঋণ — আলাদা)'],
        'sent_back_old' => ['/(?<!সংশোধনে )ফেরত পাঠা/u', 'সংশোধনের জন্য ফেরত "সংশোধনে ফেরত"'],
        'sent_back_draft' => ['/খসড়ায় ফেরত গেছে/u', 'অনুমোদনকারী সংশোধনে ফেরালে "সংশোধনে ফেরত গেছে" (লেখক নিজে খসড়ায় ফেরালে সেটা আলাদা কাজ)'],
        'notification_en' => ['/নোটিফিকেশন/u', 'বিজ্ঞপ্তি "বিজ্ঞপ্তি"'],
        'attendance_old' => ['/উপস্থিতি/u', 'হাজিরা "হাজিরা"'],
        // ⓘ দফা ১৫ (অনুমোদন মডিউলের নকশা): অপেক্ষাটা অনুমোদনের, "সিদ্ধান্তের" নয়
        'decision_wait' => ['/সিদ্ধান্তের অপেক্ষায়/u', 'অপেক্ষার লেখা "অনুমোদনের অপেক্ষায়"'],
    ];

    /**
     * ⭐ অনুমোদন মডিউলে মালিকের শব্দ — দফা ১৫। ⓘ কেবল এই মডিউলে: "স্তর" অন্য জায়গায় অন্য মানে (মালের খরচের স্তর)।
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const APPROVAL_ONLY = [
        'approval_level' => ['/স্তর/u', 'অনুমোদনের ধাপ "ধাপ" ("ধাপ 2 / 3")'],
        'approval_bengali_digit' => ['/[০-৯]/u', 'অঙ্ক সবসময় ইংরেজিতে (মালিকের নিয়ম "খ")'],
    ];

    /** বিজ্ঞপ্তি মডিউলে "খবর" মানে বিজ্ঞপ্তিই — সেখানে আলাদা নিয়ম */
    private const NOTIFICATION_NEWS = '/খবর/u';

    /**
     * ছাড়: [নিয়ম, ফাইল (রেপোর মূল থেকে), লেখার যে অংশ মিলবে, কারণ]।
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const EXCEPTIONS = [
        ['sent_back_old', 'app/Modules/Purchase/Resources/lang/bn/validation.php', 'তার বেশি ফেরত পাঠানো যাবে না',
            'মাল সরবরাহকারীর কাছে ফেরত (ক্রয় ফেরত) — কাগজ সংশোধনে ফেরত নয়'],
        ['sent_back_old', 'app/Modules/Inventory/Resources/lang/bn/validation.php', 'ফেরত পাঠাতে হলে ক্রয় ফেরত করুন',
            'মাল সরবরাহকারীর কাছে ফেরত (ক্রয় ফেরত) — কাগজ সংশোধনে ফেরত নয়'],
        ['notification_news', 'app/Modules/Notification/Resources/lang/bn/channel.php', 'ফেরত-খবরের গোপন চাবি',
            'প্রোভাইডারের ফেরত-বার্তা (webhook) — আমাদের বিজ্ঞপ্তি নয়'],
        // ⓘ আসল স্বাক্ষর — কলমে বা ডিজিটাল — অনুমোদন নয়
        ['sign_for_approve', 'lang/bn/confirmation.php', 'সই ও সিল',
            'পাওনা মিলিয়ে দেখার চিঠি — গ্রাহক কাগজে সই আর সিল দিয়ে ফেরত দেন'],
        ['sign_for_approve', 'app/Modules/Finance/Resources/lang/bn/hand_loan_letter.php', 'নিচে সই করে ফেরত দিন',
            'হাতধারের চিঠি — প্রাপক কাগজে সই করে ফেরত দেন'],
        ['sign_for_approve', 'app/Modules/Finance/Resources/lang/bn/hand_loan_letter.php', 'প্রাপকের সই ও তারিখ',
            'হাতধারের চিঠির স্বাক্ষরের ঘর'],
        ['sign_for_approve', 'app/Modules/Accounts/Resources/lang/bn/validation.php', '"সই মেলেনি"',
            'চেক ফেরতের কারণ — ব্যাংকে চেকের স্বাক্ষর মেলেনি'],
        ['sign_for_approve', 'app/Modules/Governance/Resources/lang/bn/action.php', 'ডকুমেন্টে সই',
            'ডকুমেন্টের ডিজিটাল স্বাক্ষর (চাওয়া, হলো, হলো না) — অডিটের লেখা'],
        ['sign_for_approve', 'app/Modules/Governance/Resources/lang/bn/action.php', 'ডকুমেন্টের সই যাচাই',
            'ডকুমেন্টের ডিজিটাল স্বাক্ষর যাচাই — অডিটের লেখা'],
        ['sign_for_approve', 'app/Modules/Hr/Resources/lang/bn/field.php', 'প্রাপকের সই',
            'বেতনের কাগজে প্রাপকের স্বাক্ষরের ঘর'],
        ['sign_for_approve', 'app/Modules/Sales/Resources/lang/bn/settings.php', 'সইয়ের ঘর',
            'ছাপা বিলের নিচে স্বাক্ষরের ঘর'],
        ['sign_for_approve', 'app/Modules/SystemAdmin/Resources/lang/bn/settings.php', 'সইয়ের ঘর',
            'ছাপা বিলের নিচে স্বাক্ষরের ঘর'],
        ['sign_for_approve', 'app/Modules/SystemAdmin/Resources/lang/bn/permission.php', 'সই চাওয়া',
            'ডকুমেন্টে ডিজিটাল স্বাক্ষর চাওয়ার অনুমতি (documents.signature_request)'],
    ];

    /** য় একটা অক্ষরে বা য + নুকতায় — দুই রূপ এক ধরে মেলানো */
    private static function same(string $text): string
    {
        return str_replace("\u{09DF}", "\u{09AF}\u{09BC}", $text);
    }

    /**
     * "rejected" নামের চাবিতে "বাতিল" থাকার ছাড়: [ফাইল, চাবি (ডট-পথ), কারণ]। fe, ২১ অক্টোবর ২০২৬: rejected = "প্রত্যাখ্যাত",
     * cancelled = "বাতিল" — দুটো আলাদা অবস্থা; "বাতিল" থাকতে পারে কেবল যেখানে মানেটাই বাতিল।
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const REJECTED_SAYS_CANCELLED = [
        ['app/Modules/Inventory/Resources/lang/bn/field.php', 'qc_rejected',
            'মান-পরীক্ষার ফল (মালের অংশ বাতিল), দলিলের অবস্থা নয় — তাই "মান-পরীক্ষায় বাতিল" (fe)'],
        ['app/Modules/Inventory/Resources/lang/bn/status.php', 'qc_rejected',
            'মান-পরীক্ষার ফল (মালের অংশ বাতিল), দলিলের অবস্থা নয় — তাই "মান-পরীক্ষায় বাতিল" (fe)'],
        ['app/Modules/Sales/Resources/lang/bn/auto_finish.php', 'rejected',
            'অনুমোদন প্রত্যাখ্যাত বলে, আর পরের কাজ বলে — বিক্রিটা সংশোধন বা বাতিল করা'],
        ['app/Modules/Sales/Resources/lang/bn/message.php', 'return_rejected',
            'অনুমোদন প্রত্যাখ্যাত হলে ফেরতটা সত্যিই বাতিল হয় (FinishTheReturnOnTheLastSignature → cancel)'],
        ['app/Modules/Sales/Resources/lang/bn/order_status.php', 'rejected_cannot_cancel',
            'ফেরানো আদেশ আবার বাতিল করা যায় না — বাতিল এখানে কাজটার নাম'],
    ];

    /** @return list<string> রেপোর মূল থেকে পথ */
    private function files(): array
    {
        $base = base_path();
        $files = array_merge(glob($base.'/app/Modules/*/Resources/lang/bn/*.php') ?: [], glob($base.'/lang/bn/*.php') ?: []);
        $files = array_map(fn (string $f): string => ltrim(str_replace('\\', '/', substr($f, strlen($base))), '/'), $files);

        return array_values(array_filter($files, fn (string $f): bool => ! in_array($f, ['lang/bn/core.php', 'lang/bn/validation.php'], true)));
    }

    /** @return list<string> ফাইলের দেখানো লেখাগুলো — কেবল স্ট্রিং টোকেন, মন্তব্য নয় */
    private function strings(string $file): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents(base_path($file))) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = stripcslashes(substr($token[1], 1, -1));
            }
        }

        return $out;
    }

    public function test_no_bengali_lang_string_says_a_retired_word(): void
    {
        $files = $this->files();
        $this->assertGreaterThan(300, count($files), 'প্রস্তুতিটাই ভুল — বাংলা ভাষা-ফাইল পাওয়া যায়নি।');
        $this->assertNotContains('lang/bn/core.php', $files);

        // ⓘ স্ক্যানার অন্ধ নয়: মন্তব্যে "পাকা" আছে এমন ফাইলের লেখা পড়া হয়, মন্তব্য নয়
        $this->assertNotEmpty($this->strings('app/Modules/Approval/Resources/lang/bn/action.php'), 'প্রস্তুতিটাই ভুল — লেখা পড়া গেল না।');

        $used = [];
        $found = [];
        foreach ($files as $file) {
            $rules = self::RETIRED;
            if (str_starts_with($file, 'app/Modules/Approval/')) {
                $rules += self::APPROVAL_ONLY;
            }
            if (str_starts_with($file, 'app/Modules/Notification/')) {
                $rules['notification_news'] = [self::NOTIFICATION_NEWS, 'বিজ্ঞপ্তি মডিউলে "খবর" নয়, "বিজ্ঞপ্তি"'];
            }

            if (str_starts_with($file, self::SIGNATURE_MODULE)) {
                unset($rules['sign_for_approve']);
            }

            foreach ($this->strings($file) as $text) {
                foreach ($rules as $rule => [$pattern, $why]) {
                    if (preg_match($pattern, $text) !== 1) {
                        continue;
                    }
                    $allowed = false;
                    foreach (self::EXCEPTIONS as $i => [$exRule, $exFile, $exText]) {
                        if ($exRule === $rule && $exFile === $file && str_contains(self::same($text), self::same($exText))) {
                            $allowed = true;
                            $used[$i] = true;
                        }
                    }
                    if (! $allowed) {
                        $found[] = "{$file}: [{$rule}] {$why} — \"".mb_substr($text, 0, 120).'"';
                    }
                }
            }
        }

        $this->assertSame([], $found, "⛔ মালিকের বাদ দেওয়া শব্দ এখনো পর্দার লেখায়:\n".implode("\n", $found));

        $stale = array_values(array_diff_key(self::EXCEPTIONS, $used));
        $this->assertSame([], $stale, '⛔ ছাড়ের তালিকায় বাসি ছাড় — যে লেখার জন্য ছাড়, সেটা আর নেই।');
    }

    public function test_no_rejected_key_says_cancelled(): void
    {
        $files = array_merge($this->files(), ['lang/bn/core.php']);
        $used = [];
        $found = [];
        $seen = 0;
        foreach ($files as $file) {
            $words = include base_path($file);
            if (! is_array($words)) {
                continue;
            }
            foreach (Arr::dot($words) as $key => $text) {
                if (! is_string($text) || stripos((string) $key, 'reject') === false) {
                    continue;
                }
                $seen++;
                if (! str_contains($text, 'বাতিল')) {
                    continue;
                }
                $allowed = false;
                foreach (self::REJECTED_SAYS_CANCELLED as $i => [$exFile, $exKey]) {
                    if ($exFile === $file && $exKey === (string) $key) {
                        $allowed = true;
                        $used[$i] = true;
                    }
                }
                if (! $allowed) {
                    $found[] = "{$file}: {$key} — \"{$text}\"";
                }
            }
        }

        $this->assertGreaterThan(20, $seen, 'প্রস্তুতিটাই ভুল — rejected নামের কোনো চাবি পাওয়া যায়নি।');
        $this->assertSame([], $found, "⛔ প্রত্যাখ্যাত কাগজকে \"বাতিল\" বলা হচ্ছে (বাতিল আলাদা অবস্থা):\n".implode("\n", $found));
        $stale = array_values(array_diff_key(self::REJECTED_SAYS_CANCELLED, $used));
        $this->assertSame([], $stale, '⛔ ছাড়ের তালিকায় বাসি ছাড় — যে লেখার জন্য ছাড়, সেটা আর নেই।');

        $this->assertSame('মান-পরীক্ষায় বাতিল', __('inventory::status.qc_rejected', [], 'bn'));
        $this->assertSame('মান-পরীক্ষায় বাতিল', __('inventory::field.qc_rejected', [], 'bn'));
    }

    public function test_the_shown_state_words_equal_the_shared_file(): void
    {
        $file = json_decode((string) file_get_contents(resource_path('design/paper_states.json')), true);
        $states = $file['states'];

        // ⓘ ফাইলটাই v5 — মালিকের শব্দ (অন্যথায় নিচের সমতা পুরনো শব্দকেও মেনে নিত)
        $this->assertSame('পোস্ট হয়েছে · খাতায় উঠেছে', $states['posted']['label_bn']);
        $this->assertSame('পোস্ট হয়েছে · মজুদে উঠেছে', $states['posted']['label_bn_stock']);
        $this->assertSame('পোস্ট হয়েছে', $states['posted']['label_bn_short']);
        $this->assertSame('পরিশোধিত', $states['posted']['label_bn_claim']);
        $this->assertSame(self::same('অনুমোদনের অপেক্ষায়'), self::same($states['waiting']['label_bn_without_level']));
        $this->assertSame('সংশোধনে ফেরত', $states['sent_back']['label_bn']);

        $want = [
            // posted
            'accounts::phone_voucher.state_posted' => $states['posted']['label_bn_short'],
            'finance::state.posted' => $states['posted']['label_bn'],
            // waiting
            'approval::status.pending' => $states['waiting']['label_bn_without_level'],
            'approval::dashboard.pending' => $states['waiting']['label_bn_without_level'],
            'accounts::control.awaiting' => $states['waiting']['label_bn_without_level'],
            'finance::state.awaiting' => $states['waiting']['label_bn_without_level'],
            'finance::field.waiting_approval' => $states['waiting']['label_bn_without_level'],
            'promotion::status.submitted' => $states['waiting']['label_bn_without_level'],
            'sales::order_status.state.awaiting_approval' => $states['waiting']['label_bn_without_level'],
            'sales::field.tab_awaiting_approval' => $states['waiting']['label_bn_without_level'],
            'sales::do.tab.approval' => $states['waiting']['label_bn_without_level'],
            // sent back
            'approval::status.returned' => $states['sent_back']['label_bn'],
            // ⓘ বোতামটা কাজের নাম, অবস্থার নয়: "সংশোধনে ফেরত পাঠান" (Return for correction)
            'approval::action.send_back' => $states['sent_back']['label_bn'].' পাঠান',
            'promotion::approval.send_back' => $states['sent_back']['label_bn'].' পাঠান',
            'accounts::vx.decision.returned' => $states['sent_back']['label_bn'],
            'governance::action.sent_back' => $states['sent_back']['label_bn'],
            // rejected — "ফেরত" নয়, যাতে সংশোধনে ফেরতের সাথে না মেশে
            'approval::status.rejected' => $states['rejected']['label_bn'],
            'accounts::vx.decision.rejected' => $states['rejected']['label_bn'],
            'governance::action.rejected' => $states['rejected']['label_bn'],
            // approve button
            'approval::action.approve' => self::APPROVE,
            'promotion::action.approve' => self::APPROVE,
            'promotion::approval.sign' => self::APPROVE,
            'sales::delivery_order.approve' => self::APPROVE,
            'accounts::action.approve_count' => self::APPROVE,
            // ছুটি আর চাহিদাও একই বোতাম (আগে "মঞ্জুর করুন")
            'hr::action.approve' => self::APPROVE,
            'purchase::action.approve_requisition' => self::APPROVE,
            'supplier::bank.sign' => self::APPROVE,
        ];

        foreach ($want as $key => $text) {
            $this->assertNotSame($key, __($key, [], 'bn'), "প্রস্তুতিটাই ভুল — {$key} চাবিটাই নেই।");
            $this->assertSame(self::same($text), self::same(__($key, [], 'bn')), "⛔ {$key} মালিকের শব্দ বলে না।");
        }

        /*
         * ⓘ core.php: fe-র অনুমতি, ২১ অক্টোবর ২০২৬ — শুধু status.awaiting_approval, notice.more_in_bell আর বিজ্ঞপ্তির
         * (`notify`) ব্লকের "খবর"; ফাইলের বাকিটা এই দাবির বাইরে।
         */
        $this->assertSame($states['waiting']['label_bn_without_level'], __('core.status.awaiting_approval', [], 'bn'), '⛔ core-এর অপেক্ষার লেখা মালিকের শব্দ নয়।');
        $bell = __('core.notice.more_in_bell', ['count' => 3], 'bn');
        $this->assertStringContainsString('বিজ্ঞপ্তি', $bell, '⛔ ঘণ্টার "আরও" লেখায় বিজ্ঞপ্তি নেই।');
        $this->assertStringNotContainsString('নোটিশ', $bell, '⛔ ঘণ্টার বিজ্ঞপ্তিকে "নোটিশ" বলা হলো।');
        $notify = (require lang_path('bn/core.php'))['notify'] ?? null;
        $this->assertIsArray($notify, 'প্রস্তুতিটাই ভুল — core.php-তে বিজ্ঞপ্তির ব্লক নেই।');
        $news = [];
        array_walk_recursive($notify, function ($text, $key) use (&$news): void {
            if (is_string($text) && str_contains($text, 'খবর')) {
                $news[] = "notify…{$key}: {$text}";
            }
        });
        $this->assertSame([], $news, "⛔ core.php-র বিজ্ঞপ্তির ব্লকে এখনো \"খবর\":\n".implode("\n", $news));

        // ⓘ fe, ২১ অক্টোবর ২০২৬: সেটিংসের দুই দলও — ঘণ্টা আর পাঠানো
        $this->assertSame('ঘণ্টার বিজ্ঞপ্তি', __('core.settings_group.bell', [], 'bn'), '⛔ সেটিংসের ঘণ্টার দল এখনো "খবর"।');
        $this->assertSame('বিজ্ঞপ্তি পাঠানো', __('core.settings_group.delivery', [], 'bn'), '⛔ সেটিংসের পাঠানোর দল এখনো "খবর"।');

        // ⓘ fe, ২১ অক্টোবর ২০২৬ (cb-র মাধ্যমে): উপরের ফিতা — সংখ্যার সাথে "টা", ইংরেজি অঙ্ক, "সই"
        $this->assertSame(self::same('1টা অনুমোদনের অপেক্ষায়'), self::same(trans_choice('core.notice.awaiting_decision', 1, ['count' => 1], 'bn')), '⛔ ফিতা এখনো "১টি সিদ্ধান্ত"।');
        $this->assertSame(self::same('3টা অনুমোদনের অপেক্ষায়'), self::same(trans_choice('core.notice.awaiting_decision', 3, ['count' => 3], 'bn')), '⛔ ফিতার সংখ্যা আর "টা" জোড়া নয়।');
        $this->assertSame('1টা লটের মেয়াদ 7 দিনের মধ্যে শেষ', trans_choice('core.notice.expiring_batches', 1, ['count' => 1, 'days' => 7], 'bn'), '⛔ লটের ফিতা এখনো "একটি"।');
        $this->assertSame('4টা লটের মেয়াদ 7 দিনের মধ্যে শেষ', trans_choice('core.notice.expiring_batches', 4, ['count' => 4, 'days' => 7], 'bn'), '⛔ লটের ফিতা এখনো ":count টি"।');

        // ⓘ fe, ২১ অক্টোবর ২০২৬ (a6-র paper_states): rejected = "প্রত্যাখ্যাত", cancelled = "বাতিল" — দুটো আলাদা অবস্থা, ওয়েবেও আলাদা লেখা
        $rejected = $states['rejected']['label_bn'];
        $this->assertSame('প্রত্যাখ্যাত', $rejected, '⛔ paper_states-এর rejected আর "প্রত্যাখ্যাত" নয়।');
        $this->assertNotSame($states['cancelled']['label_bn'], $rejected, '⛔ rejected আর cancelled এক লেখা পেয়েছে।');
        $this->assertSame($rejected, __('documents::catalog.status.rejected', [], 'bn'), '⛔ ডকুমেন্টের rejected অবস্থা এখনো "বাতিল"।');
        $this->assertSame($rejected.': চুক্তি', __('documents::notice.rejected', ['name' => 'চুক্তি'], 'bn'), '⛔ ডকুমেন্টের rejected নোটিশ এখনো "বাতিল"।');
        $this->assertSame('কেন '.$rejected.' হয়', __('approval::menu.report_why_rejected', [], 'bn'), '⛔ রিপোর্টের নাম এখনো "কেন বাতিল হয়"।');
        $this->assertSame('ডকুমেন্ট '.$rejected, __('governance::action.document_rejected', [], 'bn'), '⛔ audit-এর লেখা এখনো "ডকুমেন্ট বাতিল"।');
        $this->assertSame($rejected, __('governance::action.rejected', [], 'bn'));

        // ⓘ দফা ১৫ (fe-র অনুমতি, ২২ অক্টোবর ২০২৬): মাথার নোটিশও সইয়ের অপেক্ষা বলে, সিদ্ধান্তের নয়
        $this->assertSame(self::same('আপনার অনুমোদনের অপেক্ষায়'), self::same(__('core.notice.awaiting_mine', [], 'bn')), '⛔ মাথার নোটিশ এখনো "সিদ্ধান্তের অপেক্ষায়"।');

        // ⓘ চিপ নিজেও ফাইলের লেখা আঁকে
        $this->assertSame($states['posted']['label_bn_short_without_level'], PaperStates::chip('posted')['label']);
        $this->assertSame($states['posted']['label_bn_stock'], PaperStates::chip('posted', kind: 'stock')['label']);
        $this->assertSame($states['sent_back']['label_bn_short_without_level'], PaperStates::chip('sent_back')['label']);
        $this->assertSame($states['waiting']['label_bn_short_without_level'], PaperStates::chip('waiting')['label']);
    }
}
