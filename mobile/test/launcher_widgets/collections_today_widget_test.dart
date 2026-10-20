import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/collections_today.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';

import '../support/widget_claims.dart';

/// ⭐ ১১ · আজকের আদায় — নকশা ১৯ অক্টোবর ২০২৬, চুক্তি `widgets.collections_today`
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 30);
  const w = CollectionsTodayWidget();
  Map<String, dynamic> full() => {
        'as_of': '…',
        'total': '310000.0000',
        'cash': '145000.0000',
        'bank': '120000.0000',
        'mfs': '45000.0000',
        'link': '/money-in?period=today',
      };

  test('key, sizes and the shared rules', () {
    expect(w.key, 'collections_today');
    expect(w.sizes.first, WidgetSize.s2x2);
    expectWidgetRules(w.build(full(), asOf: asOf));
  });

  test('the hero and the three channels, every figure hideable money', () {
    final doc = w.build(full(), asOf: asOf);
    expect(doc.hero!.figure.text, '৳3,10,000.00');
    expect(doc.hero!.figure.secret, isTrue);
    expect([for (final r in doc.rows) r.title.text], ['নগদ', 'ব্যাংক', 'bKash / MFS']);
    expect([for (final r in doc.rows) r.amount!.text], ['৳1,45,000.00', '৳1,20,000.00', '৳45,000.00']);
    expect(doc.rows.every((r) => r.amount!.secret), isTrue);
    expect(doc.link, '/money-in?period=today');
  });

  test('a channel the payload does not carry has no row — never "৳0"', () {
    final doc = w.build(full()..remove('mfs')..['bank'] = null, asOf: asOf);
    expect([for (final r in doc.rows) r.title.text], ['নগদ']);
    final bare = w.build(const {}, asOf: asOf);
    expect(bare.hero, isNull);
    expect(bare.rows, isEmpty);
  });

  test('a real zero is shown as a figure', () {
    final doc = w.build(full()..['mfs'] = '0.0000', asOf: asOf);
    expect(doc.rows.last.amount!.text, '৳0.00');
  });

  test('an unknown field is ignored and wrong types do not throw', () {
    final doc = w.build(full()..['card'] = '9999.0000', asOf: asOf);
    expect(doc.rows, hasLength(3));
    expect(() => w.build({'total': [], 'cash': {}, 'bank': 'x', 'link': 1}, asOf: asOf), returnsNormally);
  });
}
