import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/records/money.dart';
import '../../core/records/report_record.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../books/books_widgets.dart';
import '../reports/reports_screen.dart';
import 'owner_page_frame.dart';
import 'owner_pages_api.dart';

/// মালিকের কেন্দ্রের নিজের রিপোর্ট → ফোনের সাধারণ রিপোর্টের পর্দা ([ReportViewScreen], `GET /reports/{key}`)
Widget ownerReportScreen(OwnerReportCard card) => ReportViewScreen(report: ReportSummary(card.summary));

/// ⭐ মালিকের কেন্দ্র — বিশ্লেষণ, ফোনের নিজের পর্দায় (ওয়েবের `/owner/analysis`; মালিক, ১৭ অক্টোবর ২০২৬)।
///
/// <p>উপরে বাকির বয়স (সব কোম্পানির যোগ, শেষ ঘরে মোট বাকি), নিচে সাতটা তালিকা — সেরা দশ ক্রেতা, পণ্য, রুট, বিক্রয়কর্মী; কম লাভের
/// ক্রেতা, কম মার্জিনের পণ্য, অচল মাল। ⛔ প্রতিটা তালিকা কেন্দ্রীয় রিপোর্টের নিজের সারি ([[Rankings]]) — ফোন কিছু কষে না।
/// ঢাকা ঘর (খরচের চাবি নেই) "••••"। "ক্রেতা ধরে লাভ" ফোনের সাধারণ রিপোর্টের পর্দায় খোলে।
class OwnerAnalysisScreen extends StatefulWidget {
  const OwnerAnalysisScreen({super.key, this.api = const ServerOwnerPagesApi(), this.reportScreen = ownerReportScreen});

  final OwnerPagesApi api;

  /// পরীক্ষায় বদলানো যায় — আসলটা [ownerReportScreen]
  final Widget Function(OwnerReportCard card) reportScreen;

  @override
  State<OwnerAnalysisScreen> createState() => _OwnerAnalysisScreenState();
}

class _OwnerAnalysisScreenState extends State<OwnerAnalysisScreen> {
  String _period = 'month';

  static Map<String, String> _periods(AppL10n w) =>
      {'month': w.ownerPeriodMonth, 'three_months': w.ownerPeriodThreeMonths, 'year': w.ownerPeriodYear};

  /// ওয়েবের `executive::analysis` — ফোনের নিজের নাম, ARB থেকে
  static Map<String, String> _lists(AppL10n w) => {
        'customers': w.ownerListTopCustomers(10),
        'products': w.ownerListTopProducts(10),
        'areas': w.ownerListTopAreas(10),
        'salespeople': w.ownerListTopSalespeople(10),
        'losing': w.ownerListLosing,
        'thin': w.ownerListThin,
        'dead': w.ownerListDead,
      };

  static Map<String, String> _bucketsIn(AppL10n w) => {
        'bucket_current': w.ownerBucketCurrent,
        'bucket_30': w.ownerBucketOver(30),
        'bucket_60': w.ownerBucketOver(60),
        'bucket_90': w.ownerBucketOver(90),
        'outstanding': w.ownerOutstanding,
      };

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    final buckets = _bucketsIn(w);
    final lists = _lists(w);
    return OwnerPageFrame<OwnerAnalysis>(
        title: w.ownerAnalysisTitle,
        failed: w.ownerAnalysisFailed,
        reloadKey: _period,
        load: () => widget.api.analysis(_period),
        controls: [
          OwnerChips(prefix: 'period', choices: _periods(w), selected: _period, onSelected: (p) => setState(() => _period = p)),
        ],
        body: (context, data) => [
          OwnerNote(ownerRange(data.from, data.to)),
          SectionHeading(w.ownerAgeing),
          Card(
            key: const ValueKey('analysis-ageing'),
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                children: [
                  for (final b in data.buckets)
                    if (b.key != 'outstanding') FactRow(buckets[b.key] ?? b.label, Money.taka(b.value)),
                ],
              ),
            ),
          ),
          // ⭐ মোট বাকি — রিপোর্টের নিজের যোগফল, খোপগুলোর পাশে মোটের পট্টি
          for (final b in data.buckets)
            if (b.key == 'outstanding')
              OwnerTotal(key: const ValueKey('analysis-outstanding'), label: buckets[b.key] ?? b.label, value: Money.taka(b.value)),
          if (data.ageingRefused.isNotEmpty) OwnerNote(w.ownerRefused(data.ageingRefused.join(', '))),
          for (final list in data.lists) ...[
            SectionHeading(lists[list.key] ?? list.label),
            Card(
              key: ValueKey('analysis-list-${list.key}'),
              child: Column(
                children: [
                  if (list.rows.isEmpty)
                    Padding(padding: const EdgeInsets.all(AppSpacing.md), child: Text(w.ownerNothingInPeriod)),
                  for (final (i, row) in list.rows.indexed)
                    ListTile(
                      dense: true,
                      leading: CircleAvatar(radius: 13, child: Text('${i + 1}', style: const TextStyle(fontSize: 12))),
                      title: Text(row.name, maxLines: 2, overflow: TextOverflow.ellipsis),
                      subtitle: data.many ? Text(row.company) : null,
                      trailing: Text(
                        list.percent ? _percent(row.value) : ownerCell(row.value),
                        style: TextStyle(fontWeight: FontWeight.w700, color: ownerTone(context, row.value)),
                      ),
                    ),
                ],
              ),
            ),
            if (list.refused.isNotEmpty) OwnerNote(w.ownerRefused(list.refused.join(', '))),
          ],
          if (data.report != null) ...[
            SectionHeading(w.ownerReportsOwn),
            Card(
              child: ListTile(
                key: const ValueKey('analysis-report'),
                leading: const Icon(Icons.bar_chart_outlined),
                title: Text(data.report!.shownTitle),
                subtitle: Text(data.report!.shownHint),
                trailing: data.report!.allowed ? const Icon(Icons.chevron_right) : null,
                onTap: data.report!.allowed
                    ? () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => widget.reportScreen(data.report!)))
                    : null,
              ),
            ),
          ],
          if (!data.canSeeCost)
            OwnerNote(w.ownerNoCostKey, colour: context.palette.warning),
        ],
      );
  }

  /// মার্জিন % — ঢাকা "••••", না থাকা "—"
  static String _percent(String? value) {
    if (value == null) return '—';
    if (value == ownerHidden) return '••••';
    return '${Money.amount(value)}%';
  }
}
