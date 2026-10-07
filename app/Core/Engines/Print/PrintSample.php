<?php

declare(strict_types=1);

namespace App\Core\Engines\Print;

/**
 * ⭐ বানানো একটা নমুনা কাগজ — মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬।
 *
 * তাঁর কথা: *"print e invoice template vew kore deke select korar bebosta
 * koro. zate age sample dekha zay tarpor select kora zay"*।
 *
 * ── ⛔ কেন সত্যিকারের একটা বিল দেখানো যায় না ──────────────────────────
 * সবচেয়ে সহজ পথ ছিল ডেটাবেস থেকে শেষ বিলটা তুলে এনে আঁকা — দেখতেও
 * সবচেয়ে বিশ্বাসযোগ্য হত। ⚠️ কিন্তু তাতে নিয়ন্ত্রণের পর্দাটা একটা
 * **দ্বিতীয় দরজা** হয়ে যেত: যাঁর কাছে `settings.manage` আছে কিন্তু
 * বিক্রয়ের কাগজ দেখার অনুমতি নেই, তিনি ঐ পর্দা খুলেই একজন গ্রাহকের নাম
 * আর তাঁর দর পড়ে ফেলতেন। ⛔ আর ফাঁসটা নীরব — কোথাও কিছু লাল হত না।
 *
 * ⓘ তার উপর খালি ডেটাবেসে (নতুন কোম্পানির প্রথম দিন, যেদিন এই পর্দায়
 * মানুষ আসে) কোনো বিলই থাকে না, আর তখন নমুনার জায়গাটা ফাঁকা দেখাত।
 *
 * ⭐ তাই সংখ্যাগুলো বানানো, আর ইচ্ছে করেই **অসম্ভব রকম চেনা**: গ্রাহক
 * "নমুনা ট্রেডার্স", পণ্য "নমুনা পণ্য"। ⚠️ কেউ ভুল করে নমুনাটা ছেপে
 * ফেললেও এক নজরেই বোঝা যায় ওটা কারও বিল নয়।
 *
 * ── ⓘ কেন সারিগুলো এমনভাবে সাজানো ────────────────────────────────────
 * নমুনাটার কাজ রূপগুলোর **তফাত দেখানো**, তাই প্রতিটা রূপ যে যে অংশ
 * আঁকতে পারে তার সবকটাই এখানে আছে: ফ্রি পরিমাণ, পণ্যের কোড, সারির নিচের
 * টীকা, ব্যান্ডের উপ-মোট আর আদায়ের ছক। ⛔ এগুলোর একটাও বাদ দিলে দুইটা
 * রূপ নমুনায় হুবহু এক দেখাত, অথচ আসল কাগজে আলাদা — আর মালিক তখন ভুল
 * রূপটা বাছতেন।
 */
final class PrintSample
{
    /**
     * এই কাগজের নমুনা — টেমপ্লেট, ডেটা আর মাপ।
     *
     * @return array{template: string, paper: string, data: array<string, mixed>}
     */
    public static function for(string $target): array
    {
        $target = in_array($target, PrintProfile::TARGETS, true) ? $target : 'invoice';

        if ($target === 'voucher') {
            return [
                'template' => 'print.voucher',
                'paper' => PaperSize::A4,
                'data' => self::voucher(),
            ];
        }

        return [
            'template' => 'print.document',

            /*
             * ⚠️ পস রসিদের নমুনা রোলের মাপেই — A4-তে দেখালে মালিক
             * এমন একটা চেহারা দেখতেন যা কাউন্টারে কোনোদিন বেরোয় না।
             * ⓘ সরু কাগজে কলাম ছাঁটা হয় ([[PaperSize::maxColumns()]]),
             * আর ওটাই সেখানকার আসল সীমা।
             */
            'paper' => $target === 'pos' ? PaperSize::THERMAL_80 : PaperSize::A4,

            'data' => [
                'title' => __("core.print.sample.title.{$target}"),
                'doc' => self::document($target),
            ],
        ];
    }

    private static function document(string $target): PrintableDocument
    {
        return new PrintableDocument(
            title: __("core.print.sample.title.{$target}"),
            meta: [
                __('core.print.sample.meta.no') => 'SAMPLE-0001',
                __('core.print.sample.meta.date') => '23/09/2026',
                __('core.print.sample.meta.party') => __('core.print.sample.party'),
                __('core.print.sample.meta.branch') => __('core.print.sample.branch'),
            ],
            lines: self::lines(),
            totals: [
                __('core.print.sample.total.sub') => '11,270.00',
                __('core.print.sample.total.discount') => '270.00',
                __('core.print.sample.total.net') => '11,000.00',
            ],
            signatures: [
                __('core.print.sample.sign.receiver'),
                __('core.print.sample.sign.issuer'),
            ],
            showMoney: true,
            amountInWords: __('core.print.sample.words'),
            narration: __('core.print.sample.narration'),
            payments: self::payments(),
        );
    }

