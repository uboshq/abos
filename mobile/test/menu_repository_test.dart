import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_module.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';

/// `GET /me` cannot be reached from a test run (there is no server, and
/// deliberately no attempt to fake Dio here) — `MenuRepository.menuFor`
/// therefore always exercises its offline fallback in this suite, run with
/// `--dart-define=ABOS_API_BASE_URL=http://127.0.0.1:1/api/v1` (an address
/// nothing listens on) so that failure is immediate rather than a real
/// 15-second connect timeout against the actual server. That fallback is
/// itself permission-filtered the same way the live path is — see
/// menu_repository.dart's own class comment — so this is a real test of the
/// filtering rule, not a workaround around one.
void main() {
  test('menu draws only what permissions and the route registry both allow',
      () async {
    const repository = MenuRepository();

    const salesman = AuthUser(
      id: '1',
      name: 'Salesman',
      email: 'sales@abos.test',
      roles: ['salesman'],
      permissions: ['customer.view', 'sales.order.view'],
    );

    final items = await repository.menuFor(salesman);
    final keys = items.map((item) => item.key).toSet();

    // Present: what the permissions above allow.
    expect(keys, contains('customer.index'));
    expect(keys, contains('sales.order.index'));
    // Every role gets this regardless of permissions — a device fact, not a
    // business one.
    expect(keys, contains('sync_status'));

    // Absent: permissions this user does not have.
    expect(keys, isNot(contains('inventory.product.index')));
    expect(keys, isNot(contains('inventory.stock.index')));
    // Absent: "নতুন অর্ডার" is synthesized only from sales.order.create,
    // which this user does not hold.
    expect(keys, isNot(contains('sales.order.create')));
  });

  test('sales.order.create adds the New Order tile, not a menu row',
      () async {
    const repository = MenuRepository();

    const salesmanWhoCanCreate = AuthUser(
      id: '1',
      name: 'Salesman',
      email: 'sales@abos.test',
      roles: ['salesman'],
      permissions: [
        'customer.view',
        'sales.order.view',
        'sales.order.create',
      ],
    );

    final items = await repository.menuFor(salesmanWhoCanCreate);
    final newOrderTile =
        items.where((item) => item.key == 'sales.order.create');

    expect(newOrderTile, hasLength(1));
    expect(newOrderTile.single.routeName, 'new-order');
    expect(newOrderTile.single.planned, isFalse);
  });

  test('a user with no matching permissions gets only the sync-status tile',
      () async {
    const repository = MenuRepository();

    const noPermissions = AuthUser(
      id: '1',
      name: 'Nobody',
      email: 'nobody@abos.test',
      roles: [],
      permissions: [],
    );

    final items = await repository.menuFor(noPermissions);
    expect(items.map((item) => item.key), ['sync_status']);
  });


  group('a "coming soon" row is shown, not dropped', () {
    const repository = MenuRepository();

    test('a planned row becomes a tile even with no screen behind it', () {
      // This is the case that could not previously happen. Every row was put
      // through the RouteRegistry filter first, and a planned row has no path
      // by definition — so the dimmed tile home_shell.dart had already built,
      // with its "শীঘ্রই আসছে" label, was unreachable code.
      const entry = MenuRouteEntry(
        label: 'রেস্টুরেন্ট',
        route: 'restaurant.index',
        planned: true,
      );

      final tile = repository.tileFor(entry);

      expect(tile, isNotNull);
      expect(tile!.planned, isTrue);
      expect(tile.label, 'রেস্টুরেন্ট');
      expect(tile.routeName, isEmpty,
          reason: 'a planned tile is inert — home_shell passes a null onTap, '
              'so no path is ever built from this');
    });

    test('a live row this build has no screen for is still dropped', () {
      // The other "no screen", and it must not end the same way: the server
      // has the screen, this build has not caught up. Drawing "শীঘ্রই আসছে"
      // there would tell the person a lie about the system.
      const entry = MenuRouteEntry(
        label: 'আদায়',
        route: 'sales.collection.index',
        planned: false,
      );

      expect(repository.tileFor(entry), isNull);
    });

    test('a live row with a screen becomes an ordinary tile', () {
      const entry = MenuRouteEntry(
        label: 'গ্রাহক',
        route: 'customer.index',
        planned: false,
      );

      final tile = repository.tileFor(entry);

      expect(tile, isNotNull);
      expect(tile!.planned, isFalse);
      expect(tile.routeName, 'customers');
    });
  });
}
