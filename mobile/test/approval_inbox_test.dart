// ⓘ সারিগুলো `{...row, 'key': নতুন}` — একই চাবি দুবার, তাই const হতে পারে না; analyzer তবু const চায় (মিথ্যা সংকেত)
// ignore_for_file: prefer_const_constructors
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/records/approval_record.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';

/// The approvals inbox, driven the way a person drives it.
///
/// <p>⭐ অনুমোদন ভাগ ৩, দফা ২১ (৩০ অক্টোবর ২০২৬) — পরিকল্পনার পাতা ৬-এর তিন ছবি: ইনবক্স (চার ট্যাব, চিপ, কার্ডে শেষ সময় আর
/// ধাপ, দুই মোট, একসাথে অনুমোদন), কাগজের পাতা (সারাংশ, লাইন, যাত্রাপথ, সংযুক্তি, নিচে স্থির তিন বোতাম) আর একসাথে অনুমোদনের
/// পপ-আপ। ⓘ আগের দাবিগুলো সব থাকে — কারণ ছাড়া প্রত্যাখ্যান নয়, শেষ প্রশ্ন, ফেরত ≠ প্রত্যাখ্যান, অন্যজন আগে দিলে চুপচাপ সরে,
/// অঙ্ক বদলালে সই নয় — কেবল এখন সেগুলো কাগজের পাতায়, যেখানে বোতাম।
///
/// <p>সারির আকার docs/Contract §৫-এর `GET /approvals/inbox`, সার্ভারের দাবি `ThePhoneInboxKeepsTheWebsRulesTest`।
void main() {
  const row = <String, dynamic>{
    'id': '01a0c3f0-0000-7000-8000-0000000000a1',
    'documentType': 'PurchaseBill',
    'documentNo': 'PB-2609-0007',
    'documentId': '01a0c3f0-0000-7000-8000-0000000000b7',
    'action': 'confirm',
    'amount': '125000.0000',
    'currentLevel': 2,
    'requestedAt': '2026-09-13T09:40:00+06:00',
    'requesterName': 'করিম',
    'party': 'মেসার্স রহমান ট্রেডার্স · কারওয়ান বাজার',
    // ⓘ সার্ভারের সারাংশ পক্ষ দিয়েই শুরু ([[ApprovalApiController::summaryOf()]]) — কার্ড পক্ষ বাদ দিয়ে বাকিটা দেখায়
    'summary': 'মেসার্স রহমান ট্রেডার্স · কারওয়ান বাজার · সিমেন্ট · প্রধান শাখায়',
    'status': 'pending',
    'deadline': {'text': 'শেষ সময় পেরিয়েছে 3 ঘণ্টা', 'tone': 'danger'},
    'step': {'at': 2, 'of': 2, 'name': null},
    'bulkable': true,
  };
  final second = <String, dynamic>{
    ...row,
    'id': '01a0c3f0-0000-7000-8000-0000000000a2',
    'documentNo': 'PO-2609-0012',
    'documentType': 'PurchaseOrder',
    'amount': '2500.0000',
    'deadline': {'text': 'আজ 5:00', 'tone': 'success'},
    'step': {'at': 1, 'of': 1, 'name': null},
  };
  final money = <String, dynamic>{...row, 'id': 'money-1', 'documentNo': 'PAY-0231', 'documentType': 'Payment', 'bulkable': false};

  InboxPage page(List<Map<String, dynamic>> rows, {String tab = 'waiting', int? nextPage, int lateCount = 1}) => InboxPage.fromJson({
        'tab': tab,
        'tabs': [
          {'key': 'waiting', 'label': 'অপেক্ষায়', 'count': 7},
          {'key': 'sent', 'label': 'আমার পাঠানো', 'count': 0},
          {'key': 'done', 'label': 'আমি অনুমোদন দিয়েছি', 'count': 2},
          {'key': 'behalf', 'label': 'অন্যের হয়ে', 'count': 0},
        ],
        'modules': [
          {'key': 'purchase', 'label': 'ক্রয়', 'count': 2},
          {'key': 'accounts', 'label': 'ভাউচার', 'count': 3},
        ],
        'allCount': 7,
        'lateCount': lateCount,
        'rows': rows,
        'totals': {'pageCount': rows.length, 'pageAmount': '486500.0000', 'count': 7, 'amount': '521300.0000'},
        'nextPage': nextPage,
      });

  final sheet = ApprovalSheet.fromJson({
    'facts': [
      {'label': 'তারিখ', 'value': '13 Sep 2026'},
    ],
    'columns': [
      {'key': 'item', 'label': 'বিল'},
      {'key': 'amount', 'label': 'টাকা', 'numeric': true},
    ],
    'rows': [
      {'item': 'PB-0057', 'amount': '80,000.00'},
      {'item': 'PB-0061', 'amount': '45,000.00'},
    ],
    'totals': {'amount': '1,25,000.00'},
    'summary': {
      'requesterName': 'আল-আমিন',
      'requestedAt': '2026-10-21T10:15:00+06:00',
      'amountWords': 'এক লক্ষ পঁচিশ হাজার টাকা মাত্র',
      'deadline': {'text': 'দেরি', 'tone': 'danger'},
      'step': {'at': 2, 'of': 2},
    },
    'journey': [
      {
        'level': 1,
        'state': 'approved',
        'tookHours': 2,
        'by': [
          {'name': 'রহিম', 'decision': 'approved', 'label': 'অনুমোদিত', 'at': '2026-10-21T12:40:00+06:00', 'remarks': 'বিল মিলেছে'},
        ],
      },
      {'level': 2, 'state': 'now', 'by': []},
    ],
    'attachments': [
      {'id': 41, 'name': 'ব্যাংক-অ্যাডভাইস.pdf', 'size': '120 KB'},
    ],
  });

  Widget screen({
    Future<InboxPage> Function(String, String?, bool, int)? inbox,
    Future<void> Function(String, String?)? approve,
    Future<void> Function(String, String)? reject,
    Future<void> Function(String, String)? sendBack,
    Future<BulkPreview> Function(List<String>)? preview,
    Future<BulkResult> Function(List<String>, String?)? bulk,
  }) =>
      MaterialApp(
        home: ApprovalInboxScreen(
          loadInbox: inbox ?? (_, __, ___, ____) async => page([row, second]),
          approve: approve ?? (_, __) async {},
          reject: reject ?? (_, __) async {},
          sendBack: sendBack ?? (_, __) async {},
          loadSheet: (_) async => sheet,
          loadAttachment: (_, __) async => const [],
          bulkPreview: preview,
          bulkApprove: bulk,
        ),
      );

  // ⓘ তালিকার নিজের স্ক্রল — ট্যাব আর চিপের সারিও পাশে সরে, তাই "কোনটা" বলতে হয়
  final list = find.descendant(of: find.byType(RefreshIndicator), matching: find.byType(Scrollable)).first;

  Finder inDialog(String text) => find.descendant(of: find.byType(AlertDialog), matching: find.text(text));

  Future<void> openFirst(WidgetTester tester) async {
    await tester.tap(find.byKey(Key('approval-open-${row['id']}')));
    await tester.pumpAndSettle();
  }

  Future<void> tall(WidgetTester tester) async {
    tester.view.physicalSize = const Size(412, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
  }

  group('ছবি ১ — ইনবক্স', () {
    testWidgets('দফা ২১: the four tabs carry the web\'s names and counts, and a tab asks the server for its own list', (tester) async {
      final asked = <String>[];
      await tester.pumpWidget(screen(inbox: (tab, _, __, ___) async {
        asked.add(tab);
        return page(tab == 'done' ? [{...row, 'status': 'approved', 'statusLabel': 'অনুমোদিত', 'deadline': null, 'bulkable': false}] : [row], tab: tab);
      }));
      await tester.pumpAndSettle();

      // ⭐ নমুনা v4 দফা ৭ (মালিক "ok", ৮ নভেম্বর ২০২৬): ট্যাবের ছোট নাম, এক লাইনে — আগে "আমি অনুমোদন দিয়েছি" দুই লাইনে ভাঙত
      for (final (key, label) in [('waiting', 'অপেক্ষায়'), ('sent', 'পাঠিয়েছি'), ('done', 'দিয়েছি'), ('behalf', 'অন্যের হয়ে')]) {
        expect(find.descendant(of: find.byKey(ValueKey('approvals-tab-$key')), matching: find.text(label)), findsOneWidget,
            reason: '⛔ "$label" ট্যাব নেই, বা পুরনো লম্বা নাম');
      }
      expect(find.descendant(of: find.byKey(const ValueKey('approvals-tab-waiting')), matching: find.text('7')), findsOneWidget,
          reason: '⛔ দফা ২১: ট্যাবে সংখ্যা নেই');

      await tester.tap(find.byKey(const ValueKey('approvals-tab-done')));
      await tester.pumpAndSettle();
      expect(asked, ['waiting', 'done'], reason: '⛔ দফা ২১: ট্যাব বদলালে সার্ভারকে নিজের তালিকা চাওয়া হয় না');
      expect(find.text('অনুমোদিত'), findsOneWidget, reason: '⛔ দফা ২১: শেষ হওয়া কাগজ নিজের অবস্থা বলে না');
      expect(find.byKey(Key('approval-pick-${row['id']}')), findsNothing, reason: '⛔ দফা ২১: শেষ হওয়া কাগজে বাছাই');
      await tester.tap(find.byKey(Key('approval-open-${row['id']}')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('approval-paper-position')), findsNothing, reason: '⛔ দফা ২১: শেষ হওয়া কাগজ সিদ্ধান্তের পাতায় খোলে');
    });

    testWidgets('দফা ২১: chips filter by work and lateness on the server', (tester) async {
      final asked = <String>[];
      await tester.pumpWidget(screen(inbox: (tab, module, late, _) async {
        asked.add('$module:$late');
        return page([row]);
      }));
      await tester.pumpAndSettle();

      expect(find.text('সব 7'), findsOneWidget, reason: '⛔ দফা ২১: "সব" চিপ নেই');
      expect(find.text('ক্রয় 2'), findsOneWidget, reason: '⛔ দফা ২১: কাজের চিপে সংখ্যা নেই');
      await tester.tap(find.byKey(const ValueKey('approvals-chip-purchase')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('approvals-chip-late')));
      await tester.pumpAndSettle();
      expect(asked, ['null:false', 'purchase:false', 'purchase:true'], reason: '⛔ দফা ২১: চিপ সার্ভারকে কাজ বা দেরি চায় না');
    });

    testWidgets('নমুনা v4: a card says party, who asked and when, deadline in its colour, the step, and carries view + approve', (tester) async {
      await tall(tester);
      await tester.pumpWidget(screen());
      await tester.pumpAndSettle();

      expect(find.text('ক্রয় বিল · PB-2609-0007'), findsOneWidget);
      // decimal:4 on the wire, readable here — the salePrice lesson.
      expect(find.text('৳1,25,000.00'), findsOneWidget);
      expect(tester.widget<Text>(find.byKey(Key('approval-place-${row['id']}'))).data, 'মেসার্স রহমান ট্রেডার্স · কারওয়ান বাজার',
          reason: '⛔ দফা ২১: নম্বরের নিচে পক্ষ নেই');
      // ⓘ নমুনা v4 দফা ৪: লম্বা বর্ণনার লাইন বাদ — পক্ষ এক লাইন, "চেয়েছেন কে · কখন" এক লাইন
      expect(find.byKey(Key('approval-about-${row['id']}')), findsNothing, reason: '⛔ নমুনায় বাদ দেওয়া বর্ণনার লাইন ফিরেছে');
      expect(tester.widget<Text>(find.byKey(Key('approval-asker-${row['id']}'))).data, startsWith('চেয়েছেন করিম · '),
          reason: '⛔ নমুনা v4: কার্ডে কে চেয়েছেন নেই');
      expect(find.text('ধাপ 2/2'), findsNWidgets(1), reason: '⛔ দফা ২১: ধাপ কয়টার মধ্যে বলে না');
      expect(find.descendant(of: find.byKey(Key('approval-deadline-${row['id']}')), matching: find.text('⏱ শেষ সময় পেরিয়েছে 3 ঘণ্টা')), findsOneWidget);
      final late = tester.widget<Container>(find.byKey(Key('approval-deadline-${row['id']}')));
      final onTime = tester.widget<Container>(find.byKey(Key('approval-deadline-${second['id']}')));
      expect((late.decoration! as BoxDecoration).color, isNot((onTime.decoration! as BoxDecoration).color),
          reason: '⛔ দফা ২১: দেরি আর সময়মতো এক রঙে');
      // ⭐ নমুনা v4 দফা ৯: কার্ড থেকেই "✓ অনুমোদন দিন" — চাপলে কাগজ খুলে অঙ্কসহ প্রশ্ন; "দেখুন" কাগজ খোলে
      expect(find.byKey(Key('approval-view-${row['id']}')), findsOneWidget, reason: '⛔ কার্ডে "দেখুন" নেই');
      expect(find.byKey(Key('approval-card-approve-${row['id']}')), findsOneWidget, reason: '⛔ কার্ডে "✓ অনুমোদন দিন" নেই');
      // ⭐ দেরি আগে, ভাগে — লাল ভাগের মাথা আগে, সবুজ পরে
      final lateHead = tester.getTopLeft(find.byKey(const ValueKey('approvals-group-danger'))).dy;
      final okHead = tester.getTopLeft(find.byKey(const ValueKey('approvals-group-success'))).dy;
      expect(lateHead, lessThan(okHead), reason: '⛔ নমুনা v4: "দেরি হয়ে গেছে" ভাগ আগে নয়');
    });

    testWidgets('নমুনা v4: the card\'s approve opens the paper straight on the approve question with the amount', (tester) async {
      await tall(tester);
      final approved = <String>[];
      await tester.pumpWidget(screen(approve: (id, _) async => approved.add(id)));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(Key('approval-card-approve-${row['id']}')));
      await tester.pumpAndSettle();
      expect(find.byType(AlertDialog), findsOneWidget, reason: '⛔ কার্ডের "অনুমোদন দিন" প্রশ্ন ছাড়াই গেল, বা কিছুই হল না');
      expect(find.descendant(of: find.byType(AlertDialog), matching: find.textContaining('৳1,25,000.00')), findsOneWidget,
          reason: '⛔ প্রশ্নে অঙ্ক নেই — কোন অঙ্কে অনুমোদন, দেখা যায় না');
      expect(approved, isEmpty, reason: '⛔ প্রশ্নের আগেই অনুমোদন গেল');
    });

    testWidgets('নমুনা v4: summary band says waiting, late and the total in lakh; search and sort go to the server', (tester) async {
      final asked = <String>[];
      await tester.pumpWidget(MaterialApp(
        home: ApprovalInboxScreen(
          loadInboxSorted: (tab, module, late, p, q, sort) async {
            asked.add('$q|$sort');
            return page([row, second]);
          },
          loadSheet: (_) async => sheet,
        ),
      ));
      await tester.pumpAndSettle();

      expect(find.descendant(of: find.byKey(const ValueKey('approvals-sum-waiting')), matching: find.text('7')), findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('approvals-sum-late')), matching: find.text('1')), findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('approvals-sum-amount')), matching: find.text('৳5.21 লাখ')), findsOneWidget,
          reason: '⛔ মোট টাকা লাখে নেই (৳5,21,300 → ৳5.21 লাখ)');

      await tester.enterText(find.byKey(const ValueKey('approvals-search')), 'PB-2609');
      await tester.pump(const Duration(milliseconds: 500));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('approvals-sort-amount')));
      await tester.pumpAndSettle();
      expect(asked, ['|late', 'PB-2609|late', 'PB-2609|amount'], reason: '⛔ খোঁজা বা সাজানো সার্ভারে যায় না');
      expect(find.byKey(const ValueKey('approvals-group-danger')), findsNothing, reason: '⛔ "বড় অঙ্ক আগে" সাজানোয় দেরির ভাগ — ক্রম ভাঙে');
    });

    testWidgets('নমুনা v4: attachments count, amount-changed warning, and the decided tab shows decision and time', (tester) async {
      await tall(tester);
      await tester.pumpWidget(screen(inbox: (tab, _, __, ___) async => page(tab == 'done'
          ? [{...row, 'status': 'rejected', 'statusLabel': 'প্রত্যাখ্যাত', 'decidedAt': '2026-11-08T15:30:00+06:00', 'deadline': null, 'bulkable': false}]
          : [{...row, 'attachments': 3, 'amountChanged': true}, {...second, 'attachments': 0}], tab: tab)));
      await tester.pumpAndSettle();

      expect(find.descendant(of: find.byKey(Key('approval-files-${row['id']}')), matching: find.text('📎 3')), findsOneWidget, reason: '⛔ কার্ডে 📎 সংখ্যা নেই');
      expect(find.byKey(Key('approval-files-${second['id']}')), findsNothing, reason: '⛔ সংযুক্তি নেই, তবু 📎');
      expect(find.byKey(Key('approval-changed-${row['id']}')), findsOneWidget, reason: '⛔ অঙ্ক বদলের সতর্কতা কার্ডে নেই');
      expect(find.byKey(Key('approval-changed-${second['id']}')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('approvals-tab-done')));
      await tester.pumpAndSettle();
      final decided = find.byKey(Key('approval-decided-${row['id']}'));
      expect(find.descendant(of: decided, matching: find.textContaining('প্রত্যাখ্যাত · ')), findsOneWidget, reason: '⛔ "দিয়েছি" ট্যাবে সিদ্ধান্ত আর সময় নেই');
      expect(find.byKey(Key('approval-card-approve-${row['id']}')), findsNothing, reason: '⛔ শেষ হওয়া কাগজে অনুমোদনের বোতাম');
    });

    testWidgets('দফা ২১: two totals under the list — this list and all', (tester) async {
      await tester.pumpWidget(screen());
      await tester.pumpAndSettle();
      await tester.drag(list, const Offset(0, -2000));
      await tester.pumpAndSettle();

      // ⓘ নমুনা v4: নিচে এই তালিকার সংখ্যা, বাকি কয়টা, আর এই তালিকার অঙ্ক; সবগুলোর সংখ্যা আর অঙ্ক মাথার পট্টিতে
      expect(find.text('এই তালিকায় 2টা · আরও 5টা নিচে'), findsOneWidget, reason: '⛔ এই তালিকার মোট নেই');
      expect(find.text('৳4,86,500.00'), findsOneWidget);
    });

    testWidgets('an empty inbox is good news, not an error', (tester) async {
      await tester.pumpWidget(screen(inbox: (_, __, ___, ____) async => page(const [])));
      await tester.pumpAndSettle();

      expect(find.text('কিছু অপেক্ষা করছে না'), findsOneWidget);
      expect(find.text('তালিকা আনা গেল না'), findsNothing);
    });

    testWidgets('an unreachable server does not read as an empty inbox', (tester) async {
      // The two are opposite facts. A screen that drew "nothing is waiting" for
      // both would tell someone their work was done when it was only invisible.
      await tester.pumpWidget(screen(inbox: (_, __, ___, ____) async {
        throw DioException(requestOptions: RequestOptions(path: '/approvals/inbox'), type: DioExceptionType.connectionError);
      }));
      await tester.pumpAndSettle();

      expect(find.text('তালিকা আনা গেল না'), findsOneWidget);
      expect(find.text('কিছু অপেক্ষা করছে না'), findsNothing);
      expect(find.textContaining('সংযোগ নেই'), findsOneWidget);
    });

    testWidgets('দফা ২১: an older server without the inbox door still shows its one list', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: ApprovalInboxScreen(
          loadInbox: (_, __, ___, ____) async {
            throw DioException(
              requestOptions: RequestOptions(path: '/approvals/inbox'),
              response: Response(requestOptions: RequestOptions(path: '/approvals/inbox'), statusCode: 404),
            );
          },
          loadPending: () async => const ApprovalPage(rows: [ApprovalRecord({'id': 'old', 'documentType': 'PurchaseBill', 'documentNo': 'PB-OLD-1', 'amount': '10.0000', 'currentLevel': 1})]),
        ),
      ));
      await tester.pumpAndSettle();

      expect(find.text('ক্রয় বিল · PB-OLD-1'), findsOneWidget, reason: '⛔ দফা ২১: পুরনো সার্ভারে ইনবক্স খালি বা ত্রুটি');
      expect(find.byKey(const ValueKey('approvals-tab-waiting')), findsNothing, reason: 'পুরনো সার্ভারে ট্যাবের সংখ্যা জানা নেই');
      expect(find.text('ধাপ 1'), findsOneWidget);
    });

    testWidgets('a long inbox loads its next page on request (phone line audit, 15 Oct 2026)', (tester) async {
      final pages = <int>[];
      await tester.pumpWidget(screen(inbox: (_, __, ___, p) async {
        pages.add(p);
        return p == 1 ? page([row], nextPage: 2) : page([{...row, 'id': 'second-page', 'documentNo': 'PB-2609-0099'}]);
      }));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(find.byKey(const ValueKey('approvals-more')), 200, scrollable: list);
      await tester.tap(find.byKey(const ValueKey('approvals-more')));
      await tester.pumpAndSettle();

      expect(pages, [1, 2]);
      expect(find.text('ক্রয় বিল · PB-2609-0099', skipOffstage: false), findsOneWidget, reason: '⛔ পরের পাতার কাগজ ফোনে আসে না');
      expect(find.byKey(const ValueKey('approvals-more')), findsNothing, reason: 'শেষ পাতার পরে আর বোতাম নয়');
    });
  });

  group('ছবি ২ — কাগজের পাতা', () {
    testWidgets('দফা ২১: a tapped card opens its paper with summary, lines, journey and attachments, and three fixed buttons', (tester) async {
      await tall(tester);
      await tester.pumpWidget(screen());
      await tester.pumpAndSettle();
      await openFirst(tester);

      expect(find.text('ইনবক্স · 1/2'), findsOneWidget, reason: '⛔ দফা ২১: কাগজের পাতা বলে না তালিকার কোনটা');
      expect(find.text('এক লক্ষ পঁচিশ হাজার টাকা মাত্র'), findsOneWidget, reason: '⛔ দফা ২১: অঙ্ক কথায় নেই');
      expect(find.textContaining('চেয়েছেন আল-আমিন'), findsOneWidget, reason: '⛔ দফা ২১: কে চেয়েছেন, নেই');
      expect(find.text('PB-0057'), findsOneWidget, reason: '⛔ দফা ২১: কাগজের লাইন নেই');
      expect(find.text('80,000.00'), findsOneWidget, reason: '⛔ দফা ২১: অঙ্কের কলাম একটা, তবু নামসহ ("টাকা 80,000.00")');
      final total = find.byKey(const ValueKey('approval-lines-total'));
      expect(find.descendant(of: total, matching: find.text('মোট')), findsOneWidget, reason: '⛔ দফা ২১: লাইনের মোট নেই');
      expect(find.descendant(of: total, matching: find.text('1,25,000.00')), findsOneWidget, reason: '⛔ দফা ২১: লাইনের মোটের অঙ্ক নেই');
      expect(find.text('পুরো কাগজ ›'), findsOneWidget);
      expect(find.byKey(const ValueKey('approval-journey')), findsOneWidget, reason: '⛔ দফা ২১: যাত্রাপথ নেই');
      expect(find.textContaining('“বিল মিলেছে”'), findsOneWidget, reason: '⛔ দফা ২১: যাত্রাপথে কে কী বললেন, নেই');
      expect(find.textContaining('এখন এখানে'), findsOneWidget);
      expect(find.textContaining('ব্যাংক-অ্যাডভাইস.pdf'), findsOneWidget, reason: '⛔ দফা ২১: সংযুক্তি নেই');

      final bar = tester.widget<Scaffold>(find.byType(Scaffold).last).bottomNavigationBar;
      expect(bar, isNotNull, reason: '⛔ দফা ২১: সিদ্ধান্তের বোতাম নিচে স্থির নয়');
      for (final label in ['প্রত্যাখ্যান করুন', 'সংশোধনে ফেরত পাঠান', 'অনুমোদন দিন']) {
        expect(find.descendant(of: find.byWidget(bar!), matching: find.text(label)), findsOneWidget, reason: '⛔ দফা ২১: "$label" নিচের বারে নেই');
      }
    });

    testWidgets('দফা ২১: approving opens the next paper and takes the row off the list', (tester) async {
      var approvedId = '';
      await tester.pumpWidget(screen(approve: (id, _) async => approvedId = id));
      await tester.pumpAndSettle();
      await openFirst(tester);

      await tester.tap(find.byKey(Key('approval-approve-${row['id']}')));
      await tester.pumpAndSettle();
      expect(find.textContaining('৳1,25,000.00'), findsWidgets, reason: 'অনুমোদনের প্রশ্নে অঙ্ক নেই');
      await tester.tap(inDialog('অনুমোদন দিন'));
      await tester.pumpAndSettle();

      expect(approvedId, row['id'], reason: '⛔ অনুমোদন সার্ভারে যায়নি');
      expect(find.text('ইনবক্স · 1/1'), findsOneWidget, reason: '⛔ দফা ২১: অনুমোদনের পরে পরের কাগজ নিজে খোলে না');
      expect(find.text('ক্রয় আদেশ · PO-2609-0012'), findsWidgets, reason: '⛔ দফা ২১: অনুমোদনের পরে পরের কাগজ নিজে খোলে না');
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('ক্রয় বিল · PB-2609-0007'), findsNothing, reason: 'অনুমোদিত কাগজ তালিকায় রয়ে গেল');
    });

    testWidgets('a rejection cannot be sent without a reason, and goes only after the final question', (tester) async {
      var sentReason = '';
      await tester.pumpWidget(screen(reject: (_, r) async => sentReason = r));
      await tester.pumpAndSettle();
      await openFirst(tester);

      await tester.tap(find.byKey(Key('approval-reject-${row['id']}')));
      await tester.pumpAndSettle();
      // Dead until a reason is typed. ApprovalEngine::reject takes a non-null
      // string and ApprovalsApi.reject checks it too — three places, because a
      // refusal with no reason reaches the requester as something they cannot act on.
      final confirm = tester.widget<FilledButton>(find.descendant(
          of: find.byType(AlertDialog), matching: find.widgetWithText(FilledButton, 'প্রত্যাখ্যান করুন')));
      expect(confirm.onPressed, isNull);

      await tester.enterText(find.descendant(of: find.byType(AlertDialog), matching: find.byType(TextField)), 'দর বেশি');
      await tester.pumpAndSettle();
      await tester.tap(inDialog('প্রত্যাখ্যান করুন'));
      await tester.pumpAndSettle();
      expect(find.textContaining('আর ফেরানো যাবে না'), findsOneWidget, reason: '⛔ প্রত্যাখ্যানের আগে শেষ প্রশ্ন নেই');
      // ⭐ তারপর আলাদা শেষ প্রশ্ন — চূড়ান্ত
      await tester.tap(find.byKey(const ValueKey('reject-final-confirm')));
      await tester.pumpAndSettle();

      expect(sentReason, 'দর বেশি', reason: '⛔ প্রত্যাখ্যান কারণসহ যায়নি');
    });

    testWidgets('a rejection cancelled at the final question sends nothing', (tester) async {
      final rejected = <String>[];
      await tester.pumpWidget(screen(reject: (id, r) async => rejected.add(r)));
      await tester.pumpAndSettle();
      await openFirst(tester);

      await tester.tap(find.byKey(Key('approval-reject-${row['id']}')));
      await tester.pumpAndSettle();
      await tester.enterText(find.descendant(of: find.byType(AlertDialog), matching: find.byType(TextField)), 'ভুয়া বিল');
      await tester.pumpAndSettle();
      await tester.tap(inDialog('প্রত্যাখ্যান করুন'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('reject-final-cancel')));
      await tester.pumpAndSettle();

      expect(rejected, isEmpty, reason: '⛔ শেষ প্রশ্নে থাক বললেও প্রত্যাখ্যান গেল');
      expect(find.text('ক্রয় বিল · PB-2609-0007'), findsOneWidget, reason: 'কাগজ পাতাতেই থাকে');
    });

    // ⛔ ০.৪.৪২-এর ভুল (a6, ২১ অক্টোবর ২০২৬): "সংশোধনে ফেরত" চাপলে চূড়ান্ত প্রত্যাখ্যান হত। এখন দুই আলাদা কাজ, দুই দরজা।
    testWidgets('send back for correction calls send-back, never reject', (tester) async {
      final sentBack = <String>[];
      final rejected = <String>[];
      await tester.pumpWidget(screen(sendBack: (id, r) async => sentBack.add('$id:$r'), reject: (id, r) async => rejected.add('$id:$r')));
      await tester.pumpAndSettle();
      await openFirst(tester);

      await tester.tap(find.byKey(Key('approval-send-back-${row['id']}')));
      await tester.pumpAndSettle();
      await tester.enterText(find.descendant(of: find.byType(AlertDialog), matching: find.byType(TextField)), 'দর ঠিক করুন');
      await tester.pumpAndSettle();
      await tester.tap(inDialog('সংশোধনে ফেরত পাঠান'));
      await tester.pumpAndSettle();

      expect(sentBack, ['${row['id']}:দর ঠিক করুন'], reason: '⛔ সংশোধনে ফেরত send-back দরজায় যায়নি');
      expect(rejected, isEmpty, reason: '⛔ সংশোধনে ফেরত চাপলে প্রত্যাখ্যান');
      expect(find.byKey(const ValueKey('reject-final')), findsNothing, reason: 'ফেরতে চূড়ান্ত প্রশ্ন নয়');
      expect(find.text('সংশোধনে ফেরত পাঠানো হলো।'), findsOneWidget);
    });

    testWidgets('a colleague deciding first is not an error', (tester) async {
      // docs/Contract §৫ rule খ: two people can be looking at the same inbox.
      await tester.pumpWidget(screen(approve: (_, __) async {
        throw DioException(
          requestOptions: RequestOptions(path: '/approvals/x/approve'),
          response: Response(requestOptions: RequestOptions(path: '/approvals/x/approve'), statusCode: 409),
        );
      }));
      await tester.pumpAndSettle();
      await openFirst(tester);
      await tester.tap(find.byKey(Key('approval-approve-${row['id']}')));
      await tester.pumpAndSettle();
      await tester.tap(inDialog('অনুমোদন দিন'));
      await tester.pumpAndSettle();

      expect(find.textContaining('ইতিমধ্যে নিষ্পত্তি'), findsOneWidget);
      expect(find.text('ক্রয় আদেশ · PO-2609-0012'), findsOneWidget, reason: '⛔ অন্যজনের সিদ্ধান্ত হওয়া কাগজ সরে, পরেরটা খোলে');
    });

    testWidgets('an amount changed since the card loaded is not signed: the inbox reloads and says why (phone line audit, 15 Oct 2026)',
        (tester) async {
      var loads = 0;
      await tester.pumpWidget(screen(
        inbox: (_, __, ___, ____) async {
          loads++;
          return page([row, second]);
        },
        approve: (_, __) async {
          throw DioException(
            requestOptions: RequestOptions(path: '/approvals/x/approve'),
            response: Response(requestOptions: RequestOptions(path: '/approvals/x/approve'), statusCode: 412),
          );
        },
      ));
      await tester.pumpAndSettle();
      await openFirst(tester);
      await tester.tap(find.byKey(Key('approval-approve-${row['id']}')));
      await tester.pumpAndSettle();
      await tester.tap(inDialog('অনুমোদন দিন'));
      await tester.pumpAndSettle();

      expect(find.textContaining('অঙ্ক বদলেছে'), findsOneWidget, reason: '⛔ বদলানো অঙ্কের ফেরত চুপ');
      expect(loads, 2, reason: '⛔ তালিকা আবার আনা হলো না — পুরনো অঙ্কের কার্ড রয়ে গেল');
      expect(find.text('ক্রয় বিল · PB-2609-0007'), findsOneWidget, reason: '⛔ সই না পড়া কাগজ তালিকাতেই থাকে');
    });
  });

  group('ছবি ৩ — একসাথে অনুমোদন', () {
    testWidgets('দফা ২১: only cards the server calls bulkable can be picked, and the button counts the picks', (tester) async {
      await tester.pumpWidget(screen(inbox: (_, __, ___, ____) async => page([row, money])));
      await tester.pumpAndSettle();

      expect(find.byKey(Key('approval-pick-${row['id']}')), findsOneWidget);
      expect(find.byKey(const Key('approval-pick-money-1')), findsNothing, reason: '⛔ দফা ২১: টাকা নড়ার কাজ বাছা যায়');
      final dead = tester.widget<FilledButton>(find.byKey(const ValueKey('approvals-bulk')));
      expect(dead.onPressed, isNull, reason: '⛔ দফা ২১: কিছু না বেছেও একসাথে অনুমোদন চাপা যায়');

      await tester.tap(find.byKey(Key('approval-pick-${row['id']}')));
      await tester.pumpAndSettle();
      expect(find.text('বাছাই 1টা · একসাথে অনুমোদন'), findsOneWidget);
      expect(find.byKey(const ValueKey('approval-paper-position')), findsNothing, reason: '⛔ দফা ২১: বাছতে গিয়ে কাগজ খুলে গেল');
    });

    testWidgets('দফা ২১: the sheet shows what goes, the total and what is left out with the reason; only the goers are sent', (tester) async {
      // ⓘ নমুনা v4-এর কার্ড উঁচু (বোতামের সারি) — দ্বিতীয় কার্ড ৬০০-এর পর্দার বাইরে পড়ত
      await tall(tester);
      List<String>? sent;
      await tester.pumpWidget(screen(
        inbox: (_, __, ___, ____) async => page([row, second]),
        preview: (ids) async => BulkPreview.fromJson({
          'sign': [
            {'id': second['id'], 'work': 'ক্রয় আদেশ', 'documentNo': 'PO-2609-0012', 'amount': '2500.0000'},
          ],
          'total': '2500.0000',
          'skip': [
            {'id': row['id'], 'work': 'ক্রয় বিল', 'documentNo': 'PB-2609-0007', 'amount': '125000.0000', 'why': 'multi_step', 'reason': 'এক ধাপের বেশি'},
          ],
        }),
        bulk: (ids, _) async {
          sent = ids;
          return BulkResult.fromJson({'done': 1, 'skipped': {}});
        },
      ));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(Key('approval-pick-${row['id']}')));
      await tester.tap(find.byKey(Key('approval-pick-${second['id']}')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('approvals-bulk')));
      await tester.pumpAndSettle();

      expect(find.text('1টা কাগজে একসাথে অনুমোদন দেবেন?'), findsOneWidget);
      expect(find.text('৳2,500.00'), findsWidgets, reason: '⛔ দফা ২১: পপ-আপে মোট নেই');
      expect(find.textContaining('আপনি 2টা বেছেছিলেন — বাদ পড়ছে 1টা'), findsOneWidget, reason: '⛔ দফা ২১: কয়টা বাদ, বলে না');
      expect(find.text('ক্রয় বিল · PB-2609-0007 — এক ধাপের বেশি'), findsOneWidget, reason: '⛔ দফা ২১: কেন বাদ, বলে না');

      await tester.tap(find.byKey(const ValueKey('approvals-bulk-confirm')));
      await tester.pumpAndSettle();
      expect(sent, [second['id']], reason: '⛔ দফা ২১: বাদ পড়া কাগজও অনুমোদনে পাঠানো হলো');
      expect(find.text('1টায় অনুমোদন হয়েছে'), findsOneWidget);
    });

    testWidgets('দফা ২১: when nothing can go, the sheet cannot approve', (tester) async {
      await tester.pumpWidget(screen(
        preview: (ids) async => BulkPreview.fromJson({
          'sign': [],
          'total': '0.0000',
          'skip': [
            {'id': row['id'], 'work': 'ক্রয় বিল', 'why': 'no_limit', 'reason': 'আপনার টাকার সীমা ঠিক করা নেই'},
          ],
        }),
      ));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(Key('approval-pick-${row['id']}')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('approvals-bulk')));
      await tester.pumpAndSettle();

      expect(find.text('বাছাই থেকে একটাও একসাথে দেওয়া যায় না'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.byKey(const ValueKey('approvals-bulk-confirm'))).onPressed, isNull,
          reason: '⛔ দফা ২১: শূন্যটায় অনুমোদন চাপা যায়');
    });
  });

  group('salary never reaches a phone', () {
    test('a payroll row is recognised so it can be dropped', () {
      expect(const ApprovalRecord({'documentType': 'Payroll'}).isPayroll, isTrue);
      expect(const ApprovalRecord(row).isPayroll, isFalse);
    });

    test('দফা ২১: the inbox page drops a payroll row a second time', () {
      final got = InboxPage.fromJson({
        'rows': [row, {...row, 'id': 'pay', 'documentType': 'Payroll'}],
      });
      expect(got.rows.map((r) => r.id), [row['id']], reason: '⛔ দফা ২১: বেতনের সারি ফোনের পাতায় রয়ে গেল');
    });
  });
}
