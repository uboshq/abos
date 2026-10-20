import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/hub/widget_hub.dart';
import 'package:abos_mobile/core/launcher_widgets/hub/widget_summary_api.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/all_kinds.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/principal_commission.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';
import 'package:abos_mobile/core/privacy/phone_privacy.dart';

import '../support/widget_claims.dart';

/// ⭐ ২২ · প্রিন্সিপালের কমিশন — হোম-স্ক্রিন উইজেট (মালিকের আদেশ "app e dekhate hobe", fe-র মাধ্যমে; a4-এর নকশার নিয়ম ৪;
/// চুক্তি সংশোধন ১, `widgets.principal_commission`; cb, ২০ অক্টোবর ২০২৬)
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 30);
  const w = PrincipalCommissionWidget();

  Map<String, dynamic> row(int id, String name, String branch, String balance) => {
        'supplierId': id,
        'name': name,
        'branch': branch,
        'periodFrom': '2026-09-26',
        'periodTo': '2026-10-25',
        'periodSoFar': '26/09/2026 – আজ পর্যন্ত',
        'basisRate': '2.5%',
        'inflow': '100000.0000',
        'commission': '2500.0000',
        'share': '97500.0000',
        'paid': '0.0000',
        'balance': balance,
      };

  Map<String, dynamic> full({int count = 3, String total = '1150251.9200'}) => {
        'rows': [
          row(1, 'Star Line', 'ময়মনসিংহ', '1156172.1600'),
          row(2, 'ACI লিমিটেড', 'ময়মনসিংহ', '-5920.2500'),
          row(3, 'Fresh', 'নেত্রকোনা', '0.0100'),
          for (var i = 4; i <= count; i++) row(i, 'P$i', 'ময়মনসিংহ', '1.0000'),
        ],
        'total': {'inflow': '300000.0000', 'commission': '7500.0000', 'paid': '0.0000', 'balance': total},
        'link': '/home/principal-commission',
      };

  test('key, medium and large sizes, registered, and the shared rules (money hideable, English digits, asOf)', () {
    expect(w.key, 'principal_commission');
    expect(w.sizes, [WidgetSize.s4x2, WidgetSize.s4x6]);
    expect(allKinds.whereType<PrincipalCommissionWidget>(), hasLength(1));
    expect(widgetKeys, contains('principal_commission'));
    expectWidgetRules(w.build(full(), asOf: asOf));
  });

  test('one row per principal (name · branch), the word from the sign, the total last — the server\'s, never re-added', () {
    final doc = w.build(full(total: '777.7700'), asOf: asOf);
    expect(doc.title, 'প্রিন্সিপালের কমিশন');
    expect(doc.rows.map((r) => r.title.text), ['Star Line · ময়মনসিংহ', 'ACI লিমিটেড · ময়মনসিংহ', 'Fresh · নেত্রকোনা', 'মোট · দেনা']);
    expect(doc.rows[0].amount!.text, '৳11,56,172.16');
    expect(doc.rows[0].second!.text, 'দেনা · দিতে হবে');
    expect(doc.rows[0].amount!.tone, WidgetTone.bad);
    expect(doc.rows[1].amount!.text, '৳5,920.25', reason: '⛔ বিয়োগ চিহ্ন নয়');
    expect(doc.rows[1].second!.text, 'পাওনা · কোম্পানির কাছে পাব');
    // ⓘ সারির যোগফল ১১,৫০,২৫১.৯২ — কিন্তু সার্ভার যা দিল তা-ই
    expect(doc.rows.last.amount!.text, '৳777.77');
    expect(doc.corner!.text, 'মোট ৳777.77');
    for (final r in doc.rows) {
      expect(r.amount!.secret, isTrue);
    }
  });

  test('a tap anywhere opens the app screen /home/principal-commission', () {
    final doc = w.build(full(), asOf: asOf);
    expect(doc.link, '/home/principal-commission');
    expect(doc.rows.map((r) => r.link).toSet(), {'/home/principal-commission'});
    // ⓘ সার্ভার পথ না দিলেও একই পর্দা
    expect(w.build({'rows': [], 'total': {}}, asOf: asOf).link, '/home/principal-commission');
  });

  test('more than seven principals: seven rows, the total, and "আরও Nটা"', () {
    final doc = w.build(full(count: 10), asOf: asOf);
    expect(doc.rows, hasLength(8), reason: 'ভিত্তির সীমা ৮');
    expect(doc.rows.last.title.text, startsWith('মোট'));
    expect(doc.more, 'আরও 3টা');
  });

  test('no principals: the empty line, no total row', () {
    final doc = w.build({'rows': [], 'total': {'balance': '0.0000'}}, asOf: asOf);
    expect(doc.rows, isEmpty);
    expect(doc.empty, 'এই চক্রে কোনো কমিশন নেই');
  });

  test('amounts are hidden in the stored doc when the owner\'s money-hiding switch is on', () {
    final doc = w.build(full(), asOf: asOf);
    final hidden = WidgetHub.encodeFor(doc, showAmounts: false);
    expect(hidden, isNot(contains('৳')));
    expect(hidden, isNot(contains('11,56,172')));
    expect(hidden, contains(WidgetHub.hiddenText));
    expect(hidden, contains('Star Line · ময়মনসিংহ'), reason: 'নাম থাকে, টাকা যায়');
    expect(WidgetHub.encodeFor(doc, showAmounts: true), contains('৳11,56,172.16'));
  });

  group('through the hub', () {
    final saved = <String, String?>{};
    final enabled = <String, bool>{};

    setUp(() {
      WidgetHub.resetForTest();
      saved.clear();
      enabled.clear();
      WidgetHub.kinds = () => const [PrincipalCommissionWidget()];
      WidgetHub.save = (id, value) async => saved[id] = value;
      WidgetHub.setEnabled = (key, on) async => enabled[key] = on;
      WidgetHub.redraw = (key) async {};
      WidgetHub.refreshAll = () async {};
      PhonePrivacy.amountsReader = () async => true;
    });
    tearDown(WidgetHub.resetForTest);

    test('an old server (404 on the summary door) is silent: no doc, the widget switched off, nothing thrown', () async {
      saved['doc_principal_commission'] = 'old';
      WidgetSummaryApi.transport = (etag) async {
        final req = RequestOptions(path: '/widgets/summary');
        throw DioException(requestOptions: req, response: Response(requestOptions: req, statusCode: 404));
      };
      await WidgetHub.refresh();
      expect(saved['doc_principal_commission'], isNull);
      expect(enabled['principal_commission'], isFalse);
    });

    test('a server without the key (old, or no supplier.report) switches it off; with the key it is drawn', () async {
      WidgetSummaryApi.transport = (etag) async =>
          const WidgetSummaryReply(200, body: {'asOf': '2026-10-20T08:30:00Z', 'widgets': <String, Object?>{}});
      await WidgetHub.refresh();
      expect(enabled['principal_commission'], isFalse);

      WidgetHub.resetForTest();
      WidgetHub.kinds = () => const [PrincipalCommissionWidget()];
      WidgetHub.save = (id, value) async => saved[id] = value;
      WidgetHub.setEnabled = (key, on) async => enabled[key] = on;
      WidgetHub.redraw = (key) async {};
      WidgetHub.refreshAll = () async {};
      WidgetSummaryApi.transport = (etag) async => WidgetSummaryReply(200,
          body: {'asOf': '2026-10-20T08:30:00Z', 'widgets': {'principal_commission': full()}});
      await WidgetHub.refresh();
      expect(enabled['principal_commission'], isTrue);
      expect(saved['doc_principal_commission'], contains('Star Line'));
    });

    test('with the owner\'s switch off the stored doc carries no amount', () async {
      PhonePrivacy.amountsReader = () async => false;
      WidgetSummaryApi.transport = (etag) async => WidgetSummaryReply(200,
          body: {'asOf': '2026-10-20T08:30:00Z', 'widgets': {'principal_commission': full()}});
      await WidgetHub.refresh();
      expect(saved['doc_principal_commission'], isNot(contains('৳')));
      expect(saved['widget_amounts_hidden'], '1');
    });
  });
}
