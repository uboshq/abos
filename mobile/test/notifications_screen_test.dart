import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/records/notification_record.dart';
import 'package:abos_mobile/features/notifications/notifications_screen.dart';

/// The bell's page — the owner, 6 Oct 2026: "a notification icon next to the
/// user photo, so the notifications can be seen".
void main() {
  NotificationPage page({required bool firstRead, int unread = 1}) =>
      NotificationPage(unread: unread, items: [
        NotificationRecord({
          'id': 'n-1',
          'title': 'ব্যাংক ঋণের কিস্তি — Sonali',
          'body': 'আজ কিস্তি ৳10,000',
          'read': firstRead,
          'at': '2026-10-06T09:00:00+06:00',
        }),
        const NotificationRecord({
          'id': 'n-2',
          'title': 'হাতধারের তাগাদা',
          'body': '',
          'read': true,
          'at': '2026-10-05T09:00:00+06:00',
        }),
      ]);

  testWidgets('the list, newest first; a tap marks it read and opens it',
      (tester) async {
    final marked = <String>[];
    var read = false;

    await tester.pumpWidget(MaterialApp(
      home: NotificationsScreen(
        fetch: () async => page(firstRead: read, unread: read ? 0 : 1),
        markRead: (id) async {
          marked.add(id);
          read = true;
        },
        markAllRead: () async {},
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('ব্যাংক ঋণের কিস্তি — Sonali'), findsOneWidget);
    expect(find.text('হাতধারের তাগাদা'), findsOneWidget);
    expect(find.text('সব পড়া হয়েছে'), findsOneWidget,
        reason: 'there is one unread');

    await tester.tap(find.text('ব্যাংক ঋণের কিস্তি — Sonali'));
    await tester.pumpAndSettle();

    expect(marked, ['n-1']);
    expect(find.text('আজ কিস্তি ৳10,000'), findsWidgets,
        reason: 'the message opens');
  });

  testWidgets('read-all empties the bell', (tester) async {
    var all = false;

    await tester.pumpWidget(MaterialApp(
      home: NotificationsScreen(
        fetch: () async => page(firstRead: all, unread: all ? 0 : 1),
        markRead: (_) async {},
        markAllRead: () async => all = true,
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('সব পড়া হয়েছে'));
    await tester.pumpAndSettle();

    expect(all, isTrue);
    expect(find.text('সব পড়া হয়েছে'), findsNothing);
  });

  testWidgets('nothing yet says so', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: NotificationsScreen(
        fetch: () async => const NotificationPage(items: [], unread: 0),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('কোনো নোটিফিকেশন নেই'), findsOneWidget);
  });
}
