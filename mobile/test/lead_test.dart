import 'package:abos_mobile/core/orders/lead_api.dart';
import 'package:abos_mobile/features/leads/lead_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ লিড — সমন্বয়কের ক্রম "ঘ" (৫ অক্টোবর ২০২৬)। নিয়ম সার্ভারের; ফোন যা পাঠায় আর যা দেখায় তা-ই দাবি।
class _FakeApi implements LeadApi {
  LeadForm? created;
  (String, LeadForm)? updated;
  String? askedStatus;

  static const setupData = LeadSetup(
    sources: [LeadChoice('field_visit', 'মাঠে গিয়ে'), LeadChoice('walk_in', 'নিজে এসেছেন')],
    statuses: [LeadChoice('new', 'নতুন'), LeadChoice('contacted', 'যোগাযোগ হয়েছে'), LeadChoice('lost', 'হারিয়েছি')],
  );

  static const lead = Lead(
    id: 'l1', no: 'LD-0001', name: 'নতুন মুদি', phone: '01700000001', source: 'field_visit',
    sourceLabel: 'মাঠে গিয়ে', status: 'new', statusLabel: 'নতুন', converted: false,
  );

  @override
  Future<LeadSetup> setup() async => setupData;

  @override
  Future<(List<Lead>, int?)> list({String? query, String? status, int page = 1}) async {
    askedStatus = status;
    return (const [lead], null);
  }

  @override
  Future<Lead> show(String id) async => lead;

  @override
  Future<Lead> create(LeadForm form) async {
    created = form;
    return lead;
  }

  @override
  Future<Lead> update(String id, LeadForm form) async {
    updated = (id, form);
    return lead;
  }
}

void main() {
  testWidgets('a new lead sends the name, phone and source, never a status', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: LeadFormScreen(api: api, setup: _FakeApi.setupData)));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('lead-save')));
    await tester.pumpAndSettle();
    expect(api.created, isNull, reason: 'নাম ছাড়া কিছুই যায় না');
    expect(find.text('দোকানের নাম লিখুন।'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('lead-name')), 'বাজারের দোকান');
    await tester.enterText(find.byKey(const Key('lead-phone')), '01800000000');
    await tester.tap(find.byKey(const Key('lead-save')));
    await tester.pumpAndSettle();
    final sent = api.created!.toJson();
    expect(sent['name'], 'বাজারের দোকান');
    expect(sent['phone'], '01800000000');
    expect(sent['source'], 'field_visit');
    expect(sent.containsKey('status'), isFalse, reason: 'নতুন লিডের অবস্থা সার্ভার বসায়');
  });

  testWidgets('marking a lead lost asks for the reason and sends it', (tester) async {
    final api = _FakeApi();
    // ⓘ লম্বা ফোনের মাপ — সব ঘর এক পর্দায়, নিচের বোতাম তালিকার অলস গঠনে হারায় না
    tester.view.physicalSize = const Size(800, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(home: LeadFormScreen(api: api, setup: _FakeApi.setupData, lead: _FakeApi.lead)));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('lead-lost-reason')), findsNothing);

    await tester.tap(find.byKey(const Key('lead-status')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('হারিয়েছি').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('lead-lost-reason')), 'অন্য কোম্পানির মাল রাখেন');
    await tester.tap(find.byKey(const Key('lead-save')));
    await tester.pumpAndSettle();

    expect(api.updated!.$1, 'l1');
    expect(api.updated!.$2.toJson()['status'], 'lost');
    expect(api.updated!.$2.toJson()['lost_reason'], 'অন্য কোম্পানির মাল রাখেন');
  });

  testWidgets('a status chip asks the server for that status', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: LeadListScreen(api: api)));
    await tester.pumpAndSettle();
    expect(find.text('নতুন মুদি'), findsOneWidget);

    await tester.tap(find.byKey(const Key('lead-status-contacted')));
    await tester.pumpAndSettle();
    expect(api.askedStatus, 'contacted');
  });
}
