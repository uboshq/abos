import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/offers.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';

import '../support/widget_claims.dart';

/// ⭐ ১৮ · চলতি অফার আর স্কিম — নকশা ১৯ অক্টোবর ২০২৬, চুক্তি `widgets.offers`
void main() {
  // ঢাকায় ২০ অক্টোবর সকাল ১০:৩০
  final asOf = DateTime.utc(2026, 10, 20, 4, 30);
  const w = OffersWidget();
  Map<String, dynamic> full() => {
        'as_of': '…',
        'count': 5,
        'rows': [
          {
            'title': '১০ কার্টনে ১ ফ্রি',
            'product': 'Bullet King 500ml',
            'scope': 'SL-Super Group',
            'ends_on': '2026-10-22',
            'link': '/offers/9',
          },
          {
            'title': '৩% নগদ ছাড়',
            'product': 'Star Line আটা',
            'scope': 'SL-Lion Group',
            'ends_on': '2026-10-31',
            'link': '/offers/10',
          },
        ],
        'link': '/offers',
      };

  test('key, sizes and the shared rules', () {
    expect(w.key, 'offers');
    expect(w.sizes.first, WidgetSize.s4x2);
    expectWidgetRules(w.build(full(), asOf: asOf));
  });

  test('a row: title, "product · scope", the link from the payload', () {
    final r = w.build(full(), asOf: asOf).rows.first;
    expect(r.title.text, '১০ কার্টনে ১ ফ্রি');
    expect(r.second!.text, 'Bullet King 500ml · SL-Super Group');
    expect(r.link, '/offers/9');
  });

  test('ending within 2 days reads "শেষ: ২ দিন পর" in warn tone; later reads the date, plain', () {
    final rows = w.build(full(), asOf: asOf).rows;
    expect(rows[0].chip!.text, 'শেষ: ২ দিন পর');
    expect(rows[0].chip!.tone, WidgetTone.warn);
    expect(rows[1].chip!.text, 'শেষ: ৩১ অক্টোবর');
    expect(rows[1].chip!.tone, WidgetTone.plain);
  });

  test('the day is Dhaka\'s: 19 Oct 20:00 UTC is already 20 Oct', () {
    final late = DateTime.utc(2026, 10, 19, 20, 0);
    expect(w.build(full(), asOf: late).rows[0].chip!.text, 'শেষ: ২ দিন পর');
  });

  test('count above rows gives "more"; none active gives empty', () {
    expect(w.build(full(), asOf: asOf).more, 'আরও 3টা');
    final none = w.build({'count': 0, 'rows': [], 'link': '/offers'}, asOf: asOf);
    expect(none.empty, isNotNull);
    expect(none.more, isNull);
  });

  test('a missing ends_on or product does not throw and draws nothing for it; unknown fields ignored', () {
    final doc = w.build({
      'rows': [
        {'title': 'লক্ষ্য পূরণে বোনাস', 'discount_cost': '9999.0000'}
      ],
      'mystery': true,
    }, asOf: asOf);
    expect(doc.rows.single.chip, isNull);
    expect(doc.rows.single.second, isNull);
    expect(doc.encode(), isNot(contains('9999')));
    expect(() => w.build({'rows': [{'title': 1, 'ends_on': 'soon'}], 'count': []}, asOf: asOf), returnsNormally);
  });
}
