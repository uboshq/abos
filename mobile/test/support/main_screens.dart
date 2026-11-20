import 'package:flutter/material.dart';

import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/orders/order_api.dart';
import 'package:abos_mobile/core/printing/printer_settings.dart';
import 'package:abos_mobile/core/printing/thermal_printer.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/features/auth/login_screen.dart';
import 'package:abos_mobile/features/books/collect_screen.dart';
import 'package:abos_mobile/features/customers/customer_detail_screen.dart';
import 'package:abos_mobile/features/customers/customer_ledger_screen.dart';
import 'package:abos_mobile/features/customers/customer_list_screen.dart';
import 'package:abos_mobile/features/home/home_shell.dart';
import 'package:abos_mobile/features/orders/new_order_screen.dart';
import 'package:abos_mobile/features/printing/printer_settings_screen.dart';

import '../fakes/fake_bluetooth_port.dart';

/// ⭐ অ্যাপের মূল পর্দাগুলো, পরীক্ষার জন্য একই জায়গা থেকে — TalkBack-এর নির্দেশিকা, বড় লেখার ছবি আর ট্যাবলেটের পরীক্ষা
/// এগুলোই খোলে (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)। ⓘ সব নকল সার্ভার; কোনো নেটওয়ার্ক নয়।
const shopId = 'c-0001';
const shop2Id = 'c-0002';
const soapId = 'p-soap';

/// গ্রাহক, বকেয়া, পণ্য আর দুইটা অর্ডার — রেফারেন্স-জমায় (হাইভ চালু থাকতে হবে)।
Future<void> seedMainData() async {
  final now = DateTime(2026, 10, 1, 9, 30);
  final c = ReferenceCache.instance;
  await c.clearAll();
  await c.put(entityType: 'Customer', entityId: shopId, updatedAt: now, payload: const {
    'id': shopId,
    'code': 'CUS-0001',
    'nameBn': 'ভাই ভাই এন্টারপ্রাইজ অ্যান্ড জেনারেল স্টোর',
    'ownerName': 'আব্দুর রহিম',
    'phone': '01711000001',
    'pointName': 'ফুলপুর বাজার',
    'addressBn': 'মিরপুর ১০, ঢাকা',
    'isActive': true,
  });
  await c.put(entityType: 'Customer', entityId: shop2Id, updatedAt: now, payload: const {
    'id': shop2Id,
    'code': 'CUS-0002',
    'nameBn': 'রহিম স্টোর',
    'phone': '01711000002',
    'addressBn': 'নেত্রকোনা',
    'isActive': true,
  });
  await c.put(entityType: 'CustomerDue', entityId: shopId, updatedAt: now, payload: const {
    'customerId': shopId,
    'outstanding': '1248125.5000',
    'creditLimit': '1500000.0000',
    'creditDays': 15,
  });
  await c.put(entityType: 'Product', entityId: soapId, updatedAt: now, payload: const {
    'id': soapId,
    'code': 'SOAP100',
    'nameBn': 'সাবান ১০০ গ্রাম',
    'salePrice': '35.0000',
    'packs': [
      {'unitNameBn': 'কার্টন', 'factor': '48'},
    ],
    'isActive': true,
  });
  await c.put(entityType: 'SalesOrder', entityId: 'o1', updatedAt: now, payload: const {
    'id': 'o1',
    'documentNo': 'SO-2610-0001',
    'customerId': shopId,
    'trxDate': '2026-10-01',
    'status': 'confirmed',
    'total': '125400.0000',
  });
}

