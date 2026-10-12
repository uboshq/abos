import 'package:flutter/material.dart';

import '../menu/menu_item.dart';
import '../theme/app_colors.dart';

/// ⭐ মডিউল → মেনু → পর্দা — অ্যাপের নতুন পথ (মালিক, ১২ অক্টোবর ২০২৬: "app icon e tap korle age module
/// asbe, module e dhukle tar menu, menu r vitore kichu thakle vitore dekhabe")।
///
/// <p><b>Which doors a person sees is not decided here.</b> The tree is built
/// from the very list the old grid drew — `/me`'s menu through
/// [RouteRegistry], the phone's own permission-keyed tiles
/// ([MenuRepository]), and the company's phone switches ([ModuleGate]) — so a
/// tile or a row exists only when that list already let the person open the
/// screen. This file only says *where* each door hangs: which module, under
/// which heading, with which words a search should find it.
///
/// <p>Three levels and never more: all modules → a module's menu → the screen;
/// a row with children ([NavRow.children]) opens one short sub-list first.

/// One module tile — the web's own module codes and rail colours.
class NavModuleDef {
  const NavModuleDef(this.code, this.label, this.icon, [this.aliases = const []]);

  final String code;
  final String label;
  final IconData icon;
  final List<String> aliases;

  Color get color => AppColors.moduleColor(code);
}

/// Where one `/home/<path>` screen hangs, and the words that find it.
class NavScreenDef {
  const NavScreenDef({
    required this.path,
    required this.module,
    required this.group,
    required this.label,
    required this.icon,
    this.aliases = const [],
    this.parent,
  });

  final String path;
  final String module;
  final String group;

  /// Used when the menu item carries no label of its own.
  final String label;
  final IconData icon;
  final List<String> aliases;

  /// The [NavParentDef.id] this screen sits under, when it is one of several
  /// close relatives gathered behind one row.
  final String? parent;
}

/// A menu row that opens a short sub-list rather than a screen.
class NavParentDef {
  const NavParentDef({
    required this.id,
    required this.module,
    required this.group,
    required this.label,
    required this.icon,
  });

  final String id;
  final String module;
  final String group;
  final String label;
  final IconData icon;
}

/// One row of a module's menu — a screen ([path] set), a parent with
/// [children], or a "coming soon" row ([planned]).
class NavRow {
  const NavRow({
    required this.id,
    required this.label,
    required this.icon,
    required this.module,
    this.path,
    this.children = const [],
    this.planned = false,
    this.aliases = const [],
  });

  /// The path for a screen, `group:<id>` for a parent, the server route for a
  /// planned row — unique within the tree.
  final String id;
  final String label;
  final IconData icon;
  final String module;

  /// The `/home/<path>` this row opens; null for a parent or a planned row.
  final String? path;
  final List<NavRow> children;
  final bool planned;
  final List<String> aliases;

  bool get isParent => children.isNotEmpty;
}

class NavGroup {
  const NavGroup(this.title, this.rows);

  final String title;
  final List<NavRow> rows;
}

class NavModule {
  const NavModule({required this.def, required this.groups});

  final NavModuleDef def;
  final List<NavGroup> groups;

  String get code => def.code;
  String get label => def.label;
  Color get color => def.color;

  /// Every screen this module opens, children included — never a planned row.
  Iterable<NavRow> get screens sync* {
    for (final group in groups) {
      for (final row in group.rows) {
        if (row.path != null) yield row;
        yield* row.children.where((c) => c.path != null);
      }
    }
  }
}

class NavCatalog {
  const NavCatalog._();

  static const String plannedGroup = 'শীঘ্রই আসছে';

