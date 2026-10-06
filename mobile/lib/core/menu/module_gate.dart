import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../widgets/empty_state.dart';
import 'menu_item.dart';

/// Which modules this company has switched on for the phone — `GET /me`'s
/// `phoneModules`, set by the home screen each time `/me` answers (and from
/// the last answer kept on the phone while offline).
///
/// <p>Null means "not known": a server older than the switch, or a phone
/// that has never reached `/me`. Nothing is hidden then — the server's own
/// walls (403 `module_off` on sync, reports, papers and approvals) still
/// hold, so the phone hiding a tile is convenience, never the protection.
final phoneModulesProvider = StateProvider<Set<String>?>((ref) => null);

/// One switch per module, per company, on the server (Control Panel →
/// "মোবাইল অ্যাপ"). `/me`'s menu is already filtered by it; this class is
/// the same rule for what the menu cannot carry — this app's own tiles
/// (নতুন অর্ডার, হাজিরা, বকেয়া তালিকা …) and every deep link into a
/// screen, including a home-screen widget's tap.
class ModuleGate {
  const ModuleGate._();

  /// The module behind each `/home/<segment>` — the server's module code,
  /// the prefix of the route name that screen stands for
  /// (`sales.order.index` → `sales`).
  ///
  /// <p>A segment not listed here belongs to no module and is never hidden
  /// (the sync status is a fact about the phone; the reports list is
  /// already filtered by the server, report by report).
  static const Map<String, String> moduleOfPath = {
    'approvals': 'approval',
    'attendance': 'hr',
    'customers': 'customer',
    'dues': 'customer',
    'orders': 'sales',
    'new-order': 'sales',
    'scan': 'sales',
    'tracking': 'sales',
    'my-route': 'sales',
    'leads': 'sales',
    'quotations': 'sales',
    'delivery-orders': 'sales',
    'counter': 'sales',
    'collections': 'sales',
    'principals': 'purchase',
    'purchases': 'purchase',
    'today': 'sales',
    'products': 'inventory',
    'stock': 'inventory',
  };

  /// The message a switched-off screen shows — the owner's own words.
  static const String offTitle = 'এই অংশটা এখন বন্ধ';
  static const String offMessage =
      'আপনার কোম্পানি অ্যাপে এই অংশটা বন্ধ রেখেছে। দরকার হলে প্রশাসককে '
      'বলুন কন্ট্রোল প্যানেলের "মোবাইল অ্যাপ" ট্যাব থেকে চালু করতে।';

  /// Whether the screen at [appPath] may open, given [on].
  static bool allows(Set<String>? on, String appPath) {
    if (on == null) return true;
    final segment = appPath.split('/').first;
    final module = moduleOfPath[segment];
    return module == null || on.contains(module);
  }

  /// The tiles that may show — a "coming soon" tile has no path and is left
  /// to the server's own menu filter.
  static List<MenuItem> visible(List<MenuItem> items, Set<String>? on) => items
      .where((item) => item.planned || allows(on, item.routeName))
      .toList(growable: false);
}

/// Wraps one routed screen: the screen itself when its module is on, the
/// "বন্ধ" page when it is not. Sitting on the route, not on the tile, is the
/// point: a widget tap or a saved link never passes through a tile.
class ModuleGateView extends ConsumerWidget {
  const ModuleGateView({super.key, required this.path, required this.child});

  final String path;
  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final on = ref.watch(phoneModulesProvider);
    if (ModuleGate.allows(on, path)) return child;
    return Scaffold(
      appBar: AppBar(title: const Text(ModuleGate.offTitle)),
      body: const EmptyState(
        icon: Icons.block_outlined,
        title: ModuleGate.offTitle,
        message: ModuleGate.offMessage,
      ),
    );
  }
}