/// যে মাপে পর্দা আঁকা — dp-তে, ঘনত্ব ১।
void setScreenSize(WidgetTester tester, Size size) {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

/// ⓘ অন্ধকার মোডের পরীক্ষার জন্য (a4, ২০ অক্টোবর ২০২৬) — true হলে [appFrame] অ্যাপের নিজের দুই থিম পরে, অন্ধকারে; নইলে আগের মতো।
bool appFrameInDark = false;

/// ⓘ ছবি-টেস্টের জন্য — true হলে আলোতেও অ্যাপের নিজের থিম (নইলে Flutter-এর সাধারণ থিম, আগের মতো)
bool appFrameAppTheme = false;

/// ⓘ আসল ফন্টের পরীক্ষার জন্য (fe, ২২ অক্টোবর ২০২৬) — দিলে [appFrame] অ্যাপের আলোর থিম এটা দিয়ে মুড়ে পরে (যেমন realFontTheme)
ThemeData Function(ThemeData)? appFrameFont;

/// ⓘ রূপের ছবি-টেস্টের জন্য (০.৪.৬০) — দিলে [appFrame] পুরো মোড়ক এটাকে দেয় (Liquid Glass / Material 3-এর থিম আর কাচের পট);
/// না দিলে আগের মতো
Widget Function(Widget child)? appFrameOverride;

/// [child]-কে অ্যাপের মতো মোড়ে — লেখার মাপ [textScale]।
Widget appFrame(Widget child, {double textScale = 1}) =>
    appFrameOverride != null ? appFrameOverride!(child) : _appFrame(child, textScale: textScale);

Widget _appFrame(Widget child, {double textScale = 1}) => MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: appFrameFont != null
          ? appFrameFont!(AppTheme.light())
          : appFrameInDark || appFrameAppTheme
              ? AppTheme.light()
              : null,
      darkTheme: appFrameInDark || appFrameAppTheme ? AppTheme.dark() : null,
      themeMode: appFrameInDark ? ThemeMode.dark : ThemeMode.system,
      builder: (context, inner) => MediaQuery(
        data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(textScale)),
        child: inner!,
      ),
      home: child,
    );

class _SignedOut extends AuthController {
  @override
  Future<void> login({required String identifier, required String password, String? code}) async {}
}

class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

const _user = AuthUser(
  id: 'u1',
  name: 'করিম উদ্দিন আহমেদ চৌধুরী',
  email: 'karim.uddin@testco.local',
  roles: ['Manager'],
  permissions: ['sales.order.view', 'customer.view', 'sales.order.create'],
);

Widget loginScreen({double textScale = 1}) => ProviderScope(
      overrides: [authStateProvider.overrideWith((ref) => _SignedOut())],
      child: appFrame(
        LoginScreen(
          now: () => DateTime.utc(2026, 10, 1, 4),
          readRemembered: () async => null,
          saveRemembered: (_) async {},
        ),
        textScale: textScale,
      ),
    );

const _today = TodayRecord({
  'date': '2026-10-01',
  'company': 'টেষ্ট কোম্পানী লিমিটেড',
  'branch': 'প্রধান কার্যালয়',
  'asOf': '2026-10-01T10:04:11+06:00',
  'sales': {'count': 12, 'amount': '4545200.0000'},
  'collections': {'count': 7, 'amount': '3131000.0000'},
  'dues': {'amount': '81812500.0000', 'shops': 41},
  'approvals': {'pending': 3},
});

const _menu = HomeMenu(
  items: [
    MenuItem(key: 'sales.order.create', label: 'নতুন অর্ডার', icon: Icons.add_shopping_cart_outlined, routeName: 'new-order'),
    MenuItem(key: 'customer.index', label: 'গ্রাহক', icon: Icons.people_alt_outlined, routeName: 'customers'),
    MenuItem(key: 'collections', label: 'টাকা আদায়', icon: Icons.payments_outlined, routeName: 'collections'),
    MenuItem(key: 'sync_status', label: 'সিঙ্কের অবস্থা', icon: Icons.sync_outlined, routeName: 'sync-status'),
  ],
  profile: SessionProfile(
    company: OrgRef(publicId: 'c1', code: 'TCL', name: 'টেষ্ট কোম্পানী লিমিটেড'),
    branch: OrgRef(publicId: 'b1', code: 'HO', name: 'প্রধান কার্যালয়'),
    locale: 'bn',
  ),
);

Widget homeShell({double textScale = 1}) => ProviderScope(
      overrides: [authStateProvider.overrideWith((ref) => _SignedIn(_user))],
      child: appFrame(
        HomeShell(
          loadHome: (_) async => _menu,
          fetchToday: () async => _today,
          lastKnownToday: () => null,
          readCachedOrg: () async => null,
          cacheOrg: (_) async {},
          checkUpdate: () async => UpdateStatus.fine,
          now: () => DateTime(2026, 10, 1, 10, 30),
          fetchNoticeBar: () async => const [],
          fetchPending: () async => throw StateError('not an approver'),
          syncNow: () async {},
          prefs: MemoryNavPrefsStore(),
          openPath: (_) async {},
        ),
        textScale: textScale,
      ),
    );

