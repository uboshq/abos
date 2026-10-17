import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_module.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/features/sales_papers/sales_paper_screens.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay sei vabe koro, direct webpage app e bujajayna"*।
///
/// <p>⛔ কী ভাঙা ছিল: মেনুর যে সারির ফোন-পর্দা আগে থেকেই ছিল — ভাউচারের ধরন, বাতিল বিল, DO-র ট্যাব, সব ভাউচার — সেগুলো
/// ওয়েবের দ্বিতীয় সারি বলে ওয়েব-জানালায় খুলত, কারণ মেনু চিনত কেবল রুটের নাম, প্রশ্নাংশ নয়।
///
/// <p>⭐ দাবি: প্রতিটা সারি ফোনের নিজের পর্দা খোলে, ছাঁকনি বসিয়ে, আর সেই ছাঁকনি সার্ভারকে বলা হয়; ফোনে যে ঘর আগে থেকে
/// আছে তার ওয়েব-জমজ মেনুতে দ্বিতীয়বার আসে না; আর ফোনের পর্দার ঘরে ওয়েবের গ্লোব থাকে না।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Widget inner(Widget? screen) {
    expect(screen, isA<ModuleGateView>(), reason: 'ফোনের মডিউল-সুইচের পিছনে নয়');
    return (screen! as ModuleGateView).child;
  }

  test('every voucher row of the web menu opens the phone\'s voucher list on its own kind', () {
    for (final (web, view) in [
      ('/accounts/vouchers/receipt', 'receipt'),
      ('/accounts/vouchers/payment', 'payment'),
      ('/accounts/vouchers/expense', 'expense'),
      ('/accounts/vouchers/journal', 'journal'),
      ('/accounts/vouchers/contra', 'contra'),
    ]) {
      final screen = inner(NativeForWeb.screenFor(web, title: 'নাম'));
      expect(screen, isA<VoucherListScreen>(), reason: '$web এখনো ওয়েব-জানালায়');
      expect((screen as VoucherListScreen).view, view);
      expect(VoucherView.byKey(view), isNotNull, reason: '$view চাবি পর্দা চেনে না — "সব" খুলত');
    }
    expect(VoucherView.byKey('receipt')?.type, 'receipt');
    expect(VoucherView.byKey('receipt')?.money, isNull, reason: '"আদায়" মানে সব রসিদ — নগদ/ব্যাংক বেছে নয়');

    expect(inner(NativeForWeb.screenFor('/accounts/voucher-list')), isA<VoucherListScreen>());
    expect(NativeForWeb.screenFor('/accounts/vouchers/no-such-kind'), isNull, reason: 'অচেনা ধরন আন্দাজে নয়');
  });

  test('the cancelled-invoice row opens the invoice list asking only for cancelled bills; the plain row does not', () {
    final cancelled = inner(NativeForWeb.screenFor('/sales/invoices?stage=cancelled&cancelled=1', title: 'বাতিল-ইনভয়েস (CXL)'));
    expect(cancelled, isA<SalesPaperListScreen>());
    expect(((cancelled as SalesPaperListScreen).api as ServerSalesPaperApi).cancelled, isTrue);
    expect(cancelled.title, 'বাতিল-ইনভয়েস (CXL)');

    final plain = inner(NativeForWeb.screenFor('/sales/invoices')) as SalesPaperListScreen;
    expect((plain.api as ServerSalesPaperApi).cancelled, isFalse);
    expect(plain.title, isNull);
    expect((inner(NativeForWeb.screenFor('/sales/challans')) as SalesPaperListScreen).kind, SalesPaperKind.challan);
  });

  test('a delivery-order tab row opens the phone\'s delivery-order list, not a web window', () {
    for (final tab in ['pending', 'partial', 'back', 'history']) {
      final screen = NativeForWeb.screenFor('/sales/delivery-orders?tab=$tab', title: 'ট্যাব');
      expect(screen, isA<ModuleGateView>(), reason: 'DO-র "$tab" ট্যাব এখনো ওয়েব-জানালায়');
      expect((screen! as ModuleGateView).path, 'delivery-orders');
    }
    expect(NativeForWeb.screenFor('/sales/delivery-orders'), isA<ModuleGateView>());
  });

  group('the filter reaches the server', () {
    late _Server server;
    late HttpClientAdapter real;

    setUp(() {
      FakeSecureStorage.install();
      real = ApiClient.dio.httpClientAdapter;
      server = _Server();
      ApiClient.dio.httpClientAdapter = server;
    });
    tearDown(() => ApiClient.dio.httpClientAdapter = real);

    test('the delivery-order door sends the tab, and the sales-order door never does', () async {
      await const ServerDeliveryOrderApi(tab: 'pending').list();
      expect(server.asked.last.path, '/sales/delivery-orders');
      expect(server.asked.last.query['tab'], 'pending');

      await const ServerDeliveryOrderApi(orders: true, tab: 'pending').list();
      expect(server.asked.last.query.containsKey('tab'), isFalse, reason: 'আদেশের দরজা DO-র ট্যাব চেনে না');

      await const ServerDeliveryOrderApi().list();
      expect(server.asked.last.query.containsKey('tab'), isFalse);
    });

    test('the invoice door asks for cancelled bills only when told to, and never for challans', () async {
      await const ServerSalesPaperApi(cancelled: true).list(SalesPaperKind.invoice, from: '2026-10-01', to: '2026-10-17');
      expect(server.asked.last.query['cancelled'], '1');
      expect(server.asked.last.query['status'], 'cancelled');

      await const ServerSalesPaperApi().list(SalesPaperKind.invoice, from: '2026-10-01', to: '2026-10-17');
      expect(server.asked.last.query.containsKey('status'), isFalse, reason: 'সাধারণ তালিকা বাতিল চাইছে');

      await const ServerSalesPaperApi(cancelled: true).list(SalesPaperKind.challan, from: '2026-10-01', to: '2026-10-17');
      expect(server.asked.last.query.containsKey('status'), isFalse);
    });
  });

  test('the menu drops a web row the phone already has as its own tile, and native rows lose the web globe', () {
    const repository = MenuRepository();
    final menu = [
      MenuModule.fromJson(const {
        'code': 'accounts',
        'label': 'হিসাব',
        'groups': {
          'transactions': [
            {'route': 'accounts.voucher.index', 'label': 'পরিশোধ', 'web': '/accounts/vouchers/payment'},
            {'route': 'accounts.voucher.index', 'label': 'আদায়', 'web': '/accounts/vouchers/receipt'},
            {'route': 'accounts.chart.index', 'label': 'হিসাবের তালিকা', 'web': '/accounts/chart'},
          ],
        },
      }),
    ];
    const native = [MenuItem(key: 'vouchers/payment', label: 'পেমেন্ট', icon: Icons.outbox_outlined, routeName: 'vouchers/payment')];

    final tiles = repository.webTiles(menu, native);
    final byWeb = {for (final t in tiles) t.webPath: t};

    expect(byWeb.containsKey('/accounts/vouchers/payment'), isFalse, reason: '"পরিশোধ" আর "পেমেন্ট" — একই পর্দা দুইবার');
    expect(byWeb['/accounts/vouchers/receipt']?.icon, isNot(Icons.language_outlined), reason: 'ফোনের পর্দায় ওয়েবের গ্লোব');
    expect(byWeb['/accounts/chart']?.icon, Icons.language_outlined, reason: 'ওয়েব-জানালার ঘর গ্লোবই রাখে');
  });
}

class _Asked {
  _Asked(this.path, this.query);
  final String path;
  final Map<String, String> query;
}

class _Server implements HttpClientAdapter {
  final List<_Asked> asked = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    asked.add(_Asked(options.path, {for (final e in options.queryParameters.entries) e.key: '${e.value}'}));
    return ResponseBody.fromString(
      jsonEncode({'orders': [], 'rows': [], 'count': 0, 'sum': '0', 'total': '0.00', 'next_page': null}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
