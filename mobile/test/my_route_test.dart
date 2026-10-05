import 'package:abos_mobile/core/orders/my_route_api.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/features/route/my_route_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ আজকের রুট — সমন্বয়কের ক্রম "ঘ" (৫ অক্টোবর ২০২৬)। রুট আর দোকান সার্ভারের; ফোন দেখায়, গোনে না।
void main() {
  RouteDay day(String date, {int? next}) => RouteDay.fromJson({
        'date': date,
        'routes': [
          {'id': 'r1', 'name': 'কেন্দুয়া শনিবার'},
        ],
        'shops': [
          {'id': 's1', 'route': 'r1', 'code': 'C-001', 'name': 'রহিম ট্রেডার্স', 'phone': '01711', 'sales': '1200.00', 'collections': '500.00', 'outstanding': '700.00'},
        ],
        'next_page': next,
      });

  testWidgets('the day asks for its own date, lists the shops under their route, and a shop opens its page', (tester) async {
    final asked = <String>[];
    RouteShop? opened;
    await tester.pumpWidget(MaterialApp(
      home: MyRouteScreen(
        today: () => DateTime(2026, 10, 3),
        load: (date, page) async {
          asked.add('$date#$page');
          return day(date, next: page == 1 ? 2 : null);
        },
        openShop: (_, shop) => opened = shop,
      ),
    ));
    await tester.pumpAndSettle();

    expect(asked, ['2026-10-03#1']);
    expect(find.text('শনিবার, 03/10/2026'), findsOneWidget);
    expect(find.text('কেন্দুয়া শনিবার'), findsOneWidget);
    expect(find.text('রহিম ট্রেডার্স'), findsOneWidget);
    expect(find.text('বকেয়া: ${Money.taka(700)}'), findsOneWidget);

    await tester.tap(find.byKey(const Key('route-more')));
    await tester.pumpAndSettle();
    expect(asked.last, '2026-10-03#2', reason: 'আরও দোকান — একই দিনের পরের পাতা');
    expect(find.byKey(const Key('route-more')), findsNothing);

    await tester.tap(find.byKey(const Key('route-shop-s1')).first);
    expect(opened?.id, 's1');
  });

  testWidgets('a day with no route says so in words', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: MyRouteScreen(load: (d, p) async => RouteDay.fromJson({'date': d, 'routes': [], 'shops': []})),
    ));
    await tester.pumpAndSettle();
    expect(find.text('এই দিনে কোনো রুট নেই'), findsOneWidget);
  });
}