  /// The order the tiles stand in — the owner's mockup (skin 1, picture 2).
  static const List<NavModuleDef> modules = [
    NavModuleDef('sales', 'বিক্রি', Icons.shopping_cart_outlined,
        ['বিক্রয়', 'sales', 'sale']),
    NavModuleDef('purchase', 'ক্রয়', Icons.archive_outlined,
        ['কেনা', 'purchase']),
    NavModuleDef('inventory', 'মজুদ', Icons.view_in_ar_outlined,
        ['স্টক', 'inventory', 'stock']),
    NavModuleDef('accounts', 'হিসাব', Icons.description_outlined,
        ['accounts', 'হিসাব']),
    NavModuleDef('finance', 'অর্থ', Icons.account_balance_outlined,
        ['finance', 'ব্যাংক']),
    NavModuleDef('customer', 'গ্রাহক', Icons.person_outline,
        ['customer', 'দোকান']),
    NavModuleDef('supplier', 'সরবরাহকারী', Icons.local_shipping_outlined,
        ['supplier', 'প্রিন্সিপাল']),
    NavModuleDef('approval', 'অনুমোদন', Icons.check_rounded,
        ['approval', 'সই']),
    NavModuleDef('hr', 'কর্মী', Icons.people_outline, ['hr', 'staff']),
    NavModuleDef('reports', 'রিপোর্ট', Icons.bar_chart_rounded,
        ['report', 'reports']),
    NavModuleDef('documents', 'নথি', Icons.folder_outlined,
        ['documents', 'ডকুমেন্ট']),
    NavModuleDef('executive', 'মালিকের কেন্দ্র', Icons.speed_outlined,
        ['executive', 'command']),
  ];

  /// The order the headings stand in, inside any module.
  static const List<String> groupOrder = [
    'লেনদেন',
    'মাঠের কাজ',
    'তালিকা',
    'নিজের কাজ',
    'রিপোর্ট',
    'ড্যাশবোর্ড',
    'অন্যান্য',
    plannedGroup,
  ];

  static const List<NavParentDef> parents = [
    NavParentDef(
      id: 'delivery',
      module: 'sales',
      group: 'লেনদেন',
      label: 'ডেলিভারি ও লোডিং',
      icon: Icons.local_shipping_outlined,
    ),
  ];