    /**
     * ⓘ তিনটা সারি, আর তিনটাই আলাদা কিছু দেখায়।
     *
     * ⚠️ সবগুলো এক রকম করলে ঘন (`compact`) আর খোলা (`roomy`) রূপের
     * তফাতটা চোখে পড়ত, কিন্তু ব্যান্ড বা টীকাওয়ালা রূপগুলোর নয়।
     * ⭐ তাই ব্যান্ডের উপ-মোট শেষ সারিতে, আর টীকা ও ফ্রি পরিমাণ
     * আলাদা আলাদা সারিতে — প্রতিটা অংশের নিজের একটা জায়গা।
     *
     * @return list<array<string, string>>
     */
    private static function lines(): array
    {
        $name = __('core.print.sample.item');

        return [
            [
                'code' => 'P-1001',
                'name' => $name.' ১',
                'unit' => __('core.print.sample.unit'),
                'qty' => '40',
                'free' => '4',
                'rate' => '120.00',
                'amount' => '4,800.00',
                'note' => __('core.print.sample.note'),
                'band_total' => '',
            ],
            [
                'code' => 'P-1002',
                'name' => $name.' ২',
                'unit' => __('core.print.sample.unit'),
                'qty' => '15',
                'free' => '',
                'rate' => '250.00',
                'amount' => '3,750.00',
                'note' => '',
                'band_total' => '',
            ],
            [
                'code' => 'P-1003',
                'name' => $name.' ৩',
                'unit' => __('core.print.sample.unit'),
                'qty' => '8',
                'free' => '',
                'rate' => '340.00',
                'amount' => '2,720.00',
                'note' => '',
                'band_total' => '11,270.00',
            ],
        ];
    }

    /**
     * আদায়ের ছকের দুইটা সারি।
     *
     * ⓘ ছকটা কেবল সেই রূপগুলোতে ওঠে যাদের `paid_table` আছে। ⚠️ খালি
     * রাখলে ঐ রূপগুলো নমুনায় তাদের প্রতিবেশীর মতোই দেখাত, আর ছকটাই
     * ছিল তাদের একমাত্র তফাত।
     *
     * @return list<array<string, mixed>>
     */
    private static function payments(): array
    {
        return [
            [
                'no' => 1,
                'ref' => 'RCV-0007',
                'date' => '20/09/2026',
                'method' => __('core.print.sample.method.cash'),
                'narration' => __('core.print.sample.narration'),
                'amount' => '5,000.00',
            ],
            [
                'no' => 2,
                'ref' => 'RCV-0011',
                'date' => '22/09/2026',
                'method' => __('core.print.sample.method.bank'),
                'narration' => __('core.print.sample.narration'),
                'amount' => '3,000.00',
            ],
        ];
    }

    /**
     * ভাউচারের নমুনা — নিজের ছাঁচ, কারণ কাগজটাও নিজের ছাঁচের।
     *
     * ⚠️ পণ্যের সারির ছাঁচে ডেবিট-ক্রেডিট বলে কিছু নেই
     * ([[PrintProfile::PARTS_NOT_ON]] একই কারণে লেখা), তাই এখানে
     * [[PrintableDocument]] নয় — `print.voucher` যা চায় ঠিক সেই নামেই।
     *
     * @return array<string, mixed>
     */
    private static function voucher(): array
    {
        return [
            'title' => __('core.print.sample.title.voucher'),
            'voucher' => [
                'document_no' => 'SAMPLE-0001',
                'date' => '23/09/2026',
                'party' => __('core.print.sample.party'),
                'branch' => __('core.print.sample.branch'),
                'narration' => __('core.print.sample.narration'),
                'lines' => [
                    [
                        'account' => '1010 '.__('core.print.sample.account.cash'),
                        'narration' => __('core.print.sample.narration'),
                        'debit' => '11,000.00',
                        'credit' => '',
                    ],
                    [
                        'account' => '4010 '.__('core.print.sample.account.sales'),
                        'narration' => __('core.print.sample.narration'),
                        'debit' => '',
                        'credit' => '11,000.00',
                    ],
                ],
                'total_debit' => '11,000.00',
                'total_credit' => '11,000.00',
                'amount_in_words' => __('core.print.sample.words'),
            ],
            'signatures' => [
                __('core.print.sample.sign.receiver'),
                __('core.print.sample.sign.issuer'),
            ],
            'notice' => null,
        ];
    }
}
