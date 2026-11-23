import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ ভাউচারের পক্ষ বাছাই সার্ভারের `find` লেখায়ও মেলে (২৩ নভেম্বর ২০২৬) — অফলাইনে মোবাইলের শেষ ৪ অঙ্ক আর পয়েন্টে।
/// পুরনো সার্ভার `find` না পাঠালে আগের মতো নাম/পাশের লেখায়।
void main() {
  Map<String, dynamic> setupJson({bool withFind = true}) => {
        'type': 'receipt',
        'today': '2026-11-23',
        'parties': [
          {
            'type': 'customer',
            'label': 'গ্রাহক',
            'options': [
              {
                'id': 7,
                'label': 'রহিম স্টোর',
                'hint': 'C-007',
                if (withFind) 'find': 'রহিম স্টোর rahim store c-007 কলমাকান্দা বাজার 01711-223344',
              },
              {'id': 8, 'label': 'করিম স্টোর', 'hint': 'C-008', if (withFind) 'find': 'করিম স্টোর c-008 দুর্গাপুর 01999-000111'},
            ],
          },
        ],
      };

  test('setup reads `find` when the server sends it, and null on an old server', () {
    final parties = VoucherSetup.fromJson(setupJson()).parties;
    expect(parties.first.find, 'রহিম স্টোর rahim store c-007 কলমাকান্দা বাজার 01711-223344');
    expect(VoucherSetup.fromJson(setupJson(withFind: false)).parties.first.find, isNull);
  });

  Future<void> openSheet(WidgetTester tester, List<VoucherParty> parties) async {
    await tester.pumpWidget(MaterialApp(
      theme: AppTheme.light(),
      locale: AppLocale.locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: Scaffold(
        body: VoucherPickSheet<VoucherParty>(title: 'পক্ষ', options: parties, label: (p) => p.shownName, keyPrefix: 'party'),
      ),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('the picker finds a party by the last 4 mobile digits and by its point, both only in `find`', (tester) async {
    await openSheet(tester, VoucherSetup.fromJson(setupJson()).parties);

    await tester.enterText(find.byType(TextField), '3344');
    await tester.pump();
    expect(find.text('রহিম স্টোর'), findsOneWidget);
    expect(find.text('করিম স্টোর'), findsNothing);

    await tester.enterText(find.byType(TextField), 'দুর্গাপুর');
    await tester.pump();
    expect(find.text('করিম স্টোর'), findsOneWidget);
    expect(find.text('রহিম স্টোর'), findsNothing);

    await tester.enterText(find.byType(TextField), 'Rahim');
    await tester.pump();
    expect(find.text('রহিম স্টোর'), findsOneWidget, reason: 'ইংরেজি নাম, বড় হাতে লেখা');
  });

  testWidgets('an old server without `find`: the mobile is not known, the name still matches', (tester) async {
    await openSheet(tester, VoucherSetup.fromJson(setupJson(withFind: false)).parties);

    await tester.enterText(find.byType(TextField), '3344');
    await tester.pump();
    expect(find.text('রহিম স্টোর'), findsNothing);

    await tester.enterText(find.byType(TextField), 'করিম');
    await tester.pump();
    expect(find.text('করিম স্টোর'), findsOneWidget);
  });
}