  /// ⚠️ Every `/home/<path>` the router has a screen for must stand here —
  /// `test/nav_catalog_test.dart` reads the router's source and holds the two
  /// together, so a new screen cannot be added and forgotten by the menu.
  static const List<NavScreenDef> screens = [
    // ── বিক্রি ──
    NavScreenDef(
        path: 'new-order',
        module: 'sales',
        group: 'লেনদেন',
        label: 'নতুন অর্ডার',
        icon: Icons.add_shopping_cart_outlined,
        aliases: ['অর্ডার', 'order', 'new order', 'so']),
    NavScreenDef(
        path: 'orders',
        module: 'sales',
        group: 'লেনদেন',
        label: 'অর্ডারের অবস্থা',
        icon: Icons.receipt_long_outlined,
        aliases: ['অর্ডার', 'order', 'orders', 'so', 'বিক্রয় আদেশ']),
    NavScreenDef(
        path: 'delivery-orders',
        module: 'sales',
        group: 'লেনদেন',
        label: 'ডেলিভারি অর্ডার',
        icon: Icons.assignment_outlined,
        aliases: ['do', 'ডিও', 'delivery order', 'অর্ডার', 'আদেশ']),
    NavScreenDef(
        path: 'counter',
        module: 'sales',
        group: 'লেনদেন',
        label: 'সরাসরি বিক্রয়',
        icon: Icons.point_of_sale_outlined,
        aliases: ['কাউন্টার', 'counter', 'direct sale', 'বিক্রি', 'চালান', 'বিল', 'invoice']),
    NavScreenDef(
        path: 'quotations',
        module: 'sales',
        group: 'লেনদেন',
        label: 'উদ্ধৃতি',
        icon: Icons.request_quote_outlined,
        aliases: ['quotation', 'কোটেশন', 'দাম']),
    NavScreenDef(
        path: 'collections',
        module: 'sales',
        group: 'লেনদেন',
        label: 'টাকা আদায়',
        icon: Icons.payments_outlined,
        aliases: ['আদায়', 'collection', 'টাকা', 'জমা', 'money', 'receipt']),
    NavScreenDef(
        path: 'deliveries',
        module: 'sales',
        group: 'লেনদেন',
        parent: 'delivery',
        label: 'আজকের ডেলিভারি',
        icon: Icons.local_shipping_outlined,
        aliases: ['চালান', 'challan', 'delivery', 'ডেলিভারি']),
    NavScreenDef(
        path: 'loading',
        module: 'sales',
        group: 'লেনদেন',
        parent: 'delivery',
        label: 'লোডিং শিট',
        icon: Icons.inventory_2_outlined,
        aliases: ['loading', 'লোড', 'trip', 'ট্রিপ']),
    NavScreenDef(
        path: 'tracking',
        module: 'sales',
        group: 'লেনদেন',
        parent: 'delivery',
        label: 'ডেলিভারি ট্র্যাকিং',
        icon: Icons.near_me_outlined,
        aliases: ['tracking', 'ট্র্যাক', 'চালান', 'ডেলিভারি']),
    NavScreenDef(
        path: 'scan',
        module: 'sales',
        group: 'লেনদেন',
        parent: 'delivery',
        label: 'কাগজ স্ক্যান',
        icon: Icons.qr_code_scanner_outlined,
        aliases: ['scan', 'qr', 'স্ক্যান', 'চালান', 'কাগজ']),
    NavScreenDef(
        path: 'my-route',
        module: 'sales',
        group: 'মাঠের কাজ',
        label: 'আজকের রুট',
        icon: Icons.route_outlined,
        aliases: ['route', 'রুট', 'দোকান']),
    NavScreenDef(
        path: 'leads',
        module: 'sales',
        group: 'মাঠের কাজ',
        label: 'লিড',
        icon: Icons.person_search_outlined,
        aliases: ['lead', 'নতুন দোকান']),
    NavScreenDef(
        path: 'today',
        module: 'sales',
        group: 'রিপোর্ট',
        label: 'আজকের হিসাব',
        icon: Icons.insights_outlined,
        aliases: ['today', 'আজ', 'বিক্রি', 'হিসাব']),
    // ── ক্রয় ──
    NavScreenDef(
        path: 'purchases',
        module: 'purchase',
        group: 'লেনদেন',
        label: 'ক্রয়',
        icon: Icons.shopping_cart_outlined,
        aliases: ['purchase', 'কেনা', 'বিল', 'grn', 'মাল গ্রহণ']),
    // ── সরবরাহকারী ──
    NavScreenDef(
        path: 'principals',
        module: 'supplier',
        group: 'তালিকা',
        label: 'প্রিন্সিপাল',
        icon: Icons.business_outlined,
        aliases: ['principal', 'supplier', 'সরবরাহকারী', 'কোম্পানি']),
    // ── মজুদ ──
    NavScreenDef(
        path: 'products',
        module: 'inventory',
        group: 'তালিকা',
        label: 'পণ্যের তালিকা',
        icon: Icons.inventory_2_outlined,
        aliases: ['product', 'পণ্য', 'মাল', 'দাম', 'price']),
    NavScreenDef(
        path: 'stock',
        module: 'inventory',
        group: 'তালিকা',
        label: 'হাতে থাকা মজুদ',
        icon: Icons.warehouse_outlined,
        aliases: ['stock', 'মজুদ', 'স্টক']),
    // ── হিসাব ──
    NavScreenDef(
        path: 'vouchers',
        module: 'accounts',
        group: 'লেনদেন',
        label: 'ভাউচার',
        icon: Icons.receipt_outlined,
        aliases: ['voucher', 'খরচ', 'হিসাব']),
    // ── গ্রাহক ──
    NavScreenDef(
        path: 'customers',
        module: 'customer',
        group: 'তালিকা',
        label: 'গ্রাহক',
        icon: Icons.people_alt_outlined,
        aliases: ['customer', 'দোকান', 'shop', 'dealer', 'ডিলার']),
    NavScreenDef(
        path: 'dues',
        module: 'customer',
        group: 'রিপোর্ট',
        label: 'বকেয়া তালিকা',
        icon: Icons.account_balance_wallet_outlined,
        aliases: ['due', 'dues', 'বাকি', 'বকেয়া', 'পাওনা']),
    // ── অনুমোদন ──
    NavScreenDef(
        path: 'approvals',
        module: 'approval',
        group: 'লেনদেন',
        label: 'অনুমোদন',
        icon: Icons.fact_check_outlined,
        aliases: ['approval', 'সই', 'অপেক্ষা']),
    // ── কর্মী ──
    NavScreenDef(
        path: 'attendance',
        module: 'hr',
        group: 'নিজের কাজ',
        label: 'হাজিরা',
        icon: Icons.how_to_reg_outlined,
        aliases: ['attendance', 'উপস্থিতি']),
    NavScreenDef(
        path: 'claims',
        module: 'hr',
        group: 'নিজের কাজ',
        label: 'খরচের দাবি',
        icon: Icons.request_quote_outlined,
        aliases: ['claim', 'অগ্রিম', 'advance', 'খরচ']),
    // ── রিপোর্ট ──
    NavScreenDef(
        path: 'reports',
        module: 'reports',
        group: 'রিপোর্ট',
        label: 'রিপোর্ট',
        icon: Icons.bar_chart_outlined,
        aliases: ['report', 'রিপোর্ট']),
    NavScreenDef(
        path: 'dashboards',
        module: 'reports',
        group: 'ড্যাশবোর্ড',
        label: 'ব্যবসার ড্যাশবোর্ড',
        icon: Icons.insights_outlined,
        aliases: ['dashboard', 'ড্যাশবোর্ড']),
  ];

