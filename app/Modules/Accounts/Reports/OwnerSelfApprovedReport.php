<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Approval\OwnerSelfApproval;
use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;

/**
 * ⭐ মালিকের নিজে পাকা করা কাগজ — মালিক, ২০ নভেম্বর ২০২৬ ("ok"): লেখক ≠ অনুমোদনকারী কর্মীদের নিয়ম, মালিককে আটকানো নয়;
 * বদলে প্রতিটা কাগজ যা তিনি নিজে লিখে নিজে অনুমোদন বা পোস্ট করলেন, এক তালিকায় — নিরীক্ষক এক পাতায় দেখেন।
 *
 * ⓘ সারি আসে বদলানো-যায়-না এমন সারি থেকে ([[OwnerSelfApproval::reportQuery()]]): সইয়ের সিদ্ধান্ত, নয়তো অডিট-খাতার প্রথম পোস্ট।
 * তারিখের পরিসর অনুমোদন বা পোস্টের দিন ধরে (ডিফল্ট এই মাস); শাখা কাগজের নিজের শাখা, মাথায় বাছা শাখা মেনে।
 * ⓘ চাবি `accounts.report` — হিসাবের রিপোর্টের দরজার চাবি ([[ReportController::authorizeReport()]])।
 */
final class OwnerSelfApprovedReport
{
    public const KEY = 'accounts.owner_self_approved';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::register());
    }

    public static function register(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            permission: 'accounts.report',
            title: 'accounts::menu.owner_self_approved',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => app(OwnerSelfApproval::class)->reportQuery($f),
            columns: [
                ['key' => 'paper_date', 'label' => 'accounts::owner_self.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'paper_type', 'label' => 'accounts::owner_self.type', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                [
                    'key' => 'document_no',
                    'label' => 'accounts::owner_self.number',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                    'width' => '10rem',
                ],
                ['key' => 'party', 'label' => 'accounts::owner_self.party', 'party' => 'party_key', 'width' => '14rem'],
                // ⓘ অঙ্কে চাপলে কাগজটাই খোলে ([[EveryReportFigureOpensItsSourceTest]])
                ['key' => 'amount', 'label' => 'accounts::owner_self.amount', 'type' => ReportColumn::MONEY,
                    'drill' => ['source' => ['type' => 'source_type', 'id' => 'source_id']]],
                ['key' => 'writer', 'label' => 'accounts::owner_self.writer', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'approver', 'label' => 'accounts::owner_self.approver', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'how', 'label' => 'accounts::owner_self.how', 'type' => ReportColumn::TEXT, 'width' => '6rem'],
                ['key' => 'approved_at', 'label' => 'accounts::owner_self.approved_at', 'type' => ReportColumn::DATE, 'width' => '7rem'],
            ],
            // ⓘ ব্যতিক্রমের তালিকা — শাখা ধরে ভাগে নতুন কিছু বলে না, আর প্রতিটা ভাগে গোটা UNION আবার চলত
            splitByBranch: false,
        );
    }
}
