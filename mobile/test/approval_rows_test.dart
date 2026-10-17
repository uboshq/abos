import 'package:abos_mobile/core/approvals/approval_rows_api.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/approval_rows/approval_rows_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakeApi implements ApprovalRowsApi {
  _FakeApi({this.empty = false});

  final bool empty;
  final asked = <String>[];
  final granted = <Map<String, Object?>>[];
  final revoked = <String>[];

  @override
  Future<DelegationSetup> delegations() async {
    asked.add('delegations');
    if (empty) return const DelegationSetup({'given': [], 'held': [], 'people': [], 'modules': []});
    return DelegationSetup({
      'given': [
        {'id': 'd1', 'person': 'করিম', 'starts_on': '17-10-2026', 'ends_on': '23-10-2026', 'modules': [], 'reason': 'ছুটিতে',
          'revoked': revoked.contains('d1'), 'active': !revoked.contains('d1')},
      ],
      'held': [
        {'id': 'd2', 'person': 'মালিক', 'starts_on': '15-10-2026', 'ends_on': '20-10-2026', 'modules': ['বিক্রয়'], 'active': true},
      ],
      'people': [
        {'id': 'u-karim', 'name': 'করিম'},
      ],
      'modules': [
        {'code': 'sales', 'label': 'বিক্রয়'},
      ],
    });
  }

  @override
  Future<Delegation> grant(
      {required String to, required String startsOn, required String endsOn, List<String> modules = const [], String? reason}) async {
    granted.add({'to': to, 'starts_on': startsOn, 'ends_on': endsOn, 'modules': modules, 'reason': reason});
    return const Delegation({'id': 'd9'});
  }

  @override
  Future<void> revoke(String id) async => revoked.add(id);

  @override
  Future<PaperQueuePage> documentQueue({bool mine = false, int page = 1}) async {
    asked.add('queue:${mine ? 'mine' : 'to_sign'}');
    if (empty) return const PaperQueuePage({'rows': [], 'count': 0});
    return PaperQueuePage({
      'rows': [
        {'id': 'ap1', 'document_no': 'DOC-0007', 'name': 'ডিলার চুক্তি', 'requester': 'মালিক', 'due_at': '18-10-2026 05:00 PM',
          'overdue': false, 'can_decide': !mine},
      ],
      'count': 1,
    });
  }

  @override
  Future<PaperQueuePage> signatures({String tab = 'to_sign', int page = 1}) async {
    asked.add('sign:$tab');
    if (empty) return const PaperQueuePage({'rows': [], 'count': 0});
    if (tab == 'signed') {
      return const PaperQueuePage({
        'rows': [
          {'document_no': 'DOC-0007', 'name': 'ডিলার চুক্তি', 'signer': 'করিম', 'signed_at': '17-10-2026 11:00 AM', 'version': 'v1',
            'hash': 'ab12cd34ef56'},
        ],
        'count': 1,
      });
    }
    return PaperQueuePage({
      'rows': [
        {'id': 'ap2', 'document_no': 'DOC-0008', 'name': 'ভাড়ার চুক্তি', 'requester': 'মালিক', 'can_decide': tab == 'to_sign'},
      ],
      'count': 1,
    });
  }
}

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"* — সইয়ের ভার, দলিলের অনুমোদনের সারি আর সই কেন্দ্র ফোনের পর্দায়।
void main() {
  testWidgets('hand-overs show both sides, a new one sends the person, dates and work, and one can be taken back', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: DelegationScreen(api: api, today: DateTime(2026, 10, 17))));
    await tester.pumpAndSettle();

    expect(find.text('আমার কাছে যাঁদের ভার · 1 টি'), findsOneWidget);
    expect(find.text('আমি যাঁদের দিয়েছি · 1 টি'), findsOneWidget);
    expect(find.text('সব কাজে'), findsOneWidget, reason: 'কাজ না বাছলে সব কাজে');

    await tester.tap(find.byKey(const ValueKey('delegation-new')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('delegation-save')));
    await tester.pumpAndSettle();
    expect(find.text('কাকে ভার দেবেন, বাছুন।'), findsOneWidget);
    expect(api.granted, isEmpty);

    await tester.tap(find.byKey(const ValueKey('delegation-to')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('করিম').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('delegation-module-sales')));
    await tester.enterText(find.byKey(const ValueKey('delegation-reason')), 'ছুটিতে');
    await tester.tap(find.byKey(const ValueKey('delegation-save')));
    await tester.pumpAndSettle();

    expect(api.granted.single, {
      'to': 'u-karim', 'starts_on': '2026-10-17', 'ends_on': '2026-10-23', 'modules': ['sales'], 'reason': 'ছুটিতে',
    });

    await tester.tap(find.byKey(const ValueKey('delegation-revoke-d1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('delegation-revoke-yes')));
    await tester.pumpAndSettle();
    expect(api.revoked, ['d1']);
    expect(find.byKey(const ValueKey('delegation-revoke-d1')), findsNothing, reason: 'ফেরানো ভারে আর "ফেরান" নেই');
  });

  testWidgets('the document queue signs or refuses through the approval door, and "sent by me" has no buttons', (tester) async {
    final api = _FakeApi();
    final signed = <String>[];
    final refused = <String>[];
    await tester.pumpWidget(MaterialApp(
      home: PaperQueueScreen(
        kind: PaperQueueKind.documents,
        api: api,
        approve: (id) async => signed.add(id),
        reject: (id, reason) async => refused.add('$id:$reason'),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('DOC-0007 · ডিলার চুক্তি'), findsOneWidget);
    expect(find.text('মোট 1 টি'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('queue-sign-ap1')));
    await tester.pumpAndSettle();
    expect(signed, ['ap1']);

    await tester.tap(find.byKey(const ValueKey('queue-refuse-ap1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('queue-reason-send')));
    await tester.pumpAndSettle();
    expect(refused, isEmpty, reason: 'কারণ ছাড়া ফেরত নয়');
    await tester.tap(find.byKey(const ValueKey('queue-refuse-ap1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('queue-reason')), 'সই নেই');
    await tester.tap(find.byKey(const ValueKey('queue-reason-send')));
    await tester.pumpAndSettle();
    expect(refused, ['ap1:সই নেই']);

    await tester.tap(find.byKey(const ValueKey('queue-tab-mine')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'queue:mine');
    expect(find.byKey(const ValueKey('queue-sign-ap1')), findsNothing, reason: 'নিজের পাঠানো কাগজে সইয়ের বোতাম নয়');
  });

  testWidgets('the signature centre has its three tabs and the history names the signer, version and fingerprint', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: PaperQueueScreen(kind: PaperQueueKind.signatures, api: api)));
    await tester.pumpAndSettle();

    expect(api.asked.last, 'sign:to_sign');
    expect(find.byKey(const ValueKey('queue-sign-ap2')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('queue-tab-asked')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'sign:asked');
    expect(find.byKey(const ValueKey('queue-sign-ap2')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('queue-tab-signed')));
    await tester.pumpAndSettle();
    expect(find.text('সই: করিম · 17-10-2026 11:00 AM'), findsOneWidget);
    expect(find.text('v1 · ছাপ ab12cd34ef56…'), findsOneWidget);
  });

  testWidgets('an empty hand-over list, queue and signature centre can be pulled to refresh', (tester) async {
    for (final screen in [
      DelegationScreen(api: _FakeApi(empty: true)),
      PaperQueueScreen(kind: PaperQueueKind.documents, api: _FakeApi(empty: true)),
      PaperQueueScreen(kind: PaperQueueKind.signatures, api: _FakeApi(empty: true)),
    ]) {
      await tester.pumpWidget(MaterialApp(home: screen));
      await tester.pumpAndSettle();
      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
    }
  });
}
