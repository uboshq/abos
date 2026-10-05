import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/dashboard/dashboard_api.dart';
import 'package:abos_mobile/features/dashboards/dashboards_screen.dart';

/// ⭐ The module dashboards on the phone — owner, 4 Oct 2026: *"egulo nadile bujbo kikore kihocche"*.
/// The figures are the server's, drawn as they came; a covered figure says so in words, never a blank that reads as zero;
/// lists go one field per line.
void main() {
  const inventory = ModuleDashboard({
    'title': 'মজুদ',
    'subtitle': 'গুদামের অবস্থা',
    'stats': [
      {
        'label': 'মজুদের মূল্য',
        'value': null,
        'hidden': true,
        'hint': 'কেনা দরে',
        'tone': 'neutral'
      },
      {
        'label': 'বিক্রয়যোগ্য',
        'value': '1,250',
        'hidden': false,
        'hint': '',
        'tone': 'neutral'
      },
      {
        'label': 'পুনঃঅর্ডারের নিচে',
        'value': '3',
        'hidden': false,
        'hint': '',
        'tone': 'warn'
      },
    ],
    'panels': [
      {
        'kind': 'series',
        'label': 'মাসে আসা-যাওয়া',
        'chart': 'bars',
        'firstLabel': 'এসেছে',
        'secondLabel': 'গেছে',
        'points': [
          {'label': 'Sep', 'first': '12,000.00', 'second': '8,000.00'},
        ],
      },
    ],
    'listings': [
      {
        'label': 'কম মজুদ',
        'empty': 'সব ঠিক আছে',
        'columns': [
          {'key': 'name', 'label': 'পণ্য'},
          {'key': 'available', 'label': 'বিক্রয়যোগ্য'}
        ],
        'rows': [
          ['সাবান', '2']
        ],
      },
    ],
  });

  testWidgets('the list names each module with its first figure and opens it',
      (tester) async {
    String? opened;
    await tester.pumpWidget(MaterialApp(
      home: DashboardsScreen(
        loadList: () async => const [
          DashboardEntry({
            'module': 'inventory',
            'name': 'মজুদ',
            'stat': {'label': 'মজুদের মূল্য', 'value': null, 'hidden': true}
          }),
          DashboardEntry({
            'module': 'sales',
            'name': 'বিক্রয়',
            'stat': {
              'label': 'আজকের বিক্রি',
              'value': '45,200.00',
              'hidden': false
            }
          }),
        ],
        openModule: (code) async {
          opened = code;
          return inventory;
        },
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('বিক্রয়'), findsOneWidget);
    expect(find.text('আজকের বিক্রি: 45,200.00'), findsOneWidget);
    expect(find.text('মজুদের মূল্য: চাবি নেই'), findsOneWidget);

    await tester.tap(find.byKey(const Key('dashboard-inventory')));
    await tester.pumpAndSettle();

    expect(opened, 'inventory');
    expect(find.text('1,250'), findsOneWidget);
    expect(find.text('চাবি নেই — দেখার অনুমতি লাগে'), findsOneWidget,
        reason: 'a covered figure must say so, not show a blank');
    expect(find.text('মাসে আসা-যাওয়া'), findsOneWidget);
    expect(find.text('12,000.00'), findsOneWidget);
    expect(find.text('সাবান'), findsOneWidget);
    expect(find.text('বিক্রয়যোগ্য: 2'), findsOneWidget,
        reason: 'one field per line, label and value together');
    // ⭐ এই মডিউলের রিপোর্ট — তারিখ আর PDF সহ (মালিক, ৪ অক্টোবর ২০২৬)
    await tester.scrollUntilVisible(
        find.byKey(const Key('dashboard-reports')), 200);
    expect(find.text('এই মডিউলের রিপোর্ট'), findsOneWidget);
  });

  testWidgets('a failed load says so instead of showing an empty dashboard',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: ModuleDashboardScreen(
          module: 'sales',
          name: 'বিক্রয়',
          open: (_) async => throw Exception('down')),
    ));
    await tester.pumpAndSettle();

    expect(find.text('আনা গেল না'), findsOneWidget);
  });
}
