import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/target.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';

import '../support/widget_claims.dart';

/// ⭐ ৯ · লক্ষ্য বনাম অর্জন — [TargetWidget] (a6, ২০ অক্টোবর ২০২৬)
void main() {
  const kind = TargetWidget();
  final asOf = DateTime.utc(2026, 10, 20, 10, 0);

  Map<String, dynamic> s({String? target = '20000000.00', String achieved = '13600000.00', bool own = false}) => {
        'month': '2026-10',
        'target': target,
        'achieved': achieved,
        'days_left': 12,
        'own': own,
        'link': '/sales/targets',
      };

  test('October\'s target: 68%, the amounts hidden on a locked phone, days left', () {
    final doc = kind.build(s(), asOf: asOf);
    expectWidgetRules(doc);
    expect(doc.title, 'অক্টোবরের লক্ষ্য');
    expect(doc.hero!.figure.text, '68%');
    expect(doc.hero!.figure.secret, isFalse);
    expect(doc.hero!.caption!.text, 'বাকি 12 দিন');
    expect(doc.bars.single.label.text, '৳ 1.36 কোটি / ৳ 2 কোটি');
    expect(doc.bars.single.label.secret, isTrue);
    expect(doc.bars.single.fraction, closeTo(0.68, 1e-9));
    expect(doc.hero!.figure.tone, WidgetTone.plain);
    expect(doc.link, '/sales/targets');
  });

  test('own target says so', () {
    expect(kind.build(s(own: true), asOf: asOf).title, 'অক্টোবরে আমার লক্ষ্য');
  });

  test('reaching the target is good', () {
    final doc = kind.build(s(achieved: '21000000'), asOf: asOf);
    expect(doc.hero!.figure.text, '105%');
    expect(doc.hero!.figure.tone, WidgetTone.good);
    expect(jsonDecode(doc.encode())['bars'][0]['fraction'], 1.0);
  });

  test('no target, or a zero target, is empty — never a division by zero', () {
    for (final t in [null, '0', '0.00']) {
      final doc = kind.build(s(target: t), asOf: asOf);
      expect(doc.empty, 'এই মাসের লক্ষ্য বসানো নেই', reason: '$t');
      expect(doc.hero, isNull);
      expect(doc.bars, isEmpty);
      expect(doc.encode(), isNot(contains('Infinity')));
      expectWidgetRules(doc);
    }
  });

  test('short money in English digits, lakh and crore', () {
    expect(TargetWidget.short(13600000), '৳ 1.36 কোটি');
    expect(TargetWidget.short(20000000), '৳ 2 কোটি');
    expect(TargetWidget.short(250000), '৳ 2.5 লাখ');
    expect(TargetWidget.short(8500), '৳8,500.00');
  });
}
