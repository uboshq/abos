import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/approvals.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';

import '../support/widget_claims.dart';

/// ⭐ ৩ · আপনার সইয়ের অপেক্ষায় — নকশা ১৯ অক্টোবর ২০২৬, চুক্তি `widgets.approvals`
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 30);
  const w = ApprovalsWidget();
  Map<String, dynamic> row(String name, String point, String no, String amount, int id) => {
        'party': {
          'label': '$name · $point · SL-Super Group',
          'name': name,
          'point': point,
          'branch': 'SL-Super Group',
          'kind': 'customer',
        },
        'kind_label': 'বিক্রি ফেরত',
        'number': no,
        'amount': amount,
        'link': '/home/approvals/$id',
      };
  Map<String, dynamic> full() => {
        'as_of': '…',
        'count': 7,
        'rows': [
          row('Muskan Enterprise', 'মুক্তাগাছা', 'SR-0212', '12400.0000', 123),
          row('Bismillah Enterprise', 'শাহগঞ্জ', 'DS-0088', '3250.0000', 124),
        ],
        'link': '/home/approvals',
      };

  test('key, sizes and the shared rules, with customers as name · point · branch', () {
    expect(w.key, 'approvals');
    expect(w.sizes.first, WidgetSize.s4x2);
    expectWidgetRules(w.build(full(), asOf: asOf), customers: {
      'Muskan Enterprise': ('মুক্তাগাছা', 'SL-Super Group'),
      'Bismillah Enterprise': ('শাহগঞ্জ', 'SL-Super Group'),
    });
  });

  test('a row is the party, the paper, hideable money, and the link the server gave', () {
    final r = w.build(full(), asOf: asOf).rows.first;
    expect(r.title.text, 'Muskan Enterprise · মুক্তাগাছা · SL-Super Group');
    expect(r.second!.text, 'বিক্রি ফেরত SR-0212');
    expect(r.amount!.text, '৳12,400.00');
    expect(r.amount!.secret, isTrue);
    expect(r.link, '/home/approvals/123');
  });

  test('count above the rows gives "more"; count equal gives none', () {
    final doc = w.build(full(), asOf: asOf);
    expect(doc.more, 'আরও 5টা');
    expect(doc.hero!.figure.text, '7');
    expect(w.build(full()..['count'] = 2, asOf: asOf).more, isNull);
  });

  test('an empty list reads "কোনো সই বাকি নেই"', () {
    final doc = w.build({'count': 0, 'rows': [], 'link': '/home/approvals'}, asOf: asOf);
    expect(doc.empty, 'কোনো সই বাকি নেই');
    expect(doc.rows, isEmpty);
    expect(doc.more, isNull);
  });

  test('never more than 8 rows, and an outside link is dropped', () {
    final many = full()
      ..['count'] = 12
      ..['rows'] = [for (var i = 0; i < 12; i++) row('P$i', 'X', 'N$i', '1.0000', i)];
    final doc = w.build(many, asOf: asOf);
    expect(doc.rows, hasLength(8));
    expect(doc.more, 'আরও 4টা');
    final evil = full()..['rows'] = [row('A', 'B', 'C', '1', 1)..['link'] = 'https://evil.example/x'];
    expect(w.build(evil, asOf: asOf).rows.single.link, isNull);
  });

  test('an unknown field is ignored; a missing amount or party does not throw and draws no ৳', () {
    final doc = w.build({
      'count': 1,
      'rows': [
        {'kind_label': 'ছাড়', 'number': 'DS-0088', 'extra': true},
        'not a row',
      ],
      'whatever': 1,
    }, asOf: asOf);
    expect(doc.rows.single.title.text, 'ছাড় DS-0088');
    expect(doc.rows.single.amount, isNull);
    expect(doc.encode(), isNot(contains('৳')));
    expect(() => w.build({'rows': 'x', 'count': 'y'}, asOf: asOf), returnsNormally);
  });
}