  /// Screens that hang on no module: the bottom bar and the "আমি" page reach
  /// them; the search still finds them.
  static const List<NavScreenDef> outside = [
    NavScreenDef(
        path: 'sync-status',
        module: '',
        group: '',
        label: 'সিঙ্কের অবস্থা',
        icon: Icons.sync_outlined,
        aliases: ['sync', 'সিঙ্ক', 'পাঠানো']),
    NavScreenDef(
        path: 'notifications',
        module: '',
        group: '',
        label: 'বিজ্ঞপ্তি',
        icon: Icons.notifications_outlined,
        aliases: ['notification', 'নোটিফিকেশন', 'বার্তা']),
  ];

  static NavModuleDef? moduleDef(String code) {
    for (final m in modules) {
      if (m.code == code) return m;
    }
    return null;
  }

  static NavScreenDef? screenDef(String path) {
    for (final s in screens) {
      if (s.path == path) return s;
    }
    for (final s in outside) {
      if (s.path == path) return s;
    }
    return null;
  }

  /// The module a server route name belongs to — its first segment, with the
  /// web's few plural/alias spellings folded in.
  static String? moduleOfRoute(String route) {
    final head = route.split('.').first;
    const aliases = {'approvals': 'approval', 'report': 'reports', 'customers': 'customer'};
    final code = aliases[head] ?? head;
    return moduleDef(code) == null ? null : code;
  }
}

/// The tree one person sees — built from the menu items the home screen
/// already holds (permission-filtered by `/me` and [MenuRepository], switch-
/// filtered by [ModuleGate]).
class NavTree {
  NavTree._(this.modules, this._extra);

  final List<NavModule> modules;
  final List<NavRow> _extra;

  static NavTree empty() => NavTree._(const [], const []);

