import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/features/direct_sale/return_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../return_screen_test.dart' show FakeReturnApi;
import '../support/bar_fonts.dart' show loadBarFonts;

/// ⭐ বিক্রি ফেরতের পর্দা দুই ভাষায় (0.4.43, a5a): বাংলায় আগের লেখা, ইংরেজিতে পর্দার নিজের কোনো বাংলা নেই — কেবল সার্ভারের
/// নাম (গ্রাহক, মাল, কারণ)। ছবি আসল ফন্টে (Roboto + Shonar Bangla), এক PC-তে বানানো।
const _serverWords = {'রহিম স্টোর', 'কসমস বিস্কুট', 'নষ্ট মাল'};
// ⓘ টাকার চিহ্ন ৳ (U+09F3) বাংলা ব্লকে, কিন্তু দুই ভাষাতেই এক — তাই বাদ
final _bengali = RegExp('[ঀ-৲৴-৿]');

ThemeData _theme() {
  final t = ThemeData(useMaterial3: true);
  return t.copyWith(textTheme: t.textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla']));
}

Future<void> _openBill(WidgetTester tester, Locale locale) async {
  tester.view.physicalSize = const Size(412, 892);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  AppLocale.current = locale;
  addTearDown(() => AppLocale.current = AppLocale.locale);
  await tester.pumpWidget(RepaintBoundary(
    key: const ValueKey('shot'),
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: _theme(),
      locale: locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: ReturnScreen(customerId: 'cus-1', api: FakeReturnApi()),
    ),
  ));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('return-bill-inv-1')));
  await tester.pumpAndSettle();
}

List<String> _screenTexts(WidgetTester tester) => [
      for (final w in tester.widgetList<Text>(find.byType(Text))) w.data ?? w.textSpan?.toPlainText() ?? '',
      for (final w in tester.widgetList<InputDecorator>(find.byType(InputDecorator))) w.decoration.labelText ?? '',
    ];

void main() {
  setUpAll(loadBarFonts);

  testWidgets('Bengali: the return screen reads as before', (tester) async {
    await _openBill(tester, AppLocale.locale);
    expect(find.text('বিক্রি ফেরত'), findsOneWidget);
    expect(find.text('কত ফেরত'), findsOneWidget);
    expect(find.textContaining('বেচা: 5'), findsOneWidget);
    expect(find.text('অন্য বিল বাছুন'), findsOneWidget);
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/l10n/a5a_return_bn.png'));
  });

  testWidgets('English: no Bengali of the screen\'s own, only the server\'s names', (tester) async {
    await _openBill(tester, AppLocale.english);
    expect(find.text('Sales return'), findsOneWidget);
    expect(find.text('Quantity returned'), findsOneWidget);
    expect(find.textContaining('Sold: 5'), findsOneWidget);
    expect(find.text('Choose another bill'), findsOneWidget);
    final own = _screenTexts(tester).where((t) => _bengali.hasMatch(t) && !_serverWords.any(t.contains)).toList();
    expect(own, isEmpty, reason: 'ইংরেজিতে পর্দার নিজের বাংলা: $own');
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/l10n/a5a_return_en.png'));
  });
}
