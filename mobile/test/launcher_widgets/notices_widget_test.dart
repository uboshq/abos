import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/notices.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/router/deep_links.dart';

import '../support/widget_claims.dart';

/// ⭐ ৭ · নোটিশ — [NoticesWidget] (a6, ২০ অক্টোবর ২০২৬)
void main() {
  const kind = NoticesWidget();
  final asOf = DateTime.utc(2026, 10, 20, 10, 0);
  const rasel = {'Rasel Enterprise': ('ফুলবাড়িয়া', 'SL-Super Group')};

  Map<String, dynamic> row(int i, {bool unread = false, String? second, String? title}) => {
        'title': title ?? 'নোটিশ $i',
        'second': second,
        'at': asOf.subtract(const Duration(minutes: 40)).toIso8601String(),
        'unread': unread,
        'link': '/notifications/$i',
      };

  final sample = <String, dynamic>{
    'unread': 4,
    'rows': [
      {
        'title': 'চেক ফেরত — Rasel Enterprise · ফুলবাড়িয়া · SL-Super Group',
        'second': 'CHQ-0077',
        'at': '2026-10-20T09:20:00Z',
        'unread': true,
        'link': '/notifications/77',
      },
      {
        'title': 'নতুন অফার',
        'second': null,
        'at': '2026-10-20T07:00:00Z',
        'unread': false,
        'link': '/promotions/12',
      },
    ],
    'more': 0,
  };

  test('the shared rules pass, and the customer is name · point · branch', () {
    expectWidgetRules(kind.build(sample, asOf: asOf), customers: rasel);
  });

  test('a row reads paper number · time ago against asOf, and opens its own paper', () {
    final doc = kind.build(sample, asOf: asOf);
    expect(doc.rows[0].second!.text, 'CHQ-0077 · ৪০ মিনিট আগে');
    expect(doc.rows[1].second!.text, '৩ ঘণ্টা আগে');
    expect([for (final r in doc.rows) r.link], ['/notifications/77', '/promotions/12']);
    for (final r in doc.rows) {
      expect(DeepLinks.targetFor(Uri.parse(r.link!)), isNotNull, reason: r.link);
    }
    expect(doc.hero!.figure.text, '4');
    expect(doc.hero!.caption!.text, 'না-পড়া');
  });

  test('unread rows stand out, read rows are muted', () {
    final doc = kind.build(sample, asOf: asOf);
    expect(doc.rows[0].title.tone, WidgetTone.plain);
    expect(doc.rows[0].chip?.text, 'নতুন');
    expect(doc.rows[1].title.tone, WidgetTone.muted);
    expect(doc.rows[1].chip, isNull);
  });

  test('a notice with an amount is hidden on a locked phone; one without is not', () {
    final doc = kind.build({
      'unread': 1,
      'rows': [
        row(1, title: 'চেক ফেরত ৳ 85,000', second: 'জমা ৳ 12,000', unread: true),
        row(2, title: 'নতুন অফার', second: 'CHQ-1'),
      ],
    }, asOf: asOf);
    expectWidgetRules(doc);
    expect(doc.rows[0].title.secret, isTrue);
    expect(doc.rows[0].second!.secret, isTrue);
    expect(doc.rows[0].second!.text, 'জমা ৳ 12,000');
    expect(doc.rows[0].amount!.text, '৪০ মিনিট আগে');
    expect(doc.rows[0].amount!.secret, isFalse);
    expect(doc.rows[1].title.secret, isFalse);
    expect(doc.rows[1].second!.secret, isFalse);
  });

  test('nothing to show says so', () {
    final doc = kind.build({'unread': 0, 'rows': [], 'more': 0}, asOf: asOf);
    expect(doc.empty, 'কোনো নোটিশ নেই');
    expect(doc.rows, isEmpty);
    expect(doc.hero, isNull);
    expect(doc.more, isNull);
    expectWidgetRules(doc);
  });

  test('never more than 8 rows; the rest are counted in "more"', () {
    final doc = kind.build({
      'unread': 11,
      'rows': [for (var i = 0; i < 11; i++) row(i, unread: true)],
      'more': 5,
    }, asOf: asOf);
    expect(doc.rows, hasLength(8));
    expect(doc.more, 'আরও 8টা');
    expect(doc.empty, isNull);
    expect(jsonDecode(doc.encode())['rows'], hasLength(8));
  });

  test('more from the server alone shows as "আরও Nটা"', () {
    final doc = kind.build({'unread': 1, 'rows': [row(1)], 'more': 4}, asOf: asOf);
    expect(doc.more, 'আরও 4টা');
  });
}