  /// [items] is the person's menu; [always] are paths every signed-in person
  /// may open whatever the menu says — the dashboards list (the server filters
  /// it itself, and the old home always offered it), the sync status and the
  /// bell, none of which sit behind a permission or a module switch.
  factory NavTree.from(List<MenuItem> items,
      {List<String> always = const ['dashboards', 'sync-status', 'notifications']}) {
    final rowsByModule = <String, Map<String, List<NavRow>>>{};
    final childrenByParent = <String, List<NavRow>>{};
    final extra = <NavRow>[];
    final seen = <String>{};

    void place(String module, String group, NavRow row) {
      rowsByModule
          .putIfAbsent(module, () => {})
          .putIfAbsent(group, () => [])
          .add(row);
    }

    final live = <({String path, String? label})>[
      for (final item in items)
        if (!item.planned && item.routeName.isNotEmpty)
          (path: item.routeName, label: item.label),
      for (final path in always) (path: path, label: null),
    ];

    // Catalogue order inside a group, not arrival order — the same screen
    // stands in the same place for everybody.
    int rank(String path) {
      final i = NavCatalog.screens.indexWhere((s) => s.path == path);
      return i < 0 ? 1 << 20 : i;
    }

    live.sort((a, b) => rank(a.path).compareTo(rank(b.path)));

    for (final entry in live) {
      if (!seen.add(entry.path)) continue;
      final def = NavCatalog.screenDef(entry.path);
      final label = (entry.label?.trim().isNotEmpty ?? false)
          ? entry.label!.trim()
          : def?.label ?? entry.path;
      if (def == null || def.module.isEmpty) {
        // ⓘ The sync status and the bell live outside the modules; an unknown
        // path (a build that added a screen and not its place) still has a
        // door — under "অন্যান্য" in reports — rather than none.
        final row = NavRow(
          id: entry.path,
          label: label,
          icon: def?.icon ?? Icons.circle_outlined,
          module: def == null ? 'reports' : '',
          path: entry.path,
          aliases: def?.aliases ?? const [],
        );
        if (def == null) {
          place('reports', 'অন্যান্য', row);
        } else {
          extra.add(row);
        }
        continue;
      }
      final row = NavRow(
        id: def.path,
        label: label,
        icon: def.icon,
        module: def.module,
        path: def.path,
        aliases: [def.label, ...def.aliases],
      );
      if (def.parent != null) {
        childrenByParent.putIfAbsent(def.parent!, () => []).add(row);
      } else {
        place(def.module, def.group, row);
      }
    }

    // A parent with one child is no parent — the child stands in its place.
    for (final parent in NavCatalog.parents) {
      final children = childrenByParent[parent.id] ?? const [];
      if (children.isEmpty) continue;
      place(
          parent.module,
          parent.group,
          children.length == 1
              ? children.single
              : NavRow(
                  id: 'group:${parent.id}',
                  label: parent.label,
                  icon: parent.icon,
                  module: parent.module,
                  children: children,
                ));
    }

    // "শীঘ্রই আসছে" — dimmed rows at the bottom of a module that has live
    // doors; a module with nothing live gets no tile at all.
    for (final item in items.where((i) => i.planned)) {
      final module = NavCatalog.moduleOfRoute(item.key);
      if (module == null || !rowsByModule.containsKey(module)) continue;
      place(
          module,
          NavCatalog.plannedGroup,
          NavRow(
            id: item.key,
            label: item.label,
            icon: Icons.schedule_outlined,
            module: module,
            planned: true,
          ));
    }

    final modules = <NavModule>[];
    for (final def in NavCatalog.modules) {
      final groups = rowsByModule[def.code];
      if (groups == null) continue;
      final ordered = groups.keys.toList()
        ..sort((a, b) {
          int at(String g) {
            final i = NavCatalog.groupOrder.indexOf(g);
            return i < 0 ? NavCatalog.groupOrder.length : i;
          }

          return at(a).compareTo(at(b));
        });
      modules.add(NavModule(def: def, groups: [
        for (final g in ordered) NavGroup(g, groups[g]!),
      ]));
    }
    return NavTree._(modules, extra);
  }

  NavModule? module(String code) {
    for (final m in modules) {
      if (m.code == code) return m;
    }
    return null;
  }

  /// A row with children, by its id.
  NavRow? parent(String id) {
    for (final m in modules) {
      for (final g in m.groups) {
        for (final r in g.rows) {
          if (r.id == id) return r;
        }
      }
    }
    return null;
  }

  /// Every screen this person may open — module screens first, then the
  /// ones outside the modules.
  List<NavRow> get screens => [
        for (final m in modules) ...m.screens,
        ..._extra,
      ];

  /// The screen at [path], or null when this person has no door to it.
  NavRow? screen(String path) {
    for (final row in screens) {
      if (row.path == path) return row;
    }
    return null;
  }

  /// Screens whose name — Bengali or English, the module's too — holds every
  /// word of [query]. Ranked: a name that starts with the query first.
  List<NavRow> search(String query) {
    final words = _norm(query).split(' ').where((w) => w.isNotEmpty).toList();
    if (words.isEmpty) return const [];
    final hits = <(int, int, NavRow)>[];
    var order = 0;
    for (final row in screens) {
      final moduleDef = NavCatalog.moduleDef(row.module);
      final hay = _norm([
        row.label,
        ...row.aliases,
        if (moduleDef != null) ...[moduleDef.label, ...moduleDef.aliases],
      ].join(' | '));
      if (!words.every(hay.contains)) {
        order++;
        continue;
      }
      final label = _norm(row.label);
      final score = label.startsWith(words.first)
          ? 0
          : label.contains(words.first)
              ? 1
              : 2;
      hits.add((score, order++, row));
    }
    hits.sort((a, b) =>
        a.$1 != b.$1 ? a.$1.compareTo(b.$1) : a.$2.compareTo(b.$2));
    return [for (final h in hits) h.$3];
  }

  static String _norm(String s) =>
      s.toLowerCase().replaceAll(RegExp(r'\s+'), ' ').trim();
}
