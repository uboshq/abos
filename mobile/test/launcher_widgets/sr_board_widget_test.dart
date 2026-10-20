import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/sr_board.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';

import '../support/widget_claims.dart';

/// ⭐ ১৪ · বিক্রয়কর্মীর তালিকা — নকশা ১৯ অক্টোবর ২০২৬, চুক্তি `widgets.sr_board`
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 30);
  const w = SrBoardWidget();
  List<Map<String, dynamic>> board() => [
        {'rank': 1, 'name': 'Md. Kawser', 'branch': 'SL-Super Group', 'amount': '142500.0000'},
        {'rank': 2, 'name': 'Abu Kawser', 'branch': 'SL-Lion Group', 'amount': '118200.0000'},
      ];
  Map<String, dynamic> owner() => {
        'as_of': '…',
        'branch_label': 'সব',
        'rows': board(),
        'link': '/reports/sales.by_salesman?period=today',
      };

  test('key, sizes and the shared rules', () {
    expect(w.key, 'sr_board');
    expect(w.sizes, [WidgetSize.s4x2]);
    expectWidgetRules(w.build(owner(), asOf: asOf));
  });

  test('owner rows read "1 Md. Kawser · SL-Super Group" with hideable money', () {
    final doc = w.build(owner(), asOf: asOf);
    expect(doc.rows.first.title.text, '1 Md. Kawser · SL-Super Group');
    expect(doc.rows.first.amount!.text, '৳1,42,500.00');
    expect(doc.rows.first.amount!.secret, isTrue);
    expect(doc.corner!.text, 'শাখা: সব');
    expect(doc.title, isNot(contains('শাখা')));
    expect(doc.link, '/reports/sales.by_salesman?period=today');
  });

  test('an SR\'s self-only payload reads only their own place and figure', () {
    final doc = w.build({'as_of': '…', 'self': {'rank': 3, 'amount': '96000.0000', 'of': 12}}, asOf: asOf);
    expectWidgetRules(doc);
    expect(doc.rows, hasLength(1));
    expect(doc.rows.single.title.text, 'আজ আপনি 3 নম্বরে');
    expect(doc.rows.single.amount!.text, '৳96,000.00');
    expect(doc.rows.single.amount!.secret, isTrue);
    expect(doc.corner, isNull);
  });

  test('⛔ with self present, another person\'s figure never shows, even if rows slip in', () {
    final doc = w.build({'self': {'rank': 3, 'amount': '96000.0000', 'of': 12}, 'rows': board()}, asOf: asOf);
    final all = doc.encode();
    expect(doc.rows, hasLength(1));
    expect(all, isNot(contains('Kawser')));
    expect(all, isNot(contains('1,42,500')));
    expect(all, isNot(contains('1,18,200')));
  });

  test('no sales yet gives empty; rows cap at 8', () {
    expect(w.build({'rows': []}, asOf: asOf).empty, isNotNull);
    final many = {
      'rows': [
        for (var i = 1; i <= 11; i++) {'rank': i, 'name': 'SR $i', 'amount': '1.0000'}
      ]
    };
    expect(w.build(many, asOf: asOf).rows, hasLength(8));
  });

  test('a missing optional field does not throw; an unknown one is ignored', () {
    final doc = w.build({
      'self': {'amount': '96000.0000'},
      'pay': '50000.0000',
    }, asOf: asOf);
    expect(doc.rows.single.title.text, 'আজ আপনার বিক্রি');
    expect(doc.encode(), isNot(contains('50,000')));
    expect(() => w.build({'rows': [{'rank': 'x'}, 7], 'self': 'x'}, asOf: asOf), returnsNormally);
  });
}
