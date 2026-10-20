import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/sales_today.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';

import '../support/widget_claims.dart';

/// ⭐ ১ · আজকের বিক্রি — নকশা ১৯ অক্টোবর ২০২৬, চুক্তি `widgets.sales_today`
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 42);
  const w = SalesTodayWidget();
  Map<String, dynamic> full() => {
        'as_of': '2026-10-20T10:42:00+06:00',
        'total': '482350.0000',
        'invoices': 38,
        'collections': '310000.0000',
        'yesterday': '430000.0000',
        'change_percent': '12.2',
        'link': '/sales/invoices?period=today',
      };

  test('key, sizes and the shared rules', () {
    expect(w.key, 'sales_today');
    expect(w.sizes.first, WidgetSize.s2x2);
    expectWidgetRules(w.build(full(), asOf: asOf));
  });

  test('the hero is the day\'s sales as hideable money, and the line under it says bills and collections', () {
    final doc = w.build(full(), asOf: asOf);
    expect(doc.hero!.figure.text, '৳4,82,350.00');
    expect(doc.hero!.figure.secret, isTrue);
    expect(doc.rows.single.title.text, 'বিল 38টা · আদায়');
    // ⓘ ফেরত বাদ না দেওয়া মোট বিল — "বিক্রি" নয় (fe, ২০ অক্টোবর ২০২৬)
    expect(doc.title, 'আজ বিল হয়েছে');
    expect(doc.rows.single.amount!.text, '৳3,10,000.00');
    expect(doc.rows.single.amount!.secret, isTrue);
    expect(doc.link, '/sales/invoices?period=today');
  });

  test('the corner stays free, so the renderer writes the counting time there itself', () {
    final doc = w.build(full(), asOf: asOf);
    expect(doc.corner, isNull);
    expect(doc.asOf, asOf);
  });

  test('a rise reads ▲ in good tone, a fall ▼ in bad tone', () {
    final up = w.build(full(), asOf: asOf).hero!.caption!;
    expect(up.text, '▲ গতকালের চেয়ে 12% বেশি');
    expect(up.tone, WidgetTone.good);
    final down = w.build(full()..['change_percent'] = '-7.6', asOf: asOf).hero!.caption!;
    expect(down.text, '▼ গতকালের চেয়ে 8% কম');
    expect(down.tone, WidgetTone.bad);
    expect(down.text, isNot(contains('-')));
  });

  test('an absent or null change_percent gives no caption, never "0%"', () {
    expect(w.build(full()..remove('change_percent'), asOf: asOf).hero!.caption, isNull);
    expect(w.build(full()..['change_percent'] = null, asOf: asOf).hero!.caption, isNull);
  });

  test('an absent block draws nothing: no "৳0", no empty row', () {
    final doc = w.build({'invoices': 3}, asOf: asOf);
    expect(doc.hero, isNull);
    expect(doc.rows.single.title.text, 'বিল 3টা');
    expect(doc.rows.single.amount, isNull);
    final bare = w.build(const {}, asOf: asOf);
    expect(bare.rows, isEmpty);
    expect(bare.encode(), isNot(contains('৳')));
  });

  test('an unknown or extra field is ignored, a wrong-typed one does not throw', () {
    final doc = w.build(full()..['salary'] = '99999'..['secret_figure'] = {'x': 1}, asOf: asOf);
    expect(doc.encode(), isNot(contains('99,999')));
    expect(() => w.build({'total': [1], 'invoices': 'many', 'change_percent': 'up', 'link': 5}, asOf: asOf),
        returnsNormally);
  });
}
