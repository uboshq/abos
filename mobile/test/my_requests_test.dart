import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/features/approvals/my_requests_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ "আমার অনুরোধ" ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*)।
///
/// <p>⛔ আগে মেনুর ঐ সারি ওয়েব-পাতা খুলত। ⭐ দাবি: সারিটা ফোনের পর্দা খোলে; পর্দা সার্ভারের সারি আঁকে — নম্বর, অঙ্ক,
/// অবস্থা, শেষ কথা আর ফেরতের কারণ — মাথায় পুরো গোনা আর অপেক্ষমাণের গোনা; পরের পাতা কার্সর ধরে।
void main() {
  test('the menu row opens the phone screen behind the approvals switch', () {
    final screen = NativeForWeb.screenFor('/approvals/mine', title: 'আমার অনুরোধ');
    expect(screen, isA<ModuleGateView>(), reason: '"আমার অনুরোধ" এখনো ওয়েব-জানালায়');
    expect((screen! as ModuleGateView).path, 'approvals');
    expect((screen as ModuleGateView).child, isA<MyRequestsScreen>());
    expect(NativeForWeb.iconFor('/approvals/mine'), isNot(Icons.language_outlined));
  });

  testWidgets('it draws what the server says, with the reason a paper came back, and pages on', (tester) async {
    final asked = <String?>[];
    MyRequestsPage page(List<Map<String, dynamic>> rows, String? next) =>
        MyRequestsPage.fromJson({'rows': rows, 'nextCursor': next, 'count': 3, 'pendingCount': 1});

    await tester.pumpWidget(MaterialApp(
      home: MyRequestsScreen(load: (cursor) async {
        asked.add(cursor);
        return cursor == null
            ? page([
                {
                  'id': 'a1',
                  'documentNo': 'PO-0002',
                  'documentType': 'PurchaseOrder',
                  'amount': '1000.0000',
                  'status': 'returned',
                  'statusLabel': 'সংশোধনে ফেরত',
                  'requestedAt': '2026-10-17T10:00:00+06:00',
                  'summary': 'সিমেন্ট',
                  'lastDecision': {'by': 'করিম', 'label': 'সংশোধনে ফেরত', 'remarks': 'দর ঠিক করুন'},
                },
                {
                  'id': 'a2',
                  'documentNo': 'PO-0001',
                  'documentType': 'PurchaseOrder',
                  'amount': '500.0000',
                  'status': 'pending',
                  'statusLabel': 'অপেক্ষমাণ',
                  'requestedAt': '2026-10-16T10:00:00+06:00',
                  'lastDecision': null,
                },
              ], 'next-1')
            : page([
                {'id': 'a3', 'documentNo': 'PO-0000', 'documentType': 'PurchaseOrder', 'status': 'approved', 'statusLabel': 'অনুমোদিত'},
              ], null);
      }),
    ));
    await tester.pumpAndSettle();

    expect(asked, [null]);
    expect(find.text('আমার অনুরোধ'), findsOneWidget);
    expect(find.text('PO-0002'), findsOneWidget);
    expect(find.textContaining('1,000'), findsOneWidget);
    expect(find.text('কারণ: দর ঠিক করুন'), findsOneWidget, reason: '⛔ ফেরতের কারণ লেখক দেখেন না');
    expect(find.textContaining('শেষ কথা: সংশোধনে ফেরত · করিম'), findsOneWidget);
    expect(find.textContaining('৩ টি').evaluate().isNotEmpty || find.textContaining('3 টি').evaluate().isNotEmpty, isTrue,
        reason: 'পুরো তালিকার গোনা মাথায় নেই');
    expect(find.byKey(const ValueKey('my-requests-pending')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('my-requests-more')));
    await tester.pumpAndSettle();
    expect(asked, [null, 'next-1'], reason: 'পরের পাতা কার্সর ধরে চাওয়া হয়নি');
    expect(find.text('PO-0000'), findsOneWidget);
    expect(find.byKey(const ValueKey('my-requests-more')), findsNothing);
  });

  testWidgets('nothing sent yet says so, and a pull on the empty screen asks again', (tester) async {
    var calls = 0;
    await tester.pumpWidget(MaterialApp(
      home: MyRequestsScreen(load: (_) async {
        calls++;
        return MyRequestsPage.fromJson(const {'rows': [], 'count': 0, 'pendingCount': 0});
      }),
    ));
    await tester.pumpAndSettle();
    expect(find.text('আপনার পাঠানো কোনো অনুরোধ নেই'), findsOneWidget);

    await tester.fling(find.text('আপনার পাঠানো কোনো অনুরোধ নেই'), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(calls, 2, reason: 'খালি পর্দায় টানলে আবার চাওয়া হয়নি');
  });
}
