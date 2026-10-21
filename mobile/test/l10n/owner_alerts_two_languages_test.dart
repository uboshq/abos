import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/features/executive/owner_alerts_screen.dart';
import 'package:abos_mobile/features/executive/owner_pages_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/bar_fonts.dart' show loadBarFonts;

/// ⭐ মালিকের কেন্দ্রের সতর্কতার পাতা, দুই ভাষায় (0.4.43, a6 l10n-b) — পর্দার সব লেখা ARB থেকে।
///
/// <p>দাবি: বাংলায় আগের মতোই (অঙ্ক ইংরেজিতে); ইংরেজিতে পর্দার নিজের কোনো লেখায় বাংলা অক্ষর নেই — বাংলা থাকে কেবল সার্ভারের
/// নামে (কোম্পানি, কাগজ)। ছবি দুইটা আসল ফন্টে, চোখে দেখে রাখা।
const _alpha = '0199c1d2-0000-7000-8000-000000000001';
const _beta = '0199c1d2-0000-7000-8000-000000000002';

class _Fake implements OwnerPagesApi {
  @override
  Future<OwnerAlerts> alerts() async => OwnerAlerts.fromJson({
        'companies': [
          {'id': _alpha, 'name': 'টিডি ডিপো', 'current': true},
          {'id': _beta, 'name': 'ফ্রেশ মার্ট', 'current': false},
        ],
        'alerts': [
          {
            'kind': 'overdue',
            'label': 'Overdue',
            'total': 1234,
            'companies': [
              {'id': _alpha, 'name': 'টিডি ডিপো', 'count': 1234},
              {'id': _beta, 'name': 'ফ্রেশ মার্ট', 'count': null},
            ],
          },
          {
            'kind': 'late_delivery',
            'label': 'Late',
            'total': 0,
            'companies': [
              {'id': _alpha, 'name': 'টিডি ডিপো', 'count': 0},
              {'id': _beta, 'name': 'ফ্রেশ মার্ট', 'count': 0},
            ],
          },
        ],
        'waiting': [
          {'company': 'টিডি ডিপো', 'label': 'বিল INV-1', 'amount': '250000.0000', 'requested_at': '2026-10-18 10:00:00'},
        ],
      });

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

ThemeData _theme() {
  final t = ThemeData(useMaterial3: true);
  return t.copyWith(textTheme: t.textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla']));
}

Future<void> _pump(WidgetTester tester, Locale locale) async {
  AppLocale.current = locale;
  tester.view.physicalSize = const Size(412, 892);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(RepaintBoundary(
    key: const ValueKey('shot'),
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: _theme(),
      locale: locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: OwnerAlertsScreen(api: _Fake()),
    ),
  ));
  await tester.pumpAndSettle();
}

/// বাংলা অক্ষর — ⓘ ৳ (টাকার চিহ্ন, Money-র) বাদ: দুই ভাষাতেই একই
final _bengali = RegExp('[ঀ-৲৴-৿]');

void main() {
  setUpAll(loadBarFonts);
  tearDown(() => AppLocale.current = AppLocale.locale);

  testWidgets('in Bengali the page reads as before, with English digits', (tester) async {
    await _pump(tester, AppLocale.locale);
    expect(find.text('সতর্কতা'), findsOneWidget);
    expect(find.text('মেয়াদ পেরোনো বাকি'), findsOneWidget);
    expect(find.text('দেরির চালান (7 দিন)'), findsOneWidget);
    expect(find.text('সইয়ের অপেক্ষায় (1টা)'), findsOneWidget);
    expect(find.text('গ্রুপের মোট'), findsNWidgets(2));
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/owner_alerts_bn.png'));
  });

  testWidgets('in English every word of the page itself is English', (tester) async {
    await _pump(tester, AppLocale.english);
    expect(find.text('Alerts'), findsOneWidget);
    expect(find.text('Overdue dues'), findsOneWidget);
    expect(find.text('Late delivery challans (7 days)'), findsOneWidget);
    expect(find.text('Awaiting signature (1)'), findsOneWidget);
    expect(find.text('Group total'), findsNWidgets(2));
    expect(find.text('Not allowed'), findsOneWidget);
    // ⓘ বাংলা কেবল সার্ভারের নামে — কোম্পানি আর কাগজের নাম
    const server = {'টিডি ডিপো', 'ফ্রেশ মার্ট', 'বিল INV-1', 'টিডি ডিপো · 18/10/2026'};
    final texts = tester.widgetList<Text>(find.byType(Text)).map((t) => t.data ?? '').where(_bengali.hasMatch).toSet();
    expect(texts.difference(server), isEmpty);
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/owner_alerts_en.png'));
  });
}
