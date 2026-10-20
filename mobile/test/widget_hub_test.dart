import 'dart:async';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/hub/widget_hub.dart';
import 'package:abos_mobile/core/launcher_widgets/hub/widget_summary_api.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/all_kinds.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';
import 'package:abos_mobile/core/privacy/phone_privacy.dart';
import 'package:abos_mobile/core/theme/theme_choice.dart';
import 'package:abos_mobile/main.dart' show widgetDestination;

/// নকল উইজেট — সার্ভারের সারাংশের `n` শিরোনামে বসায়
class _FakeKind extends LauncherWidgetKind {
  const _FakeKind(this.key, {this.local});

  @override
  final String key;
  final Map<String, dynamic>? local;

  @override
  String get title => key;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x2];

  @override
  Map<String, dynamic>? localSummary() => local;

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) =>
      WidgetDoc(title: '$key ${summary['n']}', asOf: asOf);
}

/// ⭐ উইজেটের কেন্দ্র ([WidgetHub]) — সার্ভারের সারাংশ থেকে নথি, চালু-বন্ধ, মোছা আর চাপের ঠিকানা (পরিকল্পনা ৪, অংশ ৯)।
void main() {
  final saved = <String, String?>{};
  final enabled = <String, bool>{};
  final redrawn = <String>[];
  var refreshAlls = 0;
  var replies = <WidgetSummaryReply>[];
  final sentEtags = <String?>[];
  final now = DateTime.utc(2026, 10, 20, 9);

  setUp(() {
    WidgetHub.resetForTest();
    saved.clear();
    enabled.clear();
    redrawn.clear();
    refreshAlls = 0;
    sentEtags.clear();
    replies = [];
    WidgetHub.save = (id, value) async => saved[id] = value;
    WidgetHub.setEnabled = (key, on) async => enabled[key] = on;
    WidgetHub.redraw = (key) async => redrawn.add(key);
    WidgetHub.refreshAll = () async => refreshAlls++;
    WidgetHub.clock = () => now;
    WidgetSummaryApi.transport = (etag) async {
      sentEtags.add(etag);
      return replies.removeAt(0);
    };
    PhonePrivacy.amountsReader = () async => true;
  });

  tearDown(WidgetHub.resetForTest);

  WidgetSummaryReply ok(Map<String, Object?> widgets, {String etag = '"e1"'}) =>
      WidgetSummaryReply(200, etag: etag, body: {'asOf': '2026-10-20T08:30:00Z', 'widgets': widgets});

  /// ⛔ যে উইজেট সার্ভার দেয়নি তা ফোনে চালুই থাকে না; দেওয়াগুলোর নথি লেখা আর চালু; ফোনের নিজের উইজেট সার্ভারের চাবি খোঁজে না
  test('docs only for answered kinds; those enabled, the rest disabled; local kinds need no server key', () async {
    WidgetHub.kinds = () => const [
          _FakeKind('sales_today'),
          _FakeKind('approvals'),
          _FakeKind('sync_state', local: {'n': 'phone'}),
        ];
    // পুরনো নথি — অনুমতি চলে গেলে মুছতে হবে
    saved['doc_approvals'] = 'old';
    replies = [ok({'sales_today': {'n': 5}, 'sync_state': {'n': 'server'}})];

    await WidgetHub.refresh();

    expect(saved['doc_sales_today'], contains('sales_today 5'));
    expect(saved['doc_sales_today'], contains('2026-10-20T08:30:00.000Z'), reason: 'সার্ভারের asOf');
    expect(saved.containsKey('doc_approvals') && saved['doc_approvals'] == null, isTrue, reason: 'মোছা');
    expect(saved['doc_sync_state'], contains('sync_state phone'), reason: 'ফোনের সারাংশ, সার্ভারেরটা নয়');
    expect(saved['doc_sync_state'], contains(now.toIso8601String()), reason: 'ফোনের উইজেটের সময় = এখন');
    expect(enabled, {'sales_today': true, 'approvals': false, 'sync_state': true});
    expect(redrawn, ['sales_today', 'sync_state']);
    expect(refreshAlls, 1);
  });

  /// ⭐ ৩০৪ — আগের উত্তরই; কোনো উইজেট মোছা বা বন্ধ হয় না, আর পরের ডাকে শেষ ETag যায়
  test('a 304 reuses the last answer and never clears a widget', () async {
    WidgetHub.kinds = () => const [_FakeKind('sales_today'), _FakeKind('cheques')];
    replies = [ok({'sales_today': {'n': 7}}), const WidgetSummaryReply(304)];

    await WidgetHub.refresh();
    final first = saved['doc_sales_today'];
    await WidgetHub.refresh();

    expect(sentEtags, [null, '"e1"']);
    expect(saved['doc_sales_today'], first);
    expect(enabled['sales_today'], isTrue);
    expect(enabled['cheques'], isFalse);
    expect(refreshAlls, 2);
  });

  /// ⓘ নেট নেই — যা আছে থাকে (ধূসর হয় asOf দেখে); কিছুই মোছা বা বন্ধ নয়
  test('a failed fetch leaves server widgets as they were', () async {
    WidgetHub.kinds = () => const [_FakeKind('sales_today')];
    WidgetHub.fetch = () async => throw StateError('offline');
    saved['doc_sales_today'] = 'kept';

    await WidgetHub.refresh();

    expect(saved['doc_sales_today'], 'kept');
    expect(enabled, isEmpty);
  });

  /// ⛔ সাইন-আউট/কোম্পানি বদল — ২১টা নথিই মোছা, ২১টাই বন্ধ, আর পরের ডাকে আগের ETag যায় না
  test('clear() removes all 21 docs and disables all', () async {
    WidgetHub.kinds = () => const [_FakeKind('sales_today')];
    replies = [ok({'sales_today': {'n': 1}}), ok({'sales_today': {'n': 2}}, etag: '"e2"')];
    await WidgetHub.refresh();

    await WidgetHub.clear();

    for (final key in widgetKeys) {
      expect(saved.containsKey('doc_$key'), isTrue, reason: key);
      expect(saved['doc_$key'], isNull, reason: key);
      expect(enabled[key], isFalse, reason: key);
    }
    expect(enabled.length, 21);
    await WidgetHub.refresh();
    expect(sentEtags.last, isNull, reason: 'আগের মানুষের ETag নয়');
  });

  /// ⛔ সাইন-আউটের আগে শুরু হওয়া গোল পরে কিছু লেখে না — বেরিয়ে যাওয়া মানুষের নথি ফিরে আসে না
  test('a refresh that was under way when clear() ran writes nothing after it', () async {
    WidgetHub.kinds = () => const [_FakeKind('sales_today')];
    final gate = Completer<WidgetSummary>();
    WidgetHub.fetch = () => gate.future;

    final round = WidgetHub.refresh();
    await WidgetHub.clear();
    gate.complete(WidgetSummary(asOf: now, widgets: const {'sales_today': {'n': 9}}));
    await round;

    expect(saved['doc_sales_today'], isNull);
    expect(enabled['sales_today'], isFalse);
  });

  /// ⭐ উইজেটও মানুষের আলো/অন্ধকার বাছাই মানে — পেছনের isolate-এও ভাণ্ডার থেকে পড়ে লেখে, "ফোনের মতো" ধরে নয়
  test("the widgets carry the person's light or dark choice, read from storage even in the background", () async {
    WidgetHub.kinds = () => const [];
    WidgetHub.fetch = () async => WidgetSummary(asOf: now, widgets: const {});
    ThemeChoices.instance = ThemeChoices(read: () async => 'dark', write: (_) async {});

    await WidgetHub.refresh();

    expect(saved['widget_theme'], 'dark');
  });

  /// ⛔ ফোনের-নিজের উইজেট (সিঙ্কের অবস্থা) পেছনের isolate-এ কিছু না জানলে বন্ধ হয় না — যেমন ছিল তেমন (a6-এর ধরা)
  test('a phone-only widget that knows nothing yet is left as it was, not switched off', () async {
    WidgetHub.kinds = () => const [_LocalOnly()];
    WidgetHub.fetch = () async => WidgetSummary(asOf: now, widgets: const {});
    saved['doc_sync_state'] = '{"v":1}';
    enabled['sync_state'] = true;

    await WidgetHub.refresh();

    expect(saved['doc_sync_state'], '{"v":1}', reason: '⛔ ফোনের-নিজের উইজেটের নথি মুছে গেল');
    expect(enabled['sync_state'], isTrue, reason: '⛔ ফোনের-নিজের উইজেট বন্ধ হয়ে গেল');
  });

  /// ⛔ সাইন-আউট দুই উইজেটের মাঝখানে এলে পরের উইজেট আর লেখা হয় না — আগের মানুষের অঙ্ক নতুন করে ফেরে না
  test('a sign-out between two widgets stops the round: the next widget is not written', () async {
    WidgetHub.kinds = () => const [_FakeKind('sales_today'), _FakeKind('approvals')];
    WidgetHub.fetch = () async => WidgetSummary(asOf: now, widgets: const {'sales_today': {'n': 1}, 'approvals': {'n': 2}});
    Future<void>? clearing;
    WidgetHub.save = (id, value) async {
      saved[id] = value;
      if (id == 'doc_sales_today' && value != null) clearing ??= WidgetHub.clear();
    };

    await WidgetHub.refresh();
    await clearing;

    expect(saved['doc_approvals'], isNull, reason: '⛔ সাইন-আউটের পরে পরের উইজেটের নথি লেখা হলো');
    expect(enabled['approvals'], isFalse);
  });

  /// ⛔ মালিকের সুইচ বন্ধ (বা পড়া যায় না) → "1" = লুকাও; চালু → "0"
  test('amounts switch off → widget_amounts_hidden "1"', () async {
    WidgetHub.kinds = () => const [];
    replies = [ok({}), const WidgetSummaryReply(304), const WidgetSummaryReply(304)];

    PhonePrivacy.amountsReader = () async => false;
    await WidgetHub.refresh();
    expect(saved['widget_amounts_hidden'], '1');

    PhonePrivacy.amountsReader = () async => true;
    await WidgetHub.refresh();
    expect(saved['widget_amounts_hidden'], '0');

    PhonePrivacy.amountsReader = () async => throw StateError('unreadable');
    await WidgetHub.refresh();
    expect(saved['widget_amounts_hidden'], '1');
  });

  test('receiver names follow CamelKey', () {
    expect(WidgetHub.receiverOf('sales_today'), 'com.abos.abos_mobile.widgets.SalesTodayWidgetReceiver');
    expect(WidgetHub.receiverOf('sr_board'), 'com.abos.abos_mobile.widgets.SrBoardWidgetReceiver');
  });

  /// ⭐ উইজেটে চাপ — অ্যাপের পথ যেমন, ওয়েবের পথ ডিপ-লিংকের দরজায়, চাবি → ঘর; পুরনো দুই ঠিকানা আগের মতো;
  /// ⛔ অচেনা বা বাইরের পথ কেবল `/home`
  test('widgetDestination maps path, key and the old addresses; a foreign path goes home', () {
    String? go(String path) => widgetDestination(Uri.parse('abos://widget/open?path=${Uri.encodeComponent(path)}'));

    expect(go('/home/approvals'), '/home/approvals');
    expect(go('/home'), '/home');
    expect(go('/sales/invoices/12'), allOf(startsWith('/home/web/'), isNot('/home')));
    expect(go('https://erp.adi.com.bd/sales/invoices/12'), startsWith('/home/web/'));
    for (final foreign in [
      'https://evil.example/sales/invoices/12',
      '/login',
      '/homeless',
      'javascript:alert(1)',
      '/verify/INV-1',
      '',
    ]) {
      expect(go(foreign), '/home', reason: foreign);
    }
    expect(widgetDestination(Uri.parse('abos://widget/open?key=sales_today')), '/home');
    expect(widgetDestination(Uri.parse('abos://widget/open')), '/home');
    expect(widgetDestination(Uri.parse('abos://widget/approvals')), '/home/approvals');
    expect(widgetDestination(Uri.parse('abos://widget/today')), '/home');
    expect(widgetDestination(Uri.parse('abos://widget/unknown')), isNull);
    expect(widgetDestination(Uri.parse('https://widget/open?path=/home/x')), isNull);
  });

  /// ⛔ ২১টা আলাদা চাবি, আর লেখা প্রতিটা উইজেটের চাবি সেই তালিকায় — নইলে সাইন-আউট সেটা মুছত না
  test('widgetKeys has 21 unique keys and every allKinds key is in widgetKeys', () {
    expect(widgetKeys.length, 21);
    expect(widgetKeys.toSet().length, 21);
    expect(widgetKeys.first, 'sales_today');
    expect(widgetKeys.last, 'owner_board');
    for (final kind in allKinds) {
      expect(widgetKeys, contains(kind.key));
    }
    expect(allKinds.map((k) => k.key).toSet().length, allKinds.length, reason: 'এক চাবি দুবার নয়');
  });
}

class _LocalOnly extends _FakeKind {
  const _LocalOnly() : super('sync_state');

  @override
  bool get answersLocally => true;
}
