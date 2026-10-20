// ⓘ নিচের বারের টেস্টের কাঠামো — আসল রাউটারের একই গড়ন (`/home` আর তার নিচে পর্দা, প্রতিটা ScreenFrame-এ মোড়া),
// কিন্তু পর্দাগুলো সাদামাটা, নেট ছাড়া।
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/state_views.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';

class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

/// চার ভূমিকার মানুষ — কেবল চাবি আলাদা, রোলের নাম ইচ্ছে করে সবার এক ("Staff"): ভূমিকা চাবি থেকে ঠিক হয়।
const Map<String, List<String>> rolePermissions = {
  'owner': [
    'executive.view', 'approval.decide', 'sales.order.view', 'sales.order.create', 'customer.view',
    'accounts.voucher.create', 'sales.collection.view', 'sales.challan.view', 'inventory.stock.view', 'hr.claim.self',
  ],
  'sr': ['sales.order.view', 'sales.order.create', 'sales.collection.view', 'sales.collection.create', 'customer.view'],
  'depot': ['sales.challan.view', 'inventory.stock.view'],
  'accounts': ['accounts.voucher.create', 'approval.decide', 'sales.collection.view'],
};

AuthUser userFor(String role, {List<String>? permissions}) => AuthUser(
      id: 'u-$role',
      name: 'করিম',
      email: '',
      roles: const ['Staff'],
      permissions: permissions ?? rolePermissions[role]!,
    );

/// একটা সাদামাটা তালিকার পর্দা — শিরোনাম আর ৬০টা সারি (ওপরে ফেরার পরীক্ষার জন্য)
Widget stubScreen(String title, {bool loading = false}) => Scaffold(
      appBar: AppBar(title: Text(title)),
      body: loading
          ? const SingleChildScrollView(child: LoadingSkeleton())
          : ListView.builder(
              key: ValueKey('list-$title'),
              itemCount: 60,
              itemBuilder: (_, i) => SizedBox(height: 56, child: Text('$title row $i')),
            ),
    );

/// হোম — [HomeShell]-এর মতোই: কোন পাতা তা দেখায়, আর তার পিছনের নিয়ম (সব মডিউল → হোম, হোমে বন্ধ)
class StubHome extends ConsumerWidget {
  const StubHome({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final page = ref.watch(homePageProvider);
    return PopScope(
      canPop: page.up == null,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && page.up != null) ref.read(homePageProvider.notifier).state = page.up!;
      },
      child: stubScreen('HOME ${page.kind.name}'),
    );
  }
}

GoRouter barRouter({String initial = '/home', Set<String> loading = const {}}) {
  GoRoute framed(String path, String title, {List<RouteBase> routes = const []}) => GoRoute(
        path: path,
        routes: routes,
        builder: (context, state) => ScreenFrame(
          location: state.matchedLocation,
          child: stubScreen(title, loading: loading.contains(path)),
        ),
      );
  return GoRouter(initialLocation: initial, routes: [
    GoRoute(
      path: '/home',
      builder: (context, state) => ScreenFrame(location: state.matchedLocation, child: const StubHome()),
      routes: [
        framed('approvals', 'APPROVALS'),
        framed('reports', 'REPORTS'),
        framed('orders', 'ORDERS'),
        framed('new-order', 'NEW ORDER'),
        framed('collections', 'COLLECTIONS'),
        framed('challans', 'CHALLANS'),
        framed('stock', 'STOCK'),
        framed('vouchers', 'VOUCHERS'),
        framed('vouchers/:view', 'VOUCHER KIND'),
        framed('claims', 'CLAIMS'),
        framed('customers', 'CUSTOMERS'),
        framed('notifications', 'NOTIFICATIONS'),
        framed('sync-status', 'SYNC'),
        framed('paper/:kind/:id', 'PAPER'),
        framed('web/:code', 'WEB'),
      ],
    ),
  ]);
}

Widget barApp(
  GoRouter router, {
  required String role,
  List<String>? permissions,
  NavBadges badges = const NavBadges(),
  Set<String>? modulesOn,
  bool mayCollect = false,
  bool dark = false,
  double textScale = 1,
  ThemeData Function(ThemeData)? themed,
}) {
  ThemeData t(ThemeData x) => themed == null ? x : themed(x);
  return ProviderScope(
    overrides: [
      authStateProvider.overrideWith((ref) => _SignedIn(userFor(role, permissions: permissions))),
      navBadgesProvider.overrideWith((ref) => badges),
      mayCollectProvider.overrideWith((ref) => mayCollect),
      if (modulesOn != null) phoneModulesProvider.overrideWith((ref) => modulesOn),
    ],
    child: MaterialApp.router(
      debugShowCheckedModeBanner: false,
      theme: t(AppTheme.light()),
      darkTheme: t(AppTheme.dark()),
      themeMode: dark ? ThemeMode.dark : ThemeMode.light,
      routerConfig: router,
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(textScale)),
        child: child!,
      ),
    ),
  );
}

/// ফোনের মাপ (dp), ঘনত্ব ১ — 320, 360, 412 বা ট্যাবলেট
Future<void> setScreen(WidgetTester tester, Size size, {double ratio = 1}) async {
  tester.view.physicalSize = size * ratio;
  tester.view.devicePixelRatio = ratio;
  addTearDown(tester.view.reset);
}

/// প্ল্যাটফর্মের ডাক — কাঁপুনি আর অ্যাপ বন্ধ
List<MethodCall> recordPlatform(WidgetTester tester) {
  final calls = <MethodCall>[];
  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
    calls.add(call);
    return null;
  });
  addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));
  return calls;
}

// ── আসল ফন্ট (ছবি-টেস্ট): লাতিনে Roboto, বাংলায় Shonar Bangla, আইকনে Material Symbols ──

const _material = 'C:/tools/flutter/bin/cache/artifacts/material_fonts';

Future<void> _load(String family, List<String> files) async {
  final loader = FontLoader(family);
  for (final f in files) {
    final bytes = File(f).readAsBytesSync();
    loader.addFont(Future.value(ByteData.view(Uint8List.fromList(bytes).buffer)));
  }
  await loader.load();
}

String _packageRoot(String name) {
  final config = jsonDecode(File('.dart_tool/package_config.json').readAsStringSync()) as Map<String, dynamic>;
  final entry = (config['packages'] as List).cast<Map<String, dynamic>>().firstWhere((p) => p['name'] == name);
  return Uri.parse(entry['rootUri'] as String).toFilePath();
}

Future<void> loadRealFonts() async {
  await _load('Roboto', ['$_material/roboto-regular.ttf', '$_material/roboto-medium.ttf', '$_material/roboto-bold.ttf']);
  await _load('Bangla', ['C:/Windows/Fonts/Shonar.ttf', 'C:/Windows/Fonts/Shonarb.ttf']);
  await _load('MaterialIcons', ['$_material/materialicons-regular.otf']);
  final symbols = _packageRoot('material_symbols_icons');
  await _load('packages/material_symbols_icons/MaterialSymbolsOutlined', ['$symbols/lib/fonts/MaterialSymbolsOutlined.ttf']);
}

ThemeData realFontTheme(ThemeData theme) => theme.copyWith(
      textTheme: theme.textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla']),
      primaryTextTheme: theme.primaryTextTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla']),
    );
