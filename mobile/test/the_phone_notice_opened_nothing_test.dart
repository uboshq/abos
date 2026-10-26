import 'package:abos_mobile/core/records/notification_record.dart';
import 'package:abos_mobile/features/notifications/notifications_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ ফোনের নোটিশে চাপলে খবরের কাগজটা খোলে — মালিকের বাগ "ভাউচার ভিউ হয় না" (fe, ১৯ অক্টোবর ২০২৬)।
///
/// <p>⛔ আগে নোটিশ কেবল শিরোনাম আর লেখা দেখাত — সার্ভার কাগজের `url` পাঠাত, অ্যাপ পড়তই না, তাই "ভাউচার পাকা হলো"-র নোটিশ
/// থেকে ভাউচারে যাওয়ার কোনো পথ ছিল না।
///
/// <p>⭐ দাবি: `url` থাকলে "খুলুন" — চাপলে ঐ পথ (কেবল পথ আর প্রশ্ন, সাইটের নাম বাদ) মেনুর একই দরজায় যায়; `url` না থাকলে বা
/// পথ না হলে বোতাম নেই।
void main() {
  NotificationPage page(List<Map<String, dynamic>> rows) =>
      NotificationPage(unread: 0, items: [for (final r in rows) NotificationRecord(r)]);

  Future<List<String>> open(WidgetTester tester, Map<String, dynamic> row) async {
    final opened = <String>[];
    await tester.pumpWidget(MaterialApp(
      home: NotificationsScreen(
        fetch: () async => page([row]),
        markRead: (_) async {},
        markAllRead: () async {},
        openWeb: (_, path) => opened.add(path),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text(row['title'] as String));
    await tester.pumpAndSettle();
    return opened;
  }

  testWidgets('a notice about a voucher opens the voucher', (tester) async {
    final opened = await open(tester, {
      'id': 'n-1', 'title': 'ভাউচার পাকা হলো — JV-0012', 'body': 'অনুমোদন শেষ', 'read': true,
      'url': 'https://erp.adi.com.bd/accounts/vouchers/12',
    });

    expect(find.byKey(const ValueKey('notification-open')), findsOneWidget, reason: '⛔ নোটিশ থেকে কাগজে যাওয়ার পথ নেই');
    await tester.tap(find.byKey(const ValueKey('notification-open')));
    await tester.pumpAndSettle();
    expect(opened, ['/accounts/vouchers/12'], reason: 'সাইটের নাম বাদ, কেবল পথ — মেনুর একই দরজা');
  });

  testWidgets('a query on the path is kept', (tester) async {
    final opened = await open(tester, {
      'id': 'n-2', 'title': 'অনুমোদন চাওয়া হয়েছে', 'body': '', 'read': true, 'url': '/approvals?tab=mine',
    });
    await tester.tap(find.byKey(const ValueKey('notification-open')));
    await tester.pumpAndSettle();
    expect(opened, ['/approvals?tab=mine']);
  });

  testWidgets('no address, no button', (tester) async {
    await open(tester, {'id': 'n-3', 'title': 'শুধু খবর', 'body': 'কিছু খোলার নেই', 'read': true});
    expect(find.byKey(const ValueKey('notification-open')), findsNothing);

    expect(const NotificationRecord({'url': 'mailto:a@b.c'}).webPath, isNull, reason: 'পথ নয় এমন ঠিকানা খোলা হয় না');
    expect(const NotificationRecord({'url': ''}).webPath, isNull);
  });
}
