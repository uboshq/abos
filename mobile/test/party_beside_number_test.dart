import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/records/approval_record.dart';
import 'package:abos_mobile/core/widgets/number_with_party.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';
import 'package:abos_mobile/features/approvals/my_requests_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ নম্বরের পাশে পক্ষ — মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের পাশে গ্রাহকের নাম
/// আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"*
///
/// <p>⛔ আগে সই-বাক্সের কার্ডে নম্বর একা, পক্ষ নিচে সারাংশের ভিতরে মিশে। ⭐ দাবি: সার্ভার `party` পাঠালে নম্বরের একই লাইনে
/// "নম্বর · নাম · পয়েন্ট", এক লাইনে কাটা (…); সারাংশে পক্ষ আর দুইবার নয়; পুরনো সার্ভার (`party` নেই) — কার্ড হুবহু আগের মতো।
/// "আমার অনুরোধ"-এর কার্ডেও একই।
void main() {
  const party = 'রহিম ট্রেডার্স · কারওয়ান বাজার';
  const row = <String, dynamic>{
    'id': 'ap-1',
    'documentType': 'Collection',
    'documentNo': 'COL-0042',
    'action': 'collection',
    'amount': '5000.0000',
    'currentLevel': 1,
    'requestedAt': '2026-10-19T09:40:00+06:00',
    'requesterName': 'করিম',
    'summary': '$party · অক্টোবরের বকেয়া · জমা: ক্যাশ',
  };

  Widget inbox(Map<String, dynamic> payload) => MaterialApp(
        home: ApprovalInboxScreen(
          loadPending: () async => ApprovalPage(rows: [ApprovalRecord(payload)]),
          approve: (_, __) async {},
          reject: (_, __) async {},
        ),
      );

  testWidgets('the party sits on the number line, once, and the summary keeps the rest', (tester) async {
    await tester.pumpWidget(inbox({...row, 'party': party}));
    await tester.pumpAndSettle();

    // ⓘ অনুমোদন ভাগ ৩, দফা ২১ (পরিকল্পনার পাতা ৬, ২১ অক্টোবর — এই নিয়মের পরের তারিখ): পক্ষ নম্বরের ঠিক নিচের লাইনে,
    // ওয়েবের কার্ডের মতো; নম্বর আর পক্ষ তবু একসাথে চোখে পড়ে, আর পক্ষ একবারই
    expect(find.text('আদায় · COL-0042'), findsOneWidget, reason: '⛔ নম্বরের লাইন নেই');
    expect(tester.widget<Text>(find.byKey(const Key('approval-place-ap-1'))).data, party, reason: '⛔ নম্বরের নিচে পক্ষ নেই');
    // ⓘ মালিকের নমুনা v4 ("ok", ৮ নভেম্বর ২০২৬ — ১৯ অক্টোবরের পরের সিদ্ধান্ত): কার্ডে লম্বা বর্ণনার লাইন বাদ — পক্ষ এক লাইন, "চেয়েছেন"
    // এক লাইন; কী বাবদ আর টাকা কোথায় কাগজের পাতার ঘরে ([[ApprovalPaperScreen]] `facts`)
    expect(find.text('অক্টোবরের বকেয়া · জমা: ক্যাশ'), findsNothing, reason: '⛔ নমুনায় বাদ দেওয়া বর্ণনার লাইন কার্ডে ফিরেছে');
    expect(find.textContaining(party), findsOneWidget, reason: '⛔ পক্ষ কার্ডে দুইবার');
  });

  // ⭐ ছোট ফোন (৩৬০) আর বড় পর্দা (৬০০) দুই মাপেই। ⛔ ৩৬০-এ আগে কার্ডের নিচের বোতামের সারি ("কাগজ দেখুন · প্রত্যাখ্যান ·
  // অনুমোদন") ডানে উপচাত — পক্ষ ছাড়াও; এখন বোতামগুলো মোড়ায়। কাগজের বোতামসহ কার্ড, কারণ সেটাই সবচেয়ে চওড়া।
  for (final width in [360.0, 600.0]) {
  testWidgets('a long name stays on one line and is cut, the number whole, at ${width.toInt()} wide', (tester) async {
    tester.view.physicalSize = Size(width * 3, 800 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    final long = 'মেসার্স ${'অনেক লম্বা নামের দোকান ' * 6}· কারওয়ান বাজার';
    await tester.pumpWidget(inbox({...row, 'party': long, 'summary': long, 'documentId': 'doc-1'}));
    await tester.pumpAndSettle();

    final text = tester.widget<Text>(find.byKey(const Key('approval-place-ap-1')));
    expect(text.maxLines, 1, reason: '⛔ পক্ষের লাইন ভেঙে কয়েক লাইন');
    expect(text.overflow, TextOverflow.ellipsis);
    final paragraph = tester.renderObject<RenderParagraph>(
        find.descendant(of: find.byKey(const Key('approval-place-ap-1')), matching: find.byType(RichText)));
    expect(paragraph.didExceedMaxLines, isTrue, reason: '⛔ নামটা যথেষ্ট লম্বা নয় — দাবিটা কাটা মাপছে না');
    expect(find.byTooltip(long), findsOneWidget, reason: '⛔ পুরো নাম পড়ার পথ নেই');
    expect(tester.takeException(), isNull, reason: '⛔ কার্ডের কিছু ডানে উপচে কেটে গেছে');
    // ⓘ নম্বর আর অঙ্ক পুরো, পর্দার ভেতরে — সিদ্ধান্তের বোতাম এখন কাগজের পাতায় (দফা ২১), কার্ডে নয়
    for (final label in ['আদায় · COL-0042', '৳5,000.00']) {
      // ⓘ নমুনা v4-এর কার্ড নিজের সাজ (Container), Material Card নয় — তাই সরাসরি লেখা ধরে মাপা
      final box = tester.getRect(find.text(label));
      expect(box.right, lessThanOrEqualTo(width), reason: '⛔ "$label" পর্দার বাইরে');
    }
  });
  }

  testWidgets('an old server without the party field draws the card as before', (tester) async {
    await tester.pumpWidget(inbox(row));
    await tester.pumpAndSettle();

    expect(find.text('আদায় · COL-0042'), findsOneWidget, reason: '⛔ পুরনো সার্ভারে নম্বর দেখাচ্ছে না');
    expect(find.text(row['summary'] as String), findsOneWidget, reason: '⛔ পুরনো সার্ভারে সারাংশ বদলে গেছে');
  });

  testWidgets('my requests name the party beside the number too, on a 360 wide phone', (tester) async {
    tester.view.physicalSize = const Size(360 * 3, 800 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      home: MyRequestsScreen(
        load: (_) async => MyRequestsPage.fromJson({
          'rows': [
            {
              'id': 'r1',
              'documentNo': 'PO-0007',
              'documentType': 'PurchaseOrder',
              'amount': '1000.0000',
              'status': 'pending',
              'statusLabel': 'অপেক্ষমাণ',
              'party': 'মেসার্স রহমান সাপ্লাই',
              'summary': 'মেসার্স রহমান সাপ্লাই · সিমেন্ট',
            },
            {'id': 'r2', 'documentNo': 'PO-0006', 'documentType': 'PurchaseOrder', 'status': 'pending', 'statusLabel': 'অপেক্ষমাণ', 'summary': 'রড'},
          ],
          'nextCursor': null,
        }),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.descendant(of: find.byKey(const ValueKey('my-request-number-r1')), matching: find.text('PO-0007 · মেসার্স রহমান সাপ্লাই')),
        findsOneWidget, reason: '⛔ "আমার অনুরোধ"-এ নম্বরের পাশে পক্ষ নেই');
    expect(find.text('সিমেন্ট'), findsOneWidget, reason: '⛔ সারাংশে পক্ষ দুইবার');
    expect(find.text('PO-0006'), findsOneWidget, reason: '⛔ পক্ষ ছাড়া সারি বদলে গেছে');
    expect(find.text('রড'), findsOneWidget);
    expect(tester.takeException(), isNull, reason: '⛔ ৩৬০ চওড়া ফোনে "আমার অনুরোধ"-এর কার্ড উপচায়');
  });

  test('the summary loses only its own copy of the party', () {
    expect(summaryAfterParty('ক · কী বাবদ', 'ক'), 'কী বাবদ');
    expect(summaryAfterParty('অঙ্ক বদলেছে · ক · কী বাবদ', 'ক'), 'অঙ্ক বদলেছে · কী বাবদ');
    expect(summaryAfterParty('কী বাবদ · ক', 'ক'), 'কী বাবদ');
    expect(summaryAfterParty('ক', 'ক'), isNull);
    expect(summaryAfterParty('কী বাবদ', null), 'কী বাবদ');
    expect(summaryAfterParty('কখগ · কী বাবদ', 'ক'), 'কখগ · কী বাবদ', reason: '⛔ নামের অংশ মিলে গেলেই কাটা');
    expect(summaryAfterParty(null, 'ক'), isNull);
  });
}