Widget customerList({double textScale = 1}) => appFrame(const CustomerListScreen(), textScale: textScale);

Widget customerDetail({double textScale = 1}) =>
    appFrame(CustomerDetailScreen(customerId: shopId, refresh: () async => null), textScale: textScale);

class _Ledger implements CustomerLedgerApi {
  @override
  Future<CustomerLedger> ledger(String customerId, {int page = 1}) async => const CustomerLedger(
        name: 'ভাই ভাই এন্টারপ্রাইজ অ্যান্ড জেনারেল স্টোর',
        code: 'CUS-0001',
        due: 1248125.5,
        rows: [
          CustomerLedgerRow(date: '2026-10-06', no: 'RCV-0002', debit: 0, credit: 150000, balance: 1248125.5),
          CustomerLedgerRow(
              date: '2026-10-02',
              no: 'S-0041',
              narration: 'চাল ১০ বস্তা, ডাল ২০ কেজি আর সয়াবিন তেল ৫ লিটারের ১২ বোতল',
              debit: 1398125.5,
              credit: 0,
              balance: 1398125.5),
        ],
        nextPage: 2,
      );
}

Widget customerLedger({double textScale = 1}) =>
    appFrame(CustomerLedgerScreen(customerId: shopId, api: _Ledger()), textScale: textScale);

class _Orders implements OrderApi {
  @override
  Future<List<OfferLine>> offers(String customerId, List<OfferAsk> lines) async => const [];

  @override
  Future<CustomerStanding> standing(String customerId, double orderTotal) async =>
      throw StateError('offline');
}

class _Memory extends OrderMemory {
  @override
  Map<String, int> lastFor(String customerId) => const {};

  @override
  Future<void> remember(String customerId, Map<String, int> lines) async {}
}

Widget newOrder({double textScale = 1}) => appFrame(
      NewOrderScreen(
        api: _Orders(),
        memory: _Memory(),
        offerDelay: const Duration(milliseconds: 10),
        enqueue: (_) async {},
      ),
      textScale: textScale,
    );

class _Entry implements CollectionEntryApi {
  @override
  Future<CollectionSetup> setup() async => const CollectionSetup(accounts: [
        CollectionAccount(id: 'acc-cash', name: 'প্রধান নগদ', kind: 'cash'),
        CollectionAccount(id: 'acc-bkash', name: 'bKash মার্চেন্ট', kind: 'mfs'),
      ]);

  @override
  List<CustomerRecord> customers() =>
      const [CustomerRecord({'id': shopId, 'nameBn': 'ভাই ভাই এন্টারপ্রাইজ', 'pointName': 'ফুলপুর বাজার'})];

  @override
  Future<OpenBills?> openBills(String customerId) async => null;

  @override
  Future<CollectionPreview?> preview(CollectionDraft draft) async => null;

  @override
  Future<PushOutcome> send(CollectionDraft draft, String changeId) async => const PushOutcome(landedId: 'x');

  @override
  Future<String?> attachSlip(String collectionId, String path) async => null;
}

class _Books implements BooksApi {
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

var _seq = 0;

Widget collect({double textScale = 1}) => appFrame(
      CollectScreen(
        api: _Entry(),
        books: _Books(),
        newChangeId: () => 'k-${_seq++}',
        today: DateTime(2026, 10, 7),
      ),
      textScale: textScale,
    );

Widget printerSettings({double textScale = 1}) => appFrame(
      PrinterSettingsScreen(
        store: MemoryPrinterSettingsStore(const PrinterSettings(permissionExplained: true)),
        thermal: ThermalPrinting(
            port: FakeBluetoothPort(printers: const [BluetoothPrinter(name: 'POS-58', address: 'AA:BB')])),
      ),
      textScale: textScale,
    );

/// SyncEngine-কে [NewOrderScreen]-এর জন্য চালু রাখা।
Future<void> initSyncEngine() => SyncEngine.instance.init();
