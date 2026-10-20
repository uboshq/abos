import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/goods_incoming.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/router/deep_links.dart';

import 'support/widget_claims.dart';

/// ⭐ মাল আসছে — সার্ভারের `goods_incoming` সারাংশ থেকে নথি (মালিকের উইজেট-নকশা ১৫, ২০ অক্টোবর ২০২৬)।
void main() {
  // ⓘ ঢাকায় ২১ অক্টোবর, সকাল ১০টা (UTC ০৪:০০)
  final asOf = DateTime.utc(2026, 10, 21, 4);
  const kind = GoodsIncomingWidget();

  final sample = <String, dynamic>{
    'link': '/purchase/orders',
    'count': 4,
    'late': 1,
    'rows': [
      {
        'supplier': 'Jabed Agro', 'supplier_branch': 'SL-Super Group', 'no': 'PO-0209', 'expected': '2026-10-16', 'late': true,
        'qty': '50', 'unit': 'কার্টন', 'product': 'Mustard Oil', 'products': 1, 'amount': '106577.00', 'link': '/purchase/orders/209',
      },
      {
        'supplier': 'Star Line', 'supplier_branch': 'SL-Lion Group', 'no': 'PO-0215', 'expected': '2026-10-22', 'late': false,
        'qty': '400', 'unit': 'বস্তা', 'product': 'আটা', 'products': 1, 'amount': '680000.00', 'link': '/purchase/orders/215',
      },
      {
        'supplier': 'Bullet King', 'supplier_branch': 'SL-Super Group', 'no': 'PO-0217', 'expected': '2026-10-23', 'late': false,
        'qty': '200', 'unit': 'কার্টন', 'product': null, 'products': 2, 'amount': '390000.00', 'link': '/purchase/orders/217',
      },
    ],
  };

  test('this week\'s orders with supplier and branch, number, when, how much and the amount, each opening its order', () {
    final doc = kind.build(sample, asOf: asOf);
    expectWidgetRules(doc);

    expect(kind.key, 'goods_incoming');
    expect(doc.title, 'মাল আসছে — ক্রয় অর্ডার');
    expect(doc.corner!.text, 'এই সপ্তাহে 4 · দেরি 1');
    expect([for (final r in doc.rows) r.title.text], [
      'দেরি: Jabed Agro (সরবরাহকারী) · SL-Super Group',
      'Star Line (সরবরাহকারী) · SL-Lion Group',
      'Bullet King (সরবরাহকারী) · SL-Super Group',
    ]);
    expect([for (final r in doc.rows) r.second!.text], [
      'PO-0209 · কথা ছিল 16 অক্টোবর',
      'PO-0215 · আসবে কাল · 400 বস্তা আটা',
      'PO-0217 · আসবে 23 অক্টোবর · 200 কার্টন',
    ]);
    expect([for (final r in doc.rows) r.amount!.text], ['৳1,06,577.00', '৳6,80,000.00', '৳3,90,000.00']);
    expect([for (final r in doc.rows) r.link], ['/purchase/orders/209', '/purchase/orders/215', '/purchase/orders/217']);
    expect(doc.more, 'আরও 1টা');
  });

  test('a late order is red all along its row and labelled দেরি; an on-time order is not', () {
    final doc = kind.build(sample, asOf: asOf);
    final late = doc.rows.first;
    expect(late.title.text, startsWith('দেরি: '));
    expect([late.title.tone, late.second!.tone, late.amount!.tone], everyElement(WidgetTone.bad));
    for (final r in doc.rows.skip(1)) {
      expect(r.title.text, isNot(contains('দেরি')));
      expect(r.title.tone, WidgetTone.plain);
      expect(r.amount!.tone, WidgetTone.plain);
    }
    expect(doc.corner!.tone, WidgetTone.bad);
  });

  test('today and tomorrow are counted on Dhaka\'s day, not UTC\'s', () {
    // ⓘ ২০ অক্টোবর ২৩:০০ UTC = ঢাকায় ২১ অক্টোবর ভোর ৫টা
    final doc = kind.build({
      'count': 1,
      'rows': [
        {'supplier': 'X', 'no': 'PO-1', 'expected': '2026-10-21', 'late': false, 'qty': '1', 'link': '/purchase/orders/1'},
      ],
    }, asOf: DateTime.utc(2026, 10, 20, 23));
    expect(doc.rows.single.second!.text, 'PO-1 · আসবে আজ · 1');
  });

  test('without the purchase price key the server sends no amount, and the row shows none', () {
    final rows = [for (final r in sample['rows'] as List) Map<String, dynamic>.from(r as Map)..remove('amount')];
    final doc = kind.build({...sample, 'rows': rows}, asOf: asOf);
    expectWidgetRules(doc);
    expect([for (final r in doc.rows) r.amount], everyElement(isNull));
    expect(doc.texts.where((t) => t.text.contains('৳')), isEmpty);
  });

  test('every link is a page the app takes as a deep link', () {
    final doc = kind.build(sample, asOf: asOf);
    for (final link in [doc.link!, for (final r in doc.rows) r.link!]) {
      expect(DeepLinks.targetFor(Uri.parse(link)), isNotNull, reason: '⛔ $link ডিপ-লিংকের উপসর্গে নেই');
    }
  });

  test('nothing due this week says so', () {
    final doc = kind.build({'count': 0, 'late': 0, 'rows': []}, asOf: asOf);
    expectWidgetRules(doc);
    expect(doc.empty, 'এই সপ্তাহে কোনো মাল আসার কথা নেই');
    expect(doc.rows, isEmpty);
    expect(doc.more, isNull);
    expect(doc.corner!.text, 'এই সপ্তাহে 0');
    expect(doc.corner!.tone, WidgetTone.plain);
  });
}
