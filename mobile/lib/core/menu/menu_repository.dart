import 'package:flutter/material.dart';

import '../auth/auth_user.dart';
import 'me_api.dart';
import 'menu_item.dart';
import 'menu_module.dart';
import 'route_registry.dart';

/// Where the signed-in person's home menu comes from: `GET /me`, filtered
/// twice before a row becomes a tile —
///
///  1. by the server, against that person's permissions (already done by the
///     time this file sees the response);
///  2. by [RouteRegistry], against what **this app build** actually has a
///     screen for — see that file's own doc comment for why the server
///     cannot do this filtering itself.
///
/// <p><b>"নতুন অর্ডার" is not one of `/me`'s menu rows</b> — the
/// coordinating session confirmed 4 September that placing an order is an
/// action inside the order list, gated by the `sales.order.create`
/// permission directly, not a navigable menu entry. This file adds that tile
/// itself, from the same `permissions` list every other check already reads,
/// rather than waiting for a menu row that will never arrive.
///
/// <p>Offline, `GET /me` cannot be called at all, and a blank home screen the
/// one time someone opens the app with no signal is worse than a slightly
/// stale menu — so [menuFor] falls back to [_localFallback], built from the
/// same `permissions` list already saved on this device by the last
/// successful login (see `SessionRepository`). The fallback is
/// permission-filtered the identical way, so it degrades to "the same menu,
/// no icons any fresher than the last sign-in" rather than to nothing.
class MenuRepository {
  const MenuRepository();

  static const _newOrderPermission = 'sales.order.create';

  /// One icon per app path segment — `/me` sends a label for every row but
  /// no icon, so the icon is this app's own, keyed by the path it already
  /// resolved the row to rather than by the server's route name (one path
  /// can only ever want one icon; the server's naming is not this app's
  /// concern here).
  static const Map<String, IconData> _iconByAppPath = {
    'approvals': Icons.fact_check_outlined,
    'customers': Icons.people_alt_outlined,
    'products': Icons.inventory_2_outlined,
    'stock': Icons.warehouse_outlined,
    'orders': Icons.receipt_long_outlined,
  };

  Future<List<MenuItem>> menuFor(AuthUser user) async {
    try {
      final response = await MeApi.fetch();
      final items = <MenuItem>[];
      for (final module in response.menu) {
        for (final entry in module.allEntries) {
          final tile = tileFor(entry);
          if (tile != null) items.add(tile);
        }
      }
      return _withSyntheticTiles(items, response.user);
    } catch (_) {
      return _withSyntheticTiles(_localFallback(user), user);
    }
  }

  /// One `/me` menu row turned into a tile, or null when it has no place on
  /// this build's home grid.
  ///
  /// <p><b>Two different "there is no screen", and they must not end the same
  /// way.</b>
  ///
  /// <p>The server says `planned` — the *system* does not have the thing yet,
  /// on the web either. That row is shown dimmed and inert, never omitted,
  /// which is the web side's own convention and the reason `MeController`
  /// sends the flag at all. Hiding it would let a person conclude the feature
  /// is not coming and ask the office for it again; showing it greyed says
  /// "planned, not yet" in the one place they are already looking.
  ///
  /// <p>The route is live but [RouteRegistry] has no path for it — the
  /// *server* has the screen and this *build* has not caught up. That row is
  /// dropped silently, which is correct rather than a gap; see
  /// RouteRegistry's own doc comment.
  ///
  /// <p>Until this method existed the first case could never happen: every
  /// row went through the RouteRegistry filter first, and a planned row has
  /// no path by definition, so all twenty-two of them were dropped before the
  /// dimmed tile that home_shell.dart had already built for them could ever
  /// draw. The tile, the flag, the parsing and the "শীঘ্রই আসছে" label were
  /// all in place and unreachable.
  @visibleForTesting
  MenuItem? tileFor(MenuRouteEntry entry) {
    if (entry.planned) {
      return MenuItem(
        key: entry.route,
        label: entry.label,
        // No path was resolved and none is wanted — the tile is inert. The
        // clock is this app's own choice for "not yet", not a server icon.
        icon: Icons.schedule_outlined,
        routeName: '',
        planned: true,
      );
    }

    final appPath = RouteRegistry.appPathFor(entry.route);
    if (appPath == null) return null;

    return MenuItem(
      key: entry.route,
      label: entry.label,
      icon: _iconByAppPath[appPath] ?? Icons.circle_outlined,
      routeName: appPath,
    );
  }

  List<MenuItem> _localFallback(AuthUser user) {
    final items = <MenuItem>[];
    const localLabels = {
      'customer.index': ('গ্রাহক', 'customer.view', Icons.people_alt_outlined),
      'sales.order.index': (
        'অর্ডারের অবস্থা',
        'sales.order.view',
        Icons.receipt_long_outlined
      ),
      'inventory.product.index': (
        'পণ্যের তালিকা',
        'inventory.product.view',
        Icons.inventory_2_outlined
      ),
      'inventory.stock.index': (
        'হাতে থাকা মজুদ',
        'inventory.stock.view',
        Icons.warehouse_outlined
      ),
    };
    localLabels.forEach((serverRoute, tuple) {
      final (label, permission, icon) = tuple;
      final appPath = RouteRegistry.appPathFor(serverRoute);
      if (appPath == null || !user.can(permission)) return;
      items.add(MenuItem(key: serverRoute, label: label, icon: icon, routeName: appPath));
    });
    return items;
  }

  /// Tiles that are not `/me` menu rows at all — see this class's own doc
  /// comment for "নতুন অর্ডার", and [_withSyntheticTiles]'s trailing comment
  /// for the sync-status tile every role gets regardless.
  List<MenuItem> _withSyntheticTiles(List<MenuItem> items, AuthUser user) => [
        ...items,
        if (user.can(_newOrderPermission))
          const MenuItem(
            key: 'sales.order.create',
            label: 'নতুন অর্ডার',
            icon: Icons.add_shopping_cart_outlined,
            routeName: 'new-order',
          ),
        // What this device has and has not sent is a fact about the phone,
        // not a business permission — every signed-in role can open it, live
        // menu or fallback alike.
        const MenuItem(
          key: 'sync_status',
          label: 'সিঙ্কের অবস্থা',
          icon: Icons.sync_outlined,
          routeName: 'sync-status',
        ),
      ];
}
