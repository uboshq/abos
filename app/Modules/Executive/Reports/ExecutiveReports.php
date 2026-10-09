<?php

declare(strict_types=1);

namespace App\Modules\Executive\Reports;

use App\Core\Engines\Report\ReportEngine;

/**
 * মালিকের কেন্দ্রের রিপোর্ট — কেন্দ্রীয় [[ReportEngine]]-এর উপরে, দ্বিতীয় ইঞ্জিন নয়।
 */
final class ExecutiveReports
{
    public static function registerAll(ReportEngine $engine): void {}
}
