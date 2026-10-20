import '../../sync_engine/sync_status.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';

/// ⭐ ১০ · "সিঙ্কের অবস্থা" — শেষ সিঙ্ক কখন, এখন চলছে কি না, নেট আছে কি না, কত কাজ জমা (a6, ২০ অক্টোবর ২০২৬; চুক্তি
/// `E:\ABOS\app-widgets-ভিত্তি.md` §২)।
///
/// <p>⭐ সারাংশ সার্ভারের নয়, ফোনের — [localSummary] = [SyncStatus.widgetSummary]:
/// `{"lastOkAt": ISO|null, "running": bool, "failed": bool, "stale": bool, "offline": bool, "pending": int}`।
///
/// <p>অবস্থার ক্রম (আগেরটা জেতে): চলছে → নেট নেই ([WidgetTone.warn]) → ব্যর্থ ([WidgetTone.bad]) → পুরনো
/// ([WidgetTone.warn]) → ঠিক আছে ([WidgetTone.good])। ⓘ [LastSyncLabel.words]-এর একই ক্রম।
class SyncStateWidget extends LauncherWidgetKind {
  const SyncStateWidget({this.status});

  /// পরীক্ষায় নিজের; অ্যাপে [SyncStatus.instance]
  final SyncStatus? status;

  @override
  String get key => 'sync_state';

  @override
  String get title => 'সিঙ্কের অবস্থা';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x1];

  @override
  Map<String, dynamic>? localSummary() => (status ?? SyncStatus.instance).widgetSummary();

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final pending = summary['pending'] is num ? (summary['pending'] as num).toInt() : 0;
    final last = DateTime.tryParse('${summary['lastOkAt'] ?? ''}');
    final (String words, WidgetTone tone) = summary['running'] == true
        ? ('সিঙ্ক চলছে', WidgetTone.plain)
        : summary['offline'] == true
            ? ('নেট নেই', WidgetTone.warn)
            : summary['failed'] == true
                ? ('সিঙ্ক হয়নি', WidgetTone.bad)
                : summary['stale'] == true || last == null
                    ? ('সিঙ্ক পুরনো', WidgetTone.warn)
                    : ('সিঙ্ক ঠিক আছে', WidgetTone.good);
    return WidgetDoc(
      title: title,
      asOf: asOf,
      link: '/home/sync-status',
      hero: WidgetHero(
        WidgetText(words, tone: tone),
        caption: WidgetText(last == null ? 'শেষ সিঙ্ক: এখনো হয়নি' : 'শেষ সিঙ্ক: ${LastSyncLabel.ago(last, asOf)}'),
      ),
      rows: [
        WidgetRow(
          title: WidgetText('জমা কাজ: $pending', tone: pending > 0 ? WidgetTone.warn : WidgetTone.plain),
          second: const WidgetText('অফলাইনে জমা থাকে', tone: WidgetTone.muted),
        ),
      ],
    );
  }
}
