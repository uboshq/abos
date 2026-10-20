import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/big_dues.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';

import '../support/widget_claims.dart';

/// ⭐ ৪ · সবচেয়ে বড় বকেয়া — নকশা ১৯ অক্টোবর ২০২৬, চুক্তি `widgets.big_dues`
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 30);
  const w = BigDuesWidget();
  Map<String, dynamic> customer(String name, String point, String branch) =>
      {'label': '$name · $point · $branch', 'name': name, 'point': point, 'branch': branch};
  Map<String, dynamic> full() => {
        'as_of': '…',
        'total': '6218400.0000',
        'rows': [
          {
            'customer': customer('Rasel Enterprise', 'ফুলবাড়িয়া', 'SL-Super Group'),
            'balance': '485000.0000',
            'overdue_days': 45,
            'link': '/customers/31/ledger',
          },
          {
            'customer': customer('Nava Enterprise', 'ত্রিশাল', 'SL-Lion Group'),
            'balance': '210550.0000',
            'overdue_days': null,
            'link': '/customers/40/ledger',
          },
          {
            'customer': customer('Muskan Enterprise', 'মুক্তাগাছা', 'SL-Super Group'),
            'balance': '-68.4300',
            'overdue_days': 10,
            'link': '/customers/55/ledger',
          },
        ],
        'link': '/dues',
      };

  test('key, sizes and the shared rules, with customers as name · point · branch', () {
    expect(w.key, 'big_dues');
    expect(w.sizes.first, WidgetSize.s4x2);
    expectWidgetRules(w.build(full(), asOf: asOf), customers: {
      'Rasel Enterprise': ('ফুলবাড়িয়া', 'SL-Super Group'),
      'Nava Enterprise': ('ত্রিশাল', 'SL-Lion Group'),
      'Muskan Enterprise': ('মুক্তাগাছা', 'SL-Super Group'),
    });
  });

  test('the total sits in the head\'s corner as hideable money ("মোট ৳…"), and there is no big figure', () {
    final doc = w.build(full(), asOf: asOf);
    expect(doc.corner!.text, 'মোট ৳62,18,400.00');
    expect(doc.corner!.secret, isTrue);
    expect(doc.hero, isNull);
  });

  test('an overdue row: amount in bad tone and "বাকির ৪৫ দিন পার"; not overdue: plain, no line', () {
    final rows = w.build(full(), asOf: asOf).rows;
    expect(rows[0].amount!.text, '৳4,85,000.00');
    expect(rows[0].amount!.tone, WidgetTone.bad);
    expect(rows[0].second!.text, 'বাকির ৪৫ দিন পার');
    expect(rows[0].link, '/customers/31/ledger');
    expect(rows[1].amount!.tone, WidgetTone.plain);
    expect(rows[1].second, isNull);
  });

  test('an advance shows "(Cr) ৳68.43" with no minus sign, and is never red', () {
    final r = w.build(full(), asOf: asOf).rows[2];
    expect(r.amount!.text, '(Cr) ৳68.43');
    expect(r.amount!.text, isNot(contains('-')));
    expect(r.amount!.secret, isTrue);
    expect(r.amount!.tone, WidgetTone.plain);
    expect(r.second!.text, 'অগ্রিম জমা আছে');
  });

  test('a minus zero is zero, not "(Cr)"', () {
    final r = w.build({
      'rows': [
        {'customer': customer('A', 'B', 'C'), 'balance': '-0.0000'}
      ]
    }, asOf: asOf).rows.single;
    expect(r.amount!.text, '৳0.00');
  });

  test('nothing owed reads empty; an absent total or balance draws no ৳', () {
    final doc = w.build({'rows': [], 'link': '/dues'}, asOf: asOf);
    expect(doc.empty, 'কারো কাছে বাকি নেই');
    expect(doc.corner, isNull);
    final noBalance = w.build({
      'rows': [
        {'customer': customer('A', 'B', 'C')}
      ]
    }, asOf: asOf);
    expect(noBalance.rows.single.amount, isNull);
    expect(noBalance.encode(), isNot(contains('৳')));
  });

  test('an unknown field is ignored and wrong types do not throw', () {
    expect(
        () => w.build({
              'total': {'a': 1},
              'rows': [
                {'customer': 5, 'balance': 'abc', 'overdue_days': 'x', 'new_field': 1},
                null,
              ],
              'surprise': [1, 2],
            }, asOf: asOf),
        returnsNormally);
  });
}
