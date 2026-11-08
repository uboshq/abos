import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/dashboard/dashboard_api.dart';
import 'package:abos_mobile/features/dashboards/dashboards_screen.dart';

/// ⭐ ড্যা-৭ — ফোনে ওয়েবের নতুন ড্যাশবোর্ডের সাজ (মালিক, ৮ নভেম্বর ২০২৬, নমুনা "ok"): রঙিন মাথা ওয়েবের রঙে, চার রঙিন কার্ড,
/// "নজরে রাখুন" (খালি হলে সবুজ "সব ঠিক আছে"), দ্রুত কাজের বোতাম, "আরও অঙ্ক"। ⓘ পুরনো সার্ভার রং না পাঠালে আগের সাজ, হুবহু।
/// লম্বা ফোন — পুরো পাতা একবারে আঁকা হয় (তালিকা অলস, পর্দার বাইরেরটা আঁকে না)
void tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 6000);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
}

void main() {
  Map<String, dynamic> stat(String label, String value, {String tone = 'neutral', String? href}) =>
      {'label': label, 'value': value, 'hidden': false, 'hint': '', 'tone': tone, 'href': href};

  final sales = ModuleDashboard({
    'module': 'sales',
    'title': 'বিক্রয়',
    'subtitle': 'এই মাস',
    'stats': [
      stat('আজ বিক্রি', '8,45,600', href: 'https://erp.example/sales/invoices'),
      stat('এই মাসে', '62,40,000'),
      stat('বাকি', '1,92,00,000', tone: 'warn'),
      stat('আজ আদায়', '6,12,350', tone: 'good'),
      stat('আজ চালান', '18'),
    ],
    'panels': const [],
    'listings': const [],
    'colours': {
      'head': ['blue', 'sky'],
      'cards': ['blue', 'sky', 'yellow', 'green'],
    },
    'watch': [
      {'label': 'তারিখ পেরোনো বিল', 'value': '14', 'tone': 'bad', 'href': null},
    ],
    'tiles': [
      {'label': '+ সরাসরি বিক্রি', 'href': 'https://erp.example/sales/direct', 'icon': 'plus'},
    ],
  });

  testWidgets('the new look: head in the web colours, four coloured cards, watch list, buttons, more figures', (tester) async {
    tall(tester);
    await tester.pumpWidget(MaterialApp(
      home: ModuleDashboardScreen(module: 'sales', name: 'বিক্রয়', open: (_) async => sales),
    ));
    await tester.pumpAndSettle();

    final head = tester.widget<Container>(find.byKey(const Key('dashboard-head')));
    final gradient = (head.decoration! as BoxDecoration).gradient! as LinearGradient;
    expect(gradient.colors, [dashboardPalette['blue'], dashboardPalette['sky']], reason: '⛔ মাথা ওয়েবের বিক্রয়ের রঙে (নীল-আকাশি) নয়');

    final cards = find.descendant(of: find.byKey(const Key('dashboard-cards')), matching: find.byType(Material));
    expect(cards, findsNWidgets(4), reason: '⛔ উপরে ঠিক চারটা কার্ড নয়');
    expect(tester.widget<Material>(cards.at(2)).color, dashboardPalette['yellow'], reason: '⛔ তৃতীয় কার্ড সার্ভারের বলা রঙে নয়');

    expect(find.descendant(of: find.byKey(const Key('dashboard-watch')), matching: find.text('তারিখ পেরোনো বিল')), findsOneWidget,
        reason: '⛔ "নজরে রাখুন"-এ সারি নেই');
    expect(find.byKey(const Key('dashboard-tile-+ সরাসরি বিক্রি')), findsOneWidget, reason: '⛔ দ্রুত কাজের বোতাম নেই');
    expect(find.byKey(const Key('dashboard-more')), findsOneWidget, reason: '⛔ পঞ্চম অঙ্কটা "আরও অঙ্ক"-এ নেই — একটা অঙ্ক হারাল');
  });

  testWidgets('an empty watch list says all clear in green', (tester) async {
    final calm = ModuleDashboard({...sales.payload, 'watch': const []});
    tall(tester);
    await tester.pumpWidget(MaterialApp(
      home: ModuleDashboardScreen(module: 'sales', name: 'বিক্রয়', open: (_) async => calm),
    ));
    await tester.pumpAndSettle();

    expect(find.descendant(of: find.byKey(const Key('dashboard-watch')), matching: find.byType(Text)), findsNWidgets(2),
        reason: '⛔ খালি "নজরে রাখুন"-এ সবুজ "সব ঠিক আছে" নেই');
  });

  testWidgets('an older server without colours keeps the old look', (tester) async {
    final old = ModuleDashboard(Map<String, dynamic>.from(sales.payload)..remove('colours'));
    tall(tester);
    await tester.pumpWidget(MaterialApp(
      home: ModuleDashboardScreen(module: 'sales', name: 'বিক্রয়', open: (_) async => old),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('dashboard-head')), findsNothing, reason: '⛔ পুরনো সার্ভারেও নতুন মাথা — রং ছাড়া আঁকা');
    expect(find.text('আজ চালান'), findsOneWidget, reason: '⛔ পুরনো সাজে সব অঙ্ক নেই');
  });
}
