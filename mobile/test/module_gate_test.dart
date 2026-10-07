import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/features/home/home_shell.dart';

/// The owner, 1 October 2026: the app carries every module's menu, and a
/// switch per module — per company, on the server — decides what shows.
///
/// <p>The server filters `/me`'s menu and refuses a switched-off module's
/// doors itself (403 `module_off`). These claims are the phone's half: its
/// own tiles and every deep link meet the same switch, so a hidden module is
/// not one saved link or widget tap away.

const _attendance = MenuItem(
  key: 'hr.attendance.self',
  label: 'হাজিরা',
  icon: Icons.how_to_reg_outlined,
  routeName: 'attendance',
);
const _newOrder = MenuItem(
  key: 'sales.order.create',
  label: 'নতুন অর্ডার',
  icon: Icons.add_shopping_cart_outlined,
  routeName: 'new-order',
);
const _approvals = MenuItem(
  key: 'approval.inbox.index',
  label: 'অনুমোদন',
  icon: Icons.fact_check_outlined,
  routeName: 'approvals',
);
const _syncStatus = MenuItem(
  key: 'sync_status',
  label: 'সিঙ্কের অবস্থা',
  icon: Icons.sync_outlined,
  routeName: 'sync-status',
);
const _planned = MenuItem(
  key: 'finance.cheque.index',
  label: 'চেক',
  icon: Icons.schedule_outlined,
  routeName: '',
  planned: true,
);

class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

void main() {
  group('ModuleGate', () {
    test('a module that is off has no tile; the phone\'s own tiles stay', () {
      final shown = ModuleGate.visible(
        [_attendance, _newOrder, _approvals, _syncStatus, _planned],
        {'sales', 'approval'},
      );

      expect(shown.map((i) => i.key), [
        'sales.order.create',
        'approval.inbox.index',
        'sync_status',
        'finance.cheque.index',
      ]);
    });

    test('not known yet (an older server): nothing is hidden here', () {
      expect(ModuleGate.visible([_attendance, _newOrder], null), hasLength(2));
      expect(ModuleGate.allows(null, 'attendance'), isTrue);
    });

    test('a deep link below a screen meets its screen\'s switch', () {
      expect(ModuleGate.allows({'sales'}, 'customers/abc-123'), isFalse);
      expect(ModuleGate.allows({'customer'}, 'customers/abc-123'), isTrue);
    });
  });

  group('ModuleGateView', () {
    Widget gated(Set<String>? on) => ProviderScope(
          overrides: [phoneModulesProvider.overrideWith((ref) => on)],
          child: const MaterialApp(
            home: ModuleGateView(path: 'attendance', child: Text('ATTENDANCE SCREEN')),
          ),
        );

    testWidgets('switched off: "এই অংশটা এখন বন্ধ", never the screen', (tester) async {
      await tester.pumpWidget(gated({'sales'}));

      expect(find.text('ATTENDANCE SCREEN'), findsNothing);
      expect(find.text('এই অংশটা এখন বন্ধ'), findsWidgets);
    });

    testWidgets('switched on: the screen itself', (tester) async {
      await tester.pumpWidget(gated({'hr'}));

      expect(find.text('ATTENDANCE SCREEN'), findsOneWidget);
      expect(find.text('এই অংশটা এখন বন্ধ'), findsNothing);
    });
  });

  /// ⛔ The gate sits on the route, not the tile — a widget tap or a saved
  /// link never passes a tile. Read from the router's own source, because
  /// the real router builds the whole home shell with its network behind it.
  test('every screen under /home is behind the gate, except the sync status', () {
    final source = File('lib/core/router/app_router.dart').readAsStringSync();
    final home = source.substring(source.indexOf("path: '/home'"));
    final paths = RegExp(r"path: '([a-z\-:]+)'")
        .allMatches(home)
        .map((m) => m.group(1)!)
        .where((p) => p != ':id')
        .toList();

    expect(paths, containsAll(ModuleGate.moduleOfPath.keys));
    for (final path in ModuleGate.moduleOfPath.keys) {
      expect(RegExp("ModuleGateView\\(\\s*path: '$path'").hasMatch(source), isTrue,
          reason: '/home/$path must not open without its module switch');
    }
    expect(RegExp(r"path: 'customers'").hasMatch(home), isTrue);
    expect(source, contains("path: 'customers',\n                  child: CustomerDetailScreen"),
        reason: 'a customer opened by its id is still the customer module');
  });

  testWidgets('the home screen hides a tile whose module /me says is off', (tester) async {
    const user = AuthUser(
      id: 'u1',
      name: 'করিম',
      email: 'karim@testco.local',
      roles: ['salesman'],
      permissions: ['sales.order.create', 'hr.attendance.self'],
    );
    const profile = SessionProfile(
      company: OrgRef(publicId: 'c1', code: 'TDEPOT', name: 'ট্রেড ডিপো'),
      branch: OrgRef(publicId: 'b1', code: 'MMS', name: 'প্রধান ময়মনসিংহ'),
      locale: 'bn',
      phoneModules: {'sales', 'approval'},
    );

    await tester.pumpWidget(ProviderScope(
      overrides: [authStateProvider.overrideWith((ref) => _SignedIn(user))],
      child: MaterialApp(
        home: HomeShell(
          loadHome: (_) async => const HomeMenu(
            items: [_newOrder, _attendance, _syncStatus],
            profile: profile,
          ),
          fetchToday: () async => const TodayRecord({}),
          lastKnownToday: () => null,
          readCachedOrg: () async => null,
          cacheOrg: (_) async {},
          checkUpdate: () async => UpdateStatus.fine,
          now: () => DateTime(2026, 10, 1, 10),
        ),
      ),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('অ্যাপ'));
    await tester.pumpAndSettle();

    expect(find.text('নতুন অর্ডার'), findsWidgets);
    expect(find.text('সিঙ্কের অবস্থা'), findsWidgets);
    expect(find.text('হাজিরা'), findsNothing, reason: 'hr is off for this company\'s phones');
  });
}
