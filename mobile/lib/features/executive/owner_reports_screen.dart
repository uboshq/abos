import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../reports/reports_screen.dart';
import 'owner_analysis_screen.dart';
import 'owner_page_frame.dart';
import 'owner_pages_api.dart';

/// ⭐ মালিকের কেন্দ্র — রিপোর্ট (ওয়েবের `/owner/reports`; মালিক, ১৭ অক্টোবর ২০২৬)।
///
/// <p>রিপোর্ট সেন্টারের দরজা (ফোনের সব রিপোর্টের পর্দা, [ReportsScreen]), মালিকের কেন্দ্রের নিজের রিপোর্ট (ফোনের সাধারণ রিপোর্টের
/// পর্দায় খোলে — তারিখ, PDF আর ভাগ করা সেখানেই), নিজের সংরক্ষিত দৃশ্য আর নির্ধারিত রিপোর্টের কথা। কেবল পড়া।
/// ⓘ সংরক্ষিত দৃশ্য ওয়েবের রিপোর্ট-পাতার ছাঁকনি — ফোনে তালিকা আর গোনা; রিপোর্টটা উপরের দরজা থেকে খোলে।
class OwnerReportsScreen extends StatelessWidget {
  const OwnerReportsScreen({
    super.key,
    this.api = const ServerOwnerPagesApi(),
    this.reportScreen = ownerReportScreen,
    this.centreScreen = _centre,
  });

  final OwnerPagesApi api;
  final Widget Function(OwnerReportCard card) reportScreen;
  final Widget Function() centreScreen;

  static Widget _centre() => const ReportsScreen();

  void _open(BuildContext context, Widget screen) =>
      Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => screen));

  @override
  Widget build(BuildContext context) => OwnerPageFrame<OwnerReports>(
        title: context.l10n.ownerReportsTitle,
        failed: context.l10n.ownerReportsFailed,
        load: api.reports,
        body: (context, data) => [
          Card(
            child: ListTile(
              key: const ValueKey('reports-centre'),
              leading: const Icon(Icons.library_books_outlined),
              title: Text(context.l10n.ownerReportsCentre),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => _open(context, centreScreen()),
            ),
          ),
          SectionHeading(context.l10n.ownerReportsOwn),
          for (final r in data.own)
            Card(
              child: ListTile(
                key: ValueKey('reports-own-${r.key}'),
                leading: const Icon(Icons.bar_chart_outlined),
                title: Text(r.shownTitle),
                subtitle: Text(r.allowed ? r.shownHint : context.l10n.ownerReportNotAllowed),
                trailing: r.allowed ? const Icon(Icons.chevron_right) : null,
                onTap: r.allowed ? () => _open(context, reportScreen(r)) : null,
              ),
            ),
          OwnerNote(context.l10n.ownerReportsExportNote),
          SectionHeading(context.l10n.ownerSavedViews(data.views.length)),
          if (data.views.isEmpty)
            OwnerNote(context.l10n.ownerNoSavedViews)
          else
            Card(
              key: const ValueKey('reports-views'),
              child: Column(
                children: [
                  for (final v in data.views)
                    ListTile(
                      dense: true,
                      leading: const Icon(Icons.bookmark_outline),
                      title: Text(v.name),
                      trailing: Text(v.company, style: TextStyle(fontSize: 12, color: context.palette.onSurfaceMuted)),
                    ),
                ],
              ),
            ),
          SectionHeading(context.l10n.ownerScheduledReports),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Text(
              data.canSchedule ? context.l10n.ownerSchedulesOnWeb : context.l10n.ownerNoScheduleKey,
              key: const ValueKey('reports-schedule'),
            ),
          ),
          const SizedBox(height: AppSpacing.xs),
          OwnerNote(context.l10n.ownerReportsInAppOnly),
        ],
      );
}
