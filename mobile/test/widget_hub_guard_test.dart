import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/hub/widget_hub.dart';
import 'package:abos_mobile/core/launcher_widgets/hub/widget_summary_api.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/all_kinds.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';
import 'package:abos_mobile/core/privacy/phone_privacy.dart';

/// নকল টাকার উইজেট — বড় অঙ্ক, সারির অঙ্ক, আর গোপন দাগ
class _MoneyKind extends LauncherWidgetKind {
  const _MoneyKind();

  @override
  String get key => 'money_in_hand';

  @override
  String get title => 'টাকা হাতে';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x2];

  @override
  WidgetDoc build(Map<String, dynamic> s, {required DateTime asOf}) => WidgetDoc(
        title: 'টাকা হাতে',
        asOf: asOf,
        hero: const WidgetHero(WidgetText.money('৳40,77,445.00'), caption: WidgetText('নগদ + ব্যাংক + MFS')),
        rows: const [WidgetRow(title: WidgetText('নগদ বাক্স'), amount: WidgetText.money('৳1,24,800.00'))],
        bars: const [WidgetBar(label: WidgetText('ভাড়া'), fraction: 0.4, value: WidgetText.money('৳1,30,000.00'))],
      );
}

/// ⭐ উইজেটের নিরাপত্তা-পর্যালোচনার দুই ফাঁক বন্ধ (cb, ২০ অক্টোবর ২০২৬; E:\ABOS\উইজেটের-নিরাপত্তা-পর্যালোচনা-২০-অক্টোবর.md):
///  - ১: মালিকের সুইচ বন্ধ থাকলে ফাইলে লেখা নথিতে কোনো টাকার অঙ্ক নেই — "••••", গোপন দাগ শূন্য; চালু হলে আসল অঙ্ক;
///  - ৬খ: দূর থেকে বের করে দেওয়ার পরে পেছনের গোলে ৪০১ আর টোকেন মোছা → ২১টা নথিই মোছা, ২১টাই বন্ধ; নেট না থাকায় নবায়ন
///    না হওয়া ৪০১ (টোকেন আছে) কিছুই মোছে না।
void main() {
  final saved = <String, String?>{};
  final enabled = <String, bool>{};
  final now = DateTime.utc(2026, 10, 20, 9);

  setUp(() {
    WidgetHub.resetForTest();
    saved.clear();
    enabled.clear();
    WidgetHub.save = (id, value) async => saved[id] = value;
    WidgetHub.setEnabled = (key, on) async => enabled[key] = on;
    WidgetHub.redraw = (key) async {};
    WidgetHub.refreshAll = () async {};
    WidgetHub.clock = () => now;
    WidgetHub.kinds = () => const [_MoneyKind()];
    WidgetHub.fetch = () async => WidgetSummary(asOf: now, widgets: const {'money_in_hand': {}});
  });

  tearDown(WidgetHub.resetForTest);

  test('the owner switch off: the stored doc carries no figure at all, and the bar no length', () async {
    PhonePrivacy.amountsReader = () async => false;
    await WidgetHub.refresh();

    final doc = saved['doc_money_in_hand']!;
    expect(doc, isNot(contains('৳')), reason: '⛔ সুইচ বন্ধ, তবু ফাইলে টাকার অঙ্ক');
    expect(RegExp(r'\d{1,3}(,\d{2,3})+\.\d\d').hasMatch(doc), isFalse, reason: '⛔ ফাইলে টাকার অঙ্কের ঘর');
    final json = jsonDecode(doc) as Map<String, dynamic>;
    expect(json['hero']['figure']['t'], WidgetHub.hiddenText);
    expect(json['hero']['caption']['t'], 'নগদ + ব্যাংক + MFS', reason: 'গোপন নয় এমন লেখা থাকে');
    expect(json['rows'][0]['amount']['t'], WidgetHub.hiddenText);
    expect(json['bars'][0]['fraction'], 0.0, reason: '⛔ দাগের দৈর্ঘ্যে অনুপাত ফাঁস');
    expect(json['bars'][0]['label']['t'], 'ভাড়া');
    expect(saved['widget_amounts_hidden'], '1');
  });

  test('the owner switch on: the next round writes the real figures', () async {
    PhonePrivacy.amountsReader = () async => true;
    await WidgetHub.refresh();

    final json = jsonDecode(saved['doc_money_in_hand']!) as Map<String, dynamic>;
    expect(json['hero']['figure']['t'], '৳40,77,445.00');
    expect(json['bars'][0]['fraction'], 0.4);
  });

  DioException unauthorised() => DioException(
        requestOptions: RequestOptions(path: '/widgets/summary'),
        response: Response(requestOptions: RequestOptions(path: '/widgets/summary'), statusCode: 401),
      );

  test('a 401 after the session was ended remotely wipes all 22 docs and disables every widget', () async {
    PhonePrivacy.amountsReader = () async => true;
    await WidgetHub.refresh();
    expect(saved['doc_money_in_hand'], isNotNull, reason: 'প্রস্তুতিটাই ভুল');

    WidgetHub.fetch = () async => throw unauthorised();
    WidgetHub.sessionGone = () async => true;
    await WidgetHub.refresh();

    for (final key in widgetKeys) {
      expect(saved['doc_$key'], isNull, reason: '⛔ বের করে দেওয়ার পরেও $key-এর নথি রইল');
      expect(enabled[key], isFalse, reason: key);
    }
  });

  test('a 401 whose refresh failed only for want of a network keeps the docs (the session is not over)', () async {
    PhonePrivacy.amountsReader = () async => true;
    await WidgetHub.refresh();
    final before = saved['doc_money_in_hand'];

    WidgetHub.fetch = () async => throw unauthorised();
    WidgetHub.sessionGone = () async => false;
    await WidgetHub.refresh();

    expect(saved['doc_money_in_hand'], before, reason: '⛔ নেট না থাকায় নথি মুছে গেল');
    expect(enabled['money_in_hand'], isTrue);
  });
}
