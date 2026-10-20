import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/hub/widget_hub.dart';
import 'package:abos_mobile/core/launcher_widgets/hub/widget_summary_api.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/stock_alerts.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/privacy/phone_privacy.dart';
import 'package:abos_mobile/core/router/deep_links.dart';

import 'support/widget_claims.dart';

/// ⭐ মজুদ সতর্কতা — সার্ভারের `stock_alerts` সারাংশ থেকে নথি (মালিকের উইজেট-নকশা ৫, ২০ অক্টোবর ২০২৬)।
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 42);
  const kind = StockAlertsWidget();

  final sample = <String, dynamic>{
    'link': '/inventory/stock?sort=available',
    'count': 4,
    'warehouse': 'প্রধান',
    'branch': 'SL-Super Group',
    'rows': [
      {'product': 'Bullet King 500ml', 'available': '24', 'reorder': '200', 'share': '0.1200', 'unit': 'কার্টন', 'link': '/inventory/stock?q=BK500'},
      {'product': 'Star Line Atta 2kg', 'available': '61', 'reorder': '150', 'share': '0.4066', 'unit': 'বস্তা', 'link': '/inventory/stock?q=SLA2'},
      {'product': 'Jabed Mustard Oil 1L', 'available': '88', 'reorder': '120', 'share': '0.7333', 'unit': 'কার্টন', 'link': '/inventory/stock?q=JMO1'},
    ],
  };

  test('each product is a bar of how much of its reorder level is left, red, amber or blue, under the warehouse', () {
    final doc = kind.build(sample, asOf: asOf);
    expectWidgetRules(doc);

    expect(kind.key, 'stock_alerts', reason: 'সার্ভারের চাবির সাথে হুবহু এক');
    expect(doc.title, 'মজুদ কমে গেছে');
    expect(doc.corner!.text, 'গুদাম: প্রধান · SL-Super Group');
    expect([for (final b in doc.bars) b.label.text], ['Bullet King 500ml', 'Star Line Atta 2kg', 'Jabed Mustard Oil 1L']);
    expect([for (final b in doc.bars) b.value!.text], ['24 / 200 কার্টন', '61 / 150 বস্তা', '88 / 120 কার্টন']);
    expect([for (final b in doc.bars) b.fraction], [closeTo(0.12, 1e-9), closeTo(0.4066, 1e-9), closeTo(0.7333, 1e-9)]);
    expect([for (final b in doc.bars) b.tone], [WidgetTone.bad, WidgetTone.warn, WidgetTone.plain], reason: 'লাল, কমলা, নীল');
    expect(doc.more, 'আরও 1টা', reason: 'চারটা সীমার নিচে, দেখা গেল তিনটা');
    expect(doc.empty, isNull);
    expect(doc.encode(), contains('"asOf":"2026-10-20T04:42:00.000Z"'));
  });

  test('the colour changes exactly at a quarter and at a half of the level', () {
    expect(StockAlertsWidget.toneOf(0), WidgetTone.bad);
    expect(StockAlertsWidget.toneOf(0.25), WidgetTone.bad);
    expect(StockAlertsWidget.toneOf(0.2501), WidgetTone.warn);
    expect(StockAlertsWidget.toneOf(0.5), WidgetTone.warn);
    expect(StockAlertsWidget.toneOf(0.5001), WidgetTone.plain);
    expect(StockAlertsWidget.toneOf(1), WidgetTone.plain);
  });

  test('stock below zero draws an empty red bar, not a crash or a negative bar', () {
    final doc = kind.build({
      'count': 1,
      'rows': [
        {'product': 'Oversold', 'available': '-3', 'reorder': '10', 'share': '-0.3000', 'unit': null},
      ],
    }, asOf: asOf);
    expect(doc.bars.single.fraction, 0.0);
    expect(doc.bars.single.tone, WidgetTone.bad);
    expect(doc.bars.single.value!.text, '-3 / 10');
    expect(doc.corner!.text, 'সব গুদাম', reason: 'একটা গুদাম না হলে');
  });

  test('the whole widget opens the stock page, and that page is one the app takes as a deep link', () {
    final doc = kind.build(sample, asOf: asOf);
    expect(doc.link, '/inventory/stock?sort=available');
    expect(DeepLinks.targetFor(Uri.parse(doc.link!)), isNotNull, reason: '⛔ লিংকটা ডিপ-লিংকের উপসর্গে নেই');
    final old = kind.build({...sample}..remove('link'), asOf: asOf);
    expect(old.link, StockAlertsWidget.stockPage, reason: 'সার্ভার লিংক না পাঠালেও মজুদের পাতা');
  });

  test('nothing below its level says so, with no bars and no more', () {
    final doc = kind.build({'count': 0, 'warehouse': null, 'branch': 'SL-Lion Group', 'rows': []}, asOf: asOf);
    expectWidgetRules(doc);
    expect(doc.empty, 'সব মজুদ পুনঃক্রয়ের সীমার উপরে');
    expect(doc.bars, isEmpty);
    expect(doc.more, isNull);
    expect(doc.corner!.text, 'সব গুদাম · SL-Lion Group');
  });

  test('a salesman\'s answer has no stock_alerts key, so the hub never builds it and keeps the widget switched off', () async {
    // ⓘ সার্ভারের দরজা চাবি (`inventory.stock.view`) ছাড়া চাবিটাই রাখে না ([[StockAlertsSummary]]); কেন্দ্র তখন build ডাকে না
    final saved = <String, String?>{'doc_stock_alerts': 'an owner\'s old figures'};
    final enabled = <String, bool>{};
    WidgetHub.resetForTest();
    addTearDown(WidgetHub.resetForTest);
    WidgetHub.kinds = () => const [StockAlertsWidget()];
    WidgetHub.save = (id, value) async => saved[id] = value;
    WidgetHub.setEnabled = (key, on) async => enabled[key] = on;
    WidgetHub.redraw = (key) async {};
    WidgetHub.refreshAll = () async {};
    PhonePrivacy.amountsReader = () async => true;

    WidgetSummaryApi.transport = (etag) async => const WidgetSummaryReply(200, etag: '"sr"', body: {
          'asOf': '2026-10-20T04:42:00Z',
          'widgets': {
            'sales_today': {'amount': '125000.00'},
            'offers': {'rows': []},
          },
        });
    await WidgetHub.refresh();
    expect(enabled['stock_alerts'], isFalse, reason: '⛔ বিক্রয়কর্মীর ফোনে মজুদের উইজেট (মালিক, ১ অক্টোবর ২০২৬)');
    expect(saved['doc_stock_alerts'], isNull, reason: '⛔ আগের মানুষের মজুদ থেকে গেল');

    // প্রস্তুতি ঠিক কি না — চাবি এলে একই কেন্দ্র উইজেটটা বানায় আর চালু করে
    WidgetSummaryApi.transport = (etag) async => WidgetSummaryReply(200, etag: '"owner"', body: {
          'asOf': '2026-10-20T04:42:00Z',
          'widgets': {'stock_alerts': sample},
        });
    await WidgetHub.refresh();
    expect(enabled['stock_alerts'], isTrue);
    expect(saved['doc_stock_alerts'], contains('Bullet King 500ml'));
  });
}
