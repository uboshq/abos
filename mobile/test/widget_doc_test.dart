import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/records/money.dart';

import 'support/widget_claims.dart';

/// ⭐ উইজেটের নথি — প্রতিটা উইজেট এটা বানায়, একটাই আঁকিয়ে আঁকে (পরিকল্পনা ৪, অংশ ৯)।
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 30);

  WidgetDoc sample({bool hideMoney = false}) => WidgetDoc(
        title: 'বড় বকেয়া',
        asOf: asOf,
        link: '/customers?sort=due',
        hero: WidgetHero(
          hideMoney ? WidgetText(Money.taka(6218400)) : WidgetText.money(Money.taka(6218400)),
          caption: const WidgetText('মোট বকেয়া'),
        ),
        rows: [
          WidgetRow(
            title: const WidgetText('Rasel Enterprise · ফুলবাড়িয়া · SL-Super Group'),
            second: const WidgetText('বাকির ৪৫ দিন পার', tone: WidgetTone.bad),
            amount: WidgetText.money(Money.taka(485000)),
            link: '/customers/31/ledger',
          ),
        ],
        more: 'আরও ১২টা',
      );

  test('a document goes to the renderer as plain JSON, with when it was counted and where each row leads', () {
    final json = jsonDecode(sample().encode()) as Map<String, dynamic>;
    expect(json['v'], 1);
    expect(json['asOf'], '2026-10-20T04:30:00.000Z');
    expect(json['hero']['figure'], {'t': '৳62,18,400.00', 's': true});
    expect(json['rows'][0]['link'], '/customers/31/ledger');
    expect(json['rows'][0]['second']['c'], 'bad');
    expect(json['more'], 'আরও ১২টা');
  });

  test('the shared rules pass a right document', () {
    expectWidgetRules(sample(), customers: {'Rasel Enterprise': ('ফুলবাড়িয়া', 'SL-Super Group')});
  });

  test('the shared rules catch money that a locked phone would show', () {
    expect(() => expectWidgetRules(sample(hideMoney: true)), throwsA(isA<TestFailure>()));
  });

  test('the shared rules catch a customer without point and branch, and any word of pay', () {
    final bare = WidgetDoc(title: 'বড় বকেয়া', asOf: asOf, rows: const [WidgetRow(title: WidgetText('Rasel Enterprise'))]);
    expect(() => expectWidgetRules(bare, customers: {'Rasel Enterprise': ('ফুলবাড়িয়া', 'SL-Super Group')}),
        throwsA(isA<TestFailure>()));
    final pay = WidgetDoc(title: 'এই মাসের বেতন', asOf: asOf);
    expect(() => expectWidgetRules(pay), throwsA(isA<TestFailure>()));
  });

  test('a bar never runs past full', () {
    final bar = const WidgetBar(label: WidgetText('লক্ষ্য'), fraction: 1.4).toJson();
    expect(bar['fraction'], 1.0);
  });
}
