import 'package:flutter/material.dart';

import '../../core/records/money.dart';
import '../../core/records/report_record.dart';
import '../../core/theme/app_colors.dart';
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

  static const Map<String, String> _periods = {'month': 'এই মাস', 'three_months': 'গত তিন মাস', 'year': 'এই বছর'};

  /// ওয়েবের `executive::analysis` — বাংলায়, ফোনের নিজের
  static const Map<String, String> _lists = {
    'customers': 'সেরা ১০ ক্রেতা',
    'products': 'সেরা ১০ পণ্য',
    'areas': 'সেরা ১০ রুট',
    'salespeople': 'সেরা ১০ বিক্রয়কর্মী',
    'losing': 'সবচেয়ে কম লাভের ক্রেতা',
    'thin': 'সবচেয়ে কম মার্জিনের পণ্য',
    'dead': 'অচল মাল (দাম ধরে)',
  };

  static const Map<String, String> _buckets = {
    'bucket_current': 'চলতি বাকি',
    'bucket_30': '৩০ দিনের বেশি',
    'bucket_60': '৬০ দিনের বেশি',
    'bucket_90': '৯০ দিনের বেশি',
    'outstanding': 'মোট বাকি',
  };

  @override
  Widget build(BuildContext context) => OwnerPageFrame<OwnerAnalysis>(
        title: 'বিশ্লেষণ',
        failed: 'বিশ্লেষণের তালিকা আনা গেল না।',
        reloadKey: _period,
        load: () => widget.api.analysis(_period),
        controls: [
          OwnerChips(prefix: 'period', choices: _periods, selected: _period, onSelected: (p) => setState(() => _period = p)),
        ],
        body: (context, data) => [
          OwnerNote(ownerRange(data.from, data.to)),
          const SectionHeading('বাকির বয়স — সব কোম্পানি মিলিয়ে'),
          Card(
            key: const ValueKey('analysis-ageing'),
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                children: [
                  for (final b in data.buckets)
                    if (b.key != 'outstanding') FactRow(_buckets[b.key] ?? b.label, Money.taka(b.value)),
                ],
              ),
            ),
          ),
          // ⭐ মোট বাকি — রিপোর্টের নিজের যোগফল, খোপগুলোর পাশে মোটের পট্টি
          for (final b in data.buckets)
            if (b.key == 'outstanding')
              OwnerTotal(key: const ValueKey('analysis-outstanding'), label: _buckets[b.key] ?? b.label, value: Money.taka(b.value)),
          if (data.ageingRefused.isNotEmpty) OwnerNote('দেখার অনুমতি নেই: ${data.ageingRefused.join(', ')}'),
          for (final list in data.lists) ...[
            SectionHeading(_lists[list.key] ?? list.label),
            Card(
              key: ValueKey('analysis-list-${list.key}'),
              child: Column(
                children: [
                  if (list.rows.isEmpty)
                    const Padding(padding: EdgeInsets.all(AppSpacing.md), child: Text('এই সময়ে কিছু নেই।')),
                  for (final (i, row) in list.rows.indexed)
                    ListTile(
                      dense: true,
                      leading: CircleAvatar(radius: 13, child: Text('${i + 1}', style: const TextStyle(fontSize: 12))),
                      title: Text(row.name, maxLines: 2, overflow: TextOverflow.ellipsis),
                      subtitle: data.many ? Text(row.company) : null,
                      trailing: Text(
                        list.percent ? _percent(row.value) : ownerCell(row.value),
                        style: TextStyle(fontWeight: FontWeight.w700, color: ownerTone(row.value)),
                      ),
                    ),
                ],
              ),
            ),
            if (list.refused.isNotEmpty) OwnerNote('দেখার অনুমতি নেই: ${list.refused.join(', ')}'),
          ],
          if (data.report != null) ...[
            const SectionHeading('মালিকের কেন্দ্রের রিপোর্ট'),
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
            const OwnerNote('খরচ দেখার অনুমতি নেই — লাভ আর মার্জিনের ঘর ঢাকা থাকবে।', colour: AppColors.warning),
        ],
      );

  /// মার্জিন % — ঢাকা "••••", না থাকা "—"
  static String _percent(String? value) {
    if (value == null) return '—';
    if (value == ownerHidden) return '••••';
    return '${Money.amount(value)}%';
  }
}
