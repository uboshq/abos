import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/sync_state.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';

import '../support/widget_claims.dart';

/// ⭐ ১০ · সিঙ্কের অবস্থা — [SyncStateWidget], সারাংশ ফোনের নিজের ([SyncStatus.widgetSummary]; a6, ২০ অক্টোবর ২০২৬)
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 10, 0);
  const kind = SyncStateWidget();

  Map<String, dynamic> s({
    String? lastOkAt = '2026-10-20T09:58:00.000Z',
    bool running = false,
    bool failed = false,
    bool stale = false,
    bool offline = false,
    int pending = 0,
  }) =>
      {'lastOkAt': lastOkAt, 'running': running, 'failed': failed, 'stale': stale, 'offline': offline, 'pending': pending};

  test('all well: green, last sync two minutes ago, nothing waiting', () {
    final doc = kind.build(s(), asOf: asOf);
    expectWidgetRules(doc);
    expect(doc.hero!.figure.text, 'সিঙ্ক ঠিক আছে');
    expect(doc.hero!.figure.tone, WidgetTone.good);
    expect(doc.hero!.caption!.text, 'শেষ সিঙ্ক: ২ মিনিট আগে');
    expect(doc.rows.single.title.text, 'জমা কাজ: 0');
    expect(doc.rows.single.second!.text, 'অফলাইনে জমা থাকে');
    expect(doc.link, '/home/sync-status');
  });

  test('each state has its own words and colour', () {
    final cases = <Map<String, dynamic>, (String, WidgetTone)>{
      s(running: true, offline: true): ('সিঙ্ক চলছে', WidgetTone.plain),
      s(offline: true, failed: true, pending: 3): ('নেট নেই', WidgetTone.warn),
      s(failed: true): ('সিঙ্ক হয়নি', WidgetTone.bad),
      s(stale: true): ('সিঙ্ক পুরনো', WidgetTone.warn),
      s(lastOkAt: null): ('সিঙ্ক পুরনো', WidgetTone.warn),
    };
    for (final MapEntry(key: summary, value: (words, tone)) in cases.entries) {
      final doc = kind.build(summary, asOf: asOf);
      expect(doc.hero!.figure.text, words, reason: '$summary');
      expect(doc.hero!.figure.tone, tone, reason: '$summary');
      expectWidgetRules(doc);
    }
  });

  test('offline shows what is waiting', () {
    final doc = kind.build(s(offline: true, pending: 3), asOf: asOf);
    expect(doc.rows.single.title.text, 'জমা কাজ: 3');
    expect(doc.rows.single.title.tone, WidgetTone.warn);
  });

  test('never synced says so', () {
    expect(kind.build(s(lastOkAt: null), asOf: asOf).hero!.caption!.text, 'শেষ সিঙ্ক: এখনো হয়নি');
  });

  test('the summary is the phone\'s own: SyncStatus.widgetSummary, with the queue count', () async {
    var now = DateTime.utc(2026, 10, 20, 9, 58);
    final status = SyncStatus(clock: () => now, pending: () => 5);
    // ⛔ পেছনের isolate-এর মতো নতুন অবস্থা কিছু জানে না — উত্তর নেই, উইজেট যেমন ছিল তেমন
    expect(status.widgetSummary(), isNull);
    expect(SyncStateWidget(status: status).localSummary(), isNull);
    status.watchNetwork(changes: const Stream.empty());
    expect(status.widgetSummary(),
        {'lastOkAt': null, 'running': false, 'failed': false, 'stale': true, 'offline': false, 'pending': 5});
    await status.track(() async {});
    now = DateTime.utc(2026, 10, 20, 10, 0);
    final summary = SyncStateWidget(status: status).localSummary()!;
    expect(summary, {
      'lastOkAt': '2026-10-20T09:58:00.000Z',
      'running': false,
      'failed': false,
      'stale': false,
      'offline': false,
      'pending': 5,
    });
    expect(kind.build(summary, asOf: asOf).hero!.caption!.text, 'শেষ সিঙ্ক: ২ মিনিট আগে');
    await expectLater(status.track(() async => throw StateError('x')), throwsStateError);
    expect(status.widgetSummary()!['failed'], isTrue);
    // ⓘ জমা কাজ গুনতে ভাঙলেও (বাক্স খোলা নেই) উইজেট ভাঙে না
    final broken = SyncStatus(clock: () => now, pending: () => throw StateError('box closed'));
    await broken.track(() async {});
    expect(broken.widgetSummary()!['pending'], 0);
  });

  test('LastSyncLabel words are unchanged by the shared "ago"', () {
    final status = SyncStatus(clock: () => DateTime.utc(2026, 10, 20, 7), pending: () => 0);
    expect(LastSyncLabel.ago(DateTime.utc(2026, 10, 20, 7), DateTime.utc(2026, 10, 20, 10)), '৩ ঘণ্টা আগে');
    expect(LastSyncLabel.ago(DateTime.utc(2026, 10, 18), DateTime.utc(2026, 10, 20)), '২ দিন আগে');
    expect(LastSyncLabel.ago(asOf, asOf), 'এইমাত্র');
    expect(LastSyncLabel.words(status, asOf), 'এখনো সিঙ্ক হয়নি');
  });
}
