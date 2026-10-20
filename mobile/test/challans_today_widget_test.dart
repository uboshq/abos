import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/challans_today.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/router/deep_links.dart';

import 'support/widget_claims.dart';

/// ⭐ আজকের চালান — সার্ভারের `challans_today` সারাংশ থেকে নথি (মালিকের উইজেট-নকশা ৬, ২০ অক্টোবর ২০২৬)।
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 42);
  const kind = ChallansTodayWidget();

  final sample = <String, dynamic>{
    'link': '/sales/deliveries?stage=dispatched',
    'total': 23,
    'gate_pending': 5,
    'moving': 9,
    'delivered': 8,
    'returned': 1,
    'latest': {
      'customer': 'Nava Enterprise · ত্রিশাল · SL-Lion Group',
      'no': 'CHA-0141',
      'vehicle': 'ঢাকা মেট্রো-ট 14-2231',
      'link': '/sales/challans/141',
    },
  };

  test('four boxes by where the goods are, each opening its own list, the total in the corner and the latest challan last', () {
    final doc = kind.build(sample, asOf: asOf);
    expectWidgetRules(doc, customers: {'Nava Enterprise': ('ত্রিশাল', 'SL-Lion Group')});

    expect(kind.key, 'challans_today');
    expect(doc.corner!.text, 'মোট 23');
    expect([for (final r in doc.rows.take(4)) r.title.text], ['গেট পাস বাকি', 'পথে আছে', 'পৌঁছেছে', 'ফেরত এসেছে']);
    expect([for (final r in doc.rows.take(4)) r.amount!.text], ['5', '9', '8', '1']);
    expect([for (final r in doc.rows.take(4)) r.link], [
      '/sales/deliveries?stage=allocated',
      '/sales/deliveries?stage=dispatched',
      '/sales/deliveries?stage=delivered',
      '/sales/deliveries?stage=failed',
    ]);

    final last = doc.rows.last;
    expect(last.title.text, 'শেষ: Nava Enterprise · ত্রিশাল · SL-Lion Group');
    expect(last.second!.text, 'CHA-0141 · গাড়ি ঢাকা মেট্রো-ট 14-2231');
    expect(last.link, '/sales/challans/141');
    expect(doc.empty, isNull);
  });

  test('returned is red only when something came back', () {
    final some = kind.build(sample, asOf: asOf);
    expect(some.rows[3].amount!.tone, WidgetTone.bad);
    expect([for (final r in some.rows.take(3)) r.amount!.tone], everyElement(WidgetTone.plain));

    final none = kind.build({...sample, 'returned': 0, 'total': 22}, asOf: asOf);
    expect(none.rows[3].amount!.tone, WidgetTone.plain, reason: '0 ফেরত লাল নয়');
  });

  test('every link in the document is a page the app takes as a deep link', () {
    final doc = kind.build(sample, asOf: asOf);
    for (final link in [doc.link!, for (final r in doc.rows) r.link!]) {
      expect(DeepLinks.targetFor(Uri.parse(link)), isNotNull, reason: '⛔ $link ডিপ-লিংকের উপসর্গে নেই');
    }
  });

  test('a challan without a vehicle shows only its number; no challan today says so with no boxes', () {
    final noCar = kind.build({...sample, 'latest': {...sample['latest'] as Map, 'vehicle': null}}, asOf: asOf);
    expect(noCar.rows.last.second!.text, 'CHA-0141');

    final empty = kind.build({'total': 0, 'gate_pending': 0, 'moving': 0, 'delivered': 0, 'returned': 0, 'latest': null}, asOf: asOf);
    expectWidgetRules(empty);
    expect(empty.empty, 'আজ এখনো কোনো চালান নেই');
    expect(empty.rows, isEmpty);
    expect(empty.corner!.text, 'মোট 0');
    expect(empty.link, '/sales/deliveries?stage=dispatched');
  });
}
