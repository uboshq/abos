import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/accounts/net_worth_api.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/auth/auth_state.dart';
import '../../core/auth/auth_user.dart';
import '../../core/auth/me_features.dart';
import '../../core/auth/session_profile.dart';
import '../../core/auth/session_repository.dart';
import '../../core/menu/me_api.dart';
import '../../core/menu/menu_item.dart';
import '../../core/menu/menu_repository.dart';
import '../../core/menu/module_gate.dart';
import '../../core/navigation/nav_catalog.dart';
import '../../core/navigation/nav_prefs.dart';
import '../../core/navigation/nav_state.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/navigation/top_bar/abos_top_bar.dart';
import '../../core/navigation/top_bar/avatar_menu.dart';
import '../../core/navigation/top_bar/top_bar_context.dart';
import '../../core/orders/delivery_order_api.dart';
import '../../core/books/collection_entry.dart';
import '../../core/launcher_widgets/launcher_widget_refresh.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/privacy/phone_privacy.dart';
import '../../core/profile/face_image.dart';
import '../../core/router/profile_routes.dart';
import '../../core/records/notice_bar.dart';
import '../../core/records/notification_record.dart';
import '../../core/records/today_record.dart';
import '../../core/sync_engine/auto_sync.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/update/app_version_check.dart';
import '../../core/workspace/workspace_switcher.dart';
import '../printing/printer_settings_screen.dart';
import '../../core/privacy/device_integrity.dart';
import '../../core/privacy/lock_delay.dart';
import '../../core/locale/locale_choice.dart';
import '../update/app_update_tile.dart';
import '../update/update_gate.dart';
import 'home_dashboard.dart';
import 'module_pages.dart';
import 'nav_bottom_bar.dart';
import 'nav_search.dart';
import 'notice_ticker.dart';
import 'workspace_picker.dart';
import '../copilot/copilot_quick_sheet.dart';

/// The signed-in person's home — the owner's navigation of 12 Oct 2026:
/// *"app icon e tap korle age module asbe, module e dhukle tar menu, menu r
/// vitore kichu thakle vitore dekhabe"*, built to the mockup he approved
/// (4.10.2026/মোবাইল-নেভিগেশন-১০-স্কিনের-নমুনা.pdf, skin ১ ABOS).
///
/// <p>Three levels, never more: <b>সব মডিউল</b> (coloured tiles) → a module's
/// menu (rows under small headings) → the screen; a row with children opens
/// one short sub-list first. Under every one of them, and under every screen
/// ([ScreenFrame]), the same bar: হোম · অনুমোদন · সব মডিউল · বিজ্ঞপ্তি · আমি.
///
/// <p><b>হোম</b> — the search box, today's figures (the same [TodayPanel] as
/// before), the person's favourites and what they opened last. <b>আমি</b> —
/// who is signed in, the sync status, the update button and the way out.
///
/// <p>⚠️ Which doors show is not decided here: the tree is built from the very
/// menu the old grid drew — `/me`'s menu through [RouteRegistry], the phone's
/// own permission tiles ([MenuRepository]) and the company's phone switches
/// ([ModuleGate]) — see [NavTree.from].
///
/// <p>The header names the company on the home because docs/Contract §৮ rule
/// খ says the figures must. ⭐ Tapping it opens the company/branch picker
/// (0.4.2) — only when `/me` lists more than one company or branch.
class HomeShell extends ConsumerStatefulWidget {
  const HomeShell({
    super.key,
    this.loadHome,
    this.fetchToday,
    this.lastKnownToday,
    this.readCachedOrg,
    this.cacheOrg,
    this.checkUpdate,
    this.now,
    this.reloadProfile,
    this.switcher,
    this.fetchNotifications,
    this.fetchNoticeBar,
    this.fetchPending,
    this.netWorth,
    this.syncNow,
    this.prefs,
    this.openPath,
    this.findCustomers,
    this.customerName,
  });

  /// Seams — the real ones need a server or a secure store.
  final Future<HomeMenu> Function(AuthUser user)? loadHome;
  final Future<TodayRecord> Function()? fetchToday;
  final TodayRecord? Function()? lastKnownToday;
  final Future<OrgSnapshot?> Function()? readCachedOrg;
  final Future<void> Function(OrgSnapshot org)? cacheOrg;
  final Future<UpdateStatus> Function()? checkUpdate;
  final DateTime Function()? now;

  /// `GET /me` again, for the picker after a company change.
  final Future<SessionProfile?> Function()? reloadProfile;
  final WorkspaceSwitcher? switcher;

  /// Seam for the bell's count — the real one needs a server.
  final Future<NotificationPage> Function()? fetchNotifications;

  /// Seam for the running notice line — the real one needs a server.
  final Future<List<NoticeBarItem>> Function()? fetchNoticeBar;

  /// Seam for the pull-to-sync round — the real one needs a server.
  final Future<void> Function()? syncNow;

  /// Seam for the approval tab's count — the real one needs a server.
  final Future<ApprovalPage> Function()? fetchPending;

  /// Seam for the owner's Net Worth Statement card — the real one needs a server.
  final NetWorthApi? netWorth;

  /// Where favourites and recents are kept — the encrypted box by default.
  final NavPrefsStore? prefs;

  /// Opens `/home/<path>` — the router by default; a test records it.
  final Future<void> Function(String path)? openPath;

  /// The synced customers a search finds, and one customer's current name.
  final List<CustomerHit> Function(String query)? findCustomers;
  final String? Function(String id)? customerName;

  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell> {
  static const MenuRepository _menuRepository = MenuRepository();

  List<MenuItem> _items = const [];
  NavTree _tree = NavTree.empty();
  bool _menuLoading = true;
  OrgSnapshot? _org;
  SessionProfile? _profile;

  /// `/me`-র পদবি আর ছবি — লগইনের উত্তরে নেই, তাই আলাদা রাখা ([[HomeMenu.me]])।
  String? _designation;
  String? _avatarUrl;

  /// How many bell messages are unread — null until the server has said.
  int? _unread;

  /// ⭐ প্রিয় আর সাম্প্রতিক — এই ফোনে, এই মানুষের ([[NavPrefs]])
  NavPrefs? _prefs;
  List<String> _favourites = const [];
  List<RecentEntry> _recents = const [];

  Future<void> _loadUnread() async {
    try {
      final page = await (widget.fetchNotifications ?? NotificationApi.fetch)();
      if (mounted) {
        setState(() => _unread = page.unread);
        _publishBadges();
      }
    } catch (_) {
      // ⓘ পুরনো সার্ভার বা সিগন্যাল নেই — ঘণ্টা থাকে, কেবল গোনা ছাড়া
    }
  }

  /// Bumped after a move, so the day's figures are asked for again — they
  /// belong to the company and branch they were fetched for.
  int _generation = 0;

  /// ⭐ How many signatures wait for this person — null while unknown, and the
  /// tab stays hidden for somebody the server does not let approve.
  int? _pending;
  bool _pendingMore = false;

  Future<void> _loadPending() async {
    try {
      final page = await (widget.fetchPending ?? () => ApprovalsApi.pending())();
      if (mounted) {
        setState(() {
          _pending = page.rows.length;
          _pendingMore = page.nextCursor != null;
        });
        _publishBadges();
      }
    } catch (_) {
      // ⓘ সই করার অধিকার নেই (৪০৩), পুরনো সার্ভার বা সিগন্যাল নেই — ঘর আগের অবস্থায়
    }
  }

  /// The bar under every screen reads the same counts ([ScreenFrame]).
  void _publishBadges() {
    ref.read(navBadgesProvider.notifier).state = NavBadges(
        pending: _pending, pendingMore: _pendingMore, unread: _unread);
  }

  /// ⭐ নিচে টানলে পুরো সিঙ্ক — অ্যাপের নিজের গোল ([[AutoSync.current]]), যাতে টাইমারেরটা চলতে থাকলে একই গোল;
  /// তারপর ঘণ্টা আর অনুমোদনের গোনা। ⓘ ব্যর্থ হলে ছুড়ে দেয় — টানার জায়গা এক লাইনে বলে।
  Future<void> _syncNow() async {
    try {
      await (widget.syncNow ??
          () => AutoSync.current?.runNow() ?? AutoSync.fullRound())();
    } finally {
      if (mounted) {
        _loadUnread();
        _loadPending();
      }
    }
  }

  @override
  void initState() {
    super.initState();
    // ⓘ নতুন করে ঢুকলে হোম থেকে শুরু — আগের জনের খোলা মডিউল নয়
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _setPage(HomePage.home);
    });
    _load();
    _loadUnread();
    _loadPending();
    _loadPrefs();
    // ⭐ ভাষা বদলের পরে মেনু আবার, সার্ভারে লেখা শেষ হলে — মেনুর নাম তৈরির সময় বাঁধা ([LocaleChoices.savedOnServer])
    LocaleChoices.savedOnServer.addListener(_reloadForLanguage);
    RecentPapers.changes.addListener(_loadPrefs);
    // ⭐ root করা বা ছদ্ম-ফোন — একবার বলা, আটকানো নয় ([[DeviceIntegrity]])
    WidgetsBinding.instance.addPostFrameCallback((_) => _warnIfUnsafe());
  }

  Future<void> _warnIfUnsafe() async {
    final warning = await DeviceIntegrity.warnOnce();
    if (warning == null || !mounted) return;
    await showDialog<void>(
      context: context,
      builder: (dialog) => AlertDialog(
        key: const ValueKey('device-integrity-warning'),
        icon: Icon(Icons.gpp_maybe_outlined, color: Theme.of(dialog).colorScheme.error),
        title: Text(dialog.l10n.homeUnsafePhoneTitle),
        content: Text(warning),
        actions: [TextButton(onPressed: () => Navigator.of(dialog).pop(), child: Text(dialog.l10n.homeUnderstood))],
      ),
    );
  }

  @override
  void dispose() {
    LocaleChoices.savedOnServer.removeListener(_reloadForLanguage);
    RecentPapers.changes.removeListener(_loadPrefs);
    if (identical(RecentPapers.active, _prefs)) RecentPapers.active = null;
    super.dispose();
  }

  Future<void> _loadPrefs() async {
    final user = ref.read(authStateProvider).user;
    if (user == null) return;
    final prefs = _prefs ??=
        NavPrefs(widget.prefs ?? ref.read(navPrefsStoreProvider), user.id);
    // ⭐ কাগজের পর্দা খুললে এই তালিকাতেই লেখে ([[RecentPapers.note]])
    RecentPapers.active = prefs;
    final favourites = await prefs.favourites();
    final recents = await prefs.recents();
    if (!mounted) return;
    setState(() {
      _favourites = favourites;
      _recents = recents;
    });
  }

  void _reloadForLanguage() {
    if (mounted) _load();
  }

  Future<void> _load() async {
    final user = ref.read(authStateProvider).user;
    if (user == null) return;

    // The cached names first, so the header is right on the first frame of
    // a phone with no signal; `/me` overwrites them the moment it answers.
    final cached =
        await (widget.readCachedOrg ?? SessionRepository.instance.readOrg)();
    if (!mounted) return;
    if (cached != null && !cached.isEmpty && _org == null) {
      setState(() => _org = cached);
      _publishTopBar();
    }
    if (cached?.phoneModules != null &&
        ref.read(phoneModulesProvider) == null) {
      ref.read(phoneModulesProvider.notifier).state = cached!.phoneModules;
    }

    final home = await (widget.loadHome ?? _menuRepository.homeFor)(user);
    if (!mounted) return;
    final profile = home.profile;
    final fresh = profile == null
        ? null
        : OrgSnapshot(
            company: profile.company.name,
            companyCode: profile.company.code,
            branch: profile.viewAllBranches && profile.branches.length > 1
                ? AppLocale.l10n.homeAllBranches
                : profile.branch.name,
            phoneModules: profile.phoneModules,
          );
    if (profile?.phoneModules != null) {
      ref.read(phoneModulesProvider.notifier).state = profile!.phoneModules;
    }
    if (profile != null) {
      ref.read(ordersReplaceDoProvider.notifier).state =
          profile.ordersReplaceDo;
      ref.read(mayCollectProvider.notifier).state = profile.mayCollect;
      // ⭐ সার্ভার ছবি বদল আর "আমার ফোনগুলো" জানে কি না — মেনু আর প্রোফাইলের পর্দা এটা দেখে ([MeFeatures])
      ref.read(meFeaturesProvider.notifier).state = profile.features;
      // ⭐ মালিকের দুই সুইচ — পর্দা আড়াল, আর উইজেটের অঙ্ক; হোম আঁকা এর জন্য থামে না
      unawaited(PhonePrivacy.follow(
          secureScreens: profile.secureScreens,
          widgetAmounts: profile.widgetAmounts,
          redrawWidgets: LauncherWidgetRefresh.refresh));
    }
    final modules = ref.read(phoneModulesProvider);
    setState(() {
      // ⭐ The company's phone switches ([ModuleGate]) — `/me`'s menu is
      // already filtered, this catches this app's own tiles too.
      _items = ModuleGate.visible(home.items, modules);
      _tree = NavTree.from(_items);
      _menuLoading = false;
      if (profile != null) _profile = profile;
      if (home.me != null) {
        _designation = home.me!.designation;
        _avatarUrl = home.me!.avatarUrl;
      }
      if (fresh != null && !fresh.isEmpty) _org = fresh;
    });
    // ⭐ নিজের মুখ সবার জন্য — ছবির পর্দা এখান থেকে পড়ে, বদলালে এখানে লেখে ([[myFaceProvider]])
    if (home.me != null) ref.read(myFaceProvider.notifier).state = _avatarUrl;
    _publishTopBar();
    if (fresh != null && !fresh.isEmpty) {
      await (widget.cacheOrg ?? SessionRepository.instance.saveOrg)(fresh);
    }
  }

  /// ⭐ ভেতরের পর্দার বার ([AbosScreenBar]) একই কোম্পানি, শাখা, ছবি আর একই কাজ পায় — হোম যা জানে তা-ই, নতুন ডাক নয়
  /// ⭐ উপরের মগজ — কেবল যাঁর মেনুতে কোপাইলট আছে (সার্ভার `assistant.use` দেখে দেয়); "পুরো দেখুন" পুরো কোপাইলটের পাতায়
  Future<void> Function(BuildContext context, String page)? get _copilot => _tree.screen('copilot') == null
      ? null
      : (from, page) => openCopilotQuick(from, page: page, onFull: () => (widget.openPath ?? _push)('copilot'));

  void _publishTopBar() {
    final user = ref.read(authStateProvider).user;
    final org = _org;
    if (!mounted || user == null) return;
    final named = org != null && org.company.isNotEmpty;
    ref.read(topBarContextProvider.notifier).state = TopBarContext(
      company: named ? org.company : '',
      companyCode: named ? org.companyCode : '',
      branch: named ? org.branch : '',
      designation: _designation,
      avatar: avatarImage(_avatarUrl),
      moduleOf: (path) {
        final row = _tree.screen(path);
        return row == null ? null : _tree.module(row.module)?.label;
      },
      search: (from) => _openSearch(from: from),
      openNotifications: (_) async {
        await (widget.openPath ?? _push)('notifications');
        if (mounted) _loadUnread();
      },
      openMenu: (from, top) async {
        final me = ref.read(authStateProvider).user;
        if (!mounted || me == null) return;
        await _openAvatarMenu(top, me, _org, from: from, fromScreen: true);
      },
      switchBranch: (_profile?.canSwitch ?? false)
          ? (_) async {
              if (mounted) await _openPicker(fromScreen: true);
            }
          : null,
      copilot: _copilot,
    );
  }

  /// [fromScreen] — ভেতরের পর্দার বার থেকে ডাকা: বদলালে হোমে ফেরা, যাতে আগের শাখার অঙ্ক কোনো পর্দায় না থাকে
  Future<void> _openPicker({bool fromScreen = false}) async {
    final profile = _profile;
    if (profile == null || !profile.canSwitch) return;
    final moved = await showWorkspacePicker(
      context,
      profile: profile,
      reload: widget.reloadProfile ?? _fetchProfile,
      switcher: widget.switcher,
    );
    if (!moved || !mounted) return;
    if (fromScreen) GoRouter.maybeOf(context)?.go('/home');
    setState(() => _generation++);
    await _load();
    // ⓘ অপেক্ষার সই কোম্পানি ধরে — নতুন কোম্পানিতে আবার গোনা
    _loadPending();
  }

  /// ⭐ ছবির মেনু খোলা — ছবির চারপাশে কমলা দাগ
  bool _menuOpen = false;

  /// ⭐ ছবিতে চাপ — নিজের মেনু (মালিকের নকশা ৩ §৩, পুরো-পাতার নমুনা পাতা ২)
  /// [from] — যে পর্দার বার থেকে ডাকা (ভেতরের পর্দা হলে [fromScreen]); নইলে হোম নিজে
  Future<void> _openAvatarMenu(double top, AuthUser user, OrgSnapshot? org,
      {BuildContext? from, bool fromScreen = false}) async {
    if (!fromScreen) setState(() => _menuOpen = true);
    final choice = await showAvatarMenu(
      from ?? context,
      top: top,
      name: user.name,
      designation: _designation,
      branch: org?.branch ?? '',
      avatar: avatarImage(_avatarUrl),
      canSwitchCompany: (_profile?.companies.length ?? 0) > 1,
    );
    if (!mounted) return;
    if (!fromScreen) setState(() => _menuOpen = false);
    switch (choice) {
      case AvatarChoice.profile:
        // ⭐ "প্রোফাইল ও ছবি" — নিজের তথ্য, ছবি বদল আর "আমার ফোনগুলো" (অ্যাপ ০.৪.৪১, a6, ২০ অক্টোবর ২০২৬)
        await (widget.openPath ?? _push)(ProfileRoutes.profile);
      case AvatarChoice.company:
        if (await mayLeaveUnsaved(context)) await _openPicker(fromScreen: fromScreen);
      case AvatarChoice.lock:
        await _showLockDelaySheet(context);
      case AvatarChoice.settings:
        _setPage(HomePage.me);
        if (fromScreen) GoRouter.maybeOf(context)?.go('/home');
      case AvatarChoice.logout:
        await _confirmLogout(context, ref);
      case null:
        break;
    }
  }

  static Future<SessionProfile?> _fetchProfile() async {
    try {
      return (await MeApi.fetch()).profile;
    } catch (_) {
      return null;
    }
  }

  /// The day's payload names its company too (docs/Contract §৮). When `/me`
  /// never answered and nothing is cached, that is still a real name for the
  /// header — better than the person's own, which says nothing about whose
  /// figures are on screen.
  void _onRecord(TodayRecord record) {
    if (_org != null && !_org!.isEmpty) return;
    final company = record.company;
    if (company == null) return;
    setState(() =>
        _org = OrgSnapshot(company: company, branch: record.branch ?? ''));
    _publishTopBar();
  }

  void _setPage(HomePage page) =>
      ref.read(homePageProvider.notifier).state = page;

  /// Opens `/home/<path>` and remembers it under "সাম্প্রতিক".
  Future<void> _open(String path, String label) async {
    final prefs = _prefs;
    if (prefs != null) {
      unawaited(prefs
          .remember(RecentEntry(path: path, label: label))
          .then((_) => _loadPrefs()));
    }
    await (widget.openPath ?? _push)(path);
    if (mounted) {
      _loadPending();
      _loadUnread();
    }
  }

  Future<void> _push(String path) async {
    await context.push('/home/$path');
  }

  Future<void> _toggleFavourite(NavRow row) async {
    final prefs = _prefs;
    final path = row.path;
    if (prefs == null || path == null) return;
    final change = await prefs.toggleFavourite(path);
    await _loadPrefs();
    if (!mounted) return;
    final messenger = ScaffoldMessenger.maybeOf(context);
    messenger?.hideCurrentSnackBar();
    messenger?.showSnackBar(SnackBar(
      content: Text(switch (change) {
        FavouriteChange.added => context.l10n.homeFavouriteAdded(row.label),
        FavouriteChange.removed => context.l10n.homeFavouriteRemoved(row.label),
        FavouriteChange.full => context.l10n.homeFavouritesFull,
      }),
    ));
  }

  Future<void> _openSearch({BuildContext? from}) async {
    final pick = await showSearch<NavPick?>(
      context: from ?? context,
      delegate: NavSearchDelegate(
        tree: _tree,
        // ⓘ গ্রাহকের তালিকার দরজা যাঁর আছে, কেবল তাঁর খোঁজায় দোকান
        findCustomers: _tree.screen('customers') == null
            ? null
            : (widget.findCustomers ?? findSyncedCustomers),
      ),
    );
    if (pick != null && mounted) await _open(pick.path, pick.label);
  }

  /// The favourites this person may still open — a door the menu no longer
  /// gives is not kept on the home.
  List<NavRow> get _favouriteRows => [
        for (final path in _favourites)
          if (_tree.screen(path) case final NavRow row) row,
      ];

  /// The recents this person may still open, under today's names; a shop
  /// this phone's cache no longer knows (another company, a sign-out) is left
  /// off rather than named from memory.
  List<RecentEntry> get _recentRows {
    final out = <RecentEntry>[];
    for (final e in _recents) {
      if (e.path.startsWith('customers/')) {
        if (_tree.screen('customers') == null) continue;
        final name = (widget.customerName ?? syncedCustomerName)(
            e.path.substring('customers/'.length));
        if (name != null) out.add(RecentEntry(path: e.path, label: name));
      } else if (RecentPapers.listPathOf(e.path) case final String list) {
        // ⓘ কাগজ — তার তালিকার দরজা যতক্ষণ আছে; নাম কাগজ খোলার সময়ের
        if (_tree.screen(list) != null) out.add(e);
      } else if (_tree.screen(e.path) case final NavRow row) {
        out.add(RecentEntry(path: e.path, label: row.label));
      }
    }
    return out;
  }

  NavCounts get _counts {
    final pending = _pending ?? 0;
    final queued = SyncEngine.instance.pendingCount;
    return NavCounts(
      byModule: {if (pending > 0) 'approval': pending},
      byPath: {if (pending > 0) 'approvals': pending},
      // "N অপেক্ষমাণ" — a plain grey line, not a red count: waiting for a
      // connection is normal, not a problem (the old grid's own rule).
      quietByPath: {if (queued > 0) 'orders': AppLocale.l10n.homeQueuedCount('$queued')},
    );
  }

  @override
  Widget build(BuildContext context) {
    // ⭐ ছবির পর্দায় মুখ বদলালে বা সরালে — ওপরের বার, ছবির মেনু আর "আমি" সাথে সাথে নতুন মুখে, `/me` আবার না ডেকে
    ref.listen<String?>(myFaceProvider, (_, face) {
      if (face == _avatarUrl || !mounted) return;
      setState(() => _avatarUrl = face);
      _publishTopBar();
    });
    final user = ref.watch(authStateProvider).user;
    if (user == null) {
      // app_router.dart's redirect keeps this from being reachable
      // signed-out, but a screen must still not crash on a null it can
      // technically be handed mid-transition.
      return const Scaffold(body: SizedBox.shrink());
    }

    var page = ref.watch(homePageProvider);
    // ⓘ যে মডিউল এখন এই মানুষের নেই (কোম্পানি বদল, সুইচ বন্ধ), তার মেনু নয় — সব মডিউলে
    final module = page.module == null ? null : _tree.module(page.module!);
    if (!_menuLoading &&
        page.module != null &&
        (module == null ||
            (page.kind == HomePageKind.subList &&
                _tree.parent(page.parent!) == null))) {
      page = HomePage.modules;
    }

    final org = _org;
    final headerNamesOrg = org != null && org.company.isNotEmpty;
    final tab = switch (page.kind) {
      HomePageKind.home => NavTab.home,
      HomePageKind.me => NavTab.me,
      _ => NavTab.modules,
    };
    final counts = _counts;

    return PopScope(
      // ⭐ অ্যান্ড্রয়েডের "পিছনে" — সবসময় এক ধাপ উপরে; হোমে থাকলে তবেই অ্যাপ বন্ধ
      canPop: page.up == null,
      onPopInvokedWithResult: (didPop, _) {
        final up = page.up;
        if (!didPop && up != null) _setPage(up);
      },
      child: Scaffold(
        // ⭐ উপরের বার — মালিকের নকশা ৩ আর পুরো-পাতার নমুনা (২০ অক্টোবর ২০২৬): সব পাতায় "কোম্পানি · শাখা ▾",
        // ভেতরের পাতায় "› মডিউল › পাতা"; ডানে খোঁজা, বিজ্ঞপ্তি, নিজের ছবি ([AbosTopBar])
        appBar: AbosTopBar(
          company: headerNamesOrg ? org.company : '',
          branch: headerNamesOrg ? org.branch : '',
          companyCode: headerNamesOrg ? org.companyCode : '',
          fallbackTitle: user.name,
          crumbs: switch (page.kind) {
            HomePageKind.home => const [],
            HomePageKind.modules => [context.l10n.homeAllModules],
            HomePageKind.module => [module?.label ?? context.l10n.homeAllModules],
            HomePageKind.subList => [
                if (module != null) module.label,
                if (_tree.parent(page.parent!) case final p?) p.label,
              ],
            HomePageKind.me => [context.l10n.homeMe],
          },
          leading: switch (page.kind) {
            HomePageKind.home || HomePageKind.modules => TopBarLeading.menu,
            _ => TopBarLeading.back,
          },
          // ≡ পুরো মেনু = সব মডিউল; ← এক ধাপ উপরে (অ্যান্ড্রয়েডের "পিছনে"-র একই নিয়ম)
          onLeading: () => _setPage(page.up == null || page.kind == HomePageKind.modules
              ? HomePage.modules
              : page.up!),
          onOrgTap: (_profile?.canSwitch ?? false) ? _openPicker : null,
          onSearch: _openSearch,
          onCopilot: _copilot == null ? null : () => _copilot!(context, context.l10n.navTabHome),
          unread: _unread,
          onBell: () async {
            await (widget.openPath ?? _push)('notifications');
            if (mounted) _loadUnread();
          },
          avatarName: user.name,
          avatar: avatarImage(_avatarUrl),
          menuOpen: _menuOpen,
          onAvatar: (top) => _openAvatarMenu(top, user, org),
        ),
        // docs/Contract §৬. Wrapped here rather than around the whole app: the
        // login screen must stay reachable for somebody whose build is too old,
        // because signing in is how they find out anything at all, and a wall
        // in front of it would leave them with a blank app and no explanation.
        body: UpdateGate(
          check: widget.checkUpdate,
          child: Column(children: [
            // ⭐ ওয়েবের চলমান নোটিশ — মাথার ঠিক নিচে, প্রতিটা ট্যাবে (মালিক, ৬ অক্টোবর ২০২৬); কিছু না থাকলে চুপ।
            // ⓘ কোম্পানি বদলালে নতুন key — আগের কোম্পানির নোটিশ এক মুহূর্তও থাকে না
            NoticeTicker(
                key: ValueKey('notice-ticker-$_generation'),
                fetch: widget.fetchNoticeBar),
            Expanded(
                child: IndexedStack(
              index: tab == NavTab.home ? 0 : (tab == NavTab.me ? 2 : 1),
              children: [
                HomeDashboard(
                  key: ValueKey('home-dashboard-$_generation'),
                  user: user,
                  items: _items,
                  headerNamesOrg: headerNamesOrg,
                  fetchToday: widget.fetchToday,
                  lastKnownToday: widget.lastKnownToday,
                  onRecord: _onRecord,
                  now: widget.now,
                  // ⭐ উপর থেকে নিচে টানলে পুরো সিঙ্ক — পাঠানো আর আনা (মালিক, ১০ অক্টোবর ২০২৬)
                  onPull: _syncNow,
                  search: NavSearchBox(onTap: _openSearch),
                  favourites: _favouriteRows,
                  recents: _recentRows,
                  onOpen: _open,
                  onUnfavourite: _toggleFavourite,
                  // ⭐ মালিকের হোমের "সইয়ের অপেক্ষায়" — অনুমোদনের ঘরের একই সারি (০.৪.৪১)
                  loadWaiting: widget.fetchPending ?? () => ApprovalsApi.pending(limit: 3),
                  // ⭐ নিট সম্পদ বিবরণী — টাকার কার্ডের নিচে, কেবল মালিক (সার্ভার অন্যকে ৪০৪ দেয়)
                  netWorth: widget.netWorth ?? const ServerNetWorthApi(),
                ),
                _modulesArea(page, module, counts),
                _MoreTab(
                    user: user,
                    org: org,
                    designation: _designation,
                    avatarUrl: _avatarUrl,
                    onOpen: _open),
              ],
            )),
          ]),
        ),
        bottomNavigationBar: AbosBottomBar(
          selected: tab,
          badges: NavBadges(
              pending: _pending, pendingMore: _pendingMore, unread: _unread),
          onSelect: (selected) async {
            switch (selected) {
              case NavTab.home:
                _setPage(HomePage.home);
              case NavTab.modules:
                _setPage(HomePage.modules);
              case NavTab.me:
                _setPage(HomePage.me);
              case NavTab.approvals:
                await (widget.openPath ?? _push)('approvals');
                if (mounted) _loadPending();
              case NavTab.notifications:
                await (widget.openPath ?? _push)('notifications');
                if (mounted) _loadUnread();
            }
          },
        ),
      ),
    );
  }

  Widget _modulesArea(HomePage page, NavModule? module, NavCounts counts) {
    final favourites = _favourites.toSet();
    void onRow(NavRow row) {
      if (row.isParent) {
        _setPage(HomePage.subList(row.module, row.id));
      } else if (row.path != null) {
        _open(row.path!, row.label);
      }
    }

    if (page.kind == HomePageKind.subList && module != null) {
      final parent = _tree.parent(page.parent!);
      if (parent != null) {
        return ModuleMenuPage(
          key: ValueKey('sub-${parent.id}'),
          crumb: module.label,
          onCrumb: () => _setPage(HomePage.module(module.code)),
          groups: [NavGroup(parent.label, parent.children)],
          counts: counts,
          favourites: favourites,
          onRow: onRow,
          onFavourite: _toggleFavourite,
        );
      }
    }
    if (page.kind == HomePageKind.module && module != null) {
      return ModuleMenuPage(
        key: ValueKey('menu-${module.code}'),
        crumb: context.l10n.homeAllModules,
        onCrumb: () => _setPage(HomePage.modules),
        note: module.live
            ? null
            : context.l10n.homeModuleComingSoon(module.label),
        groups: module.groups,
        counts: counts,
        favourites: favourites,
        onRow: onRow,
        onFavourite: _toggleFavourite,
      );
    }
    return AllModulesPage(
      modules: _tree.modules,
      counts: counts,
      loading: _menuLoading,
      header: NavSearchBox(onTap: _openSearch),
      onRefresh: _syncNow,
      onOpen: (m) => _setPage(HomePage.module(m.code)),
    );
  }
}

/// "আমি" — who is signed in, where, the sync status, the update button and
/// the way out. Signing out asks first: on a shared phone one stray tap must
/// not cost somebody their session and the login that follows.
class _MoreTab extends ConsumerWidget {
  const _MoreTab(
      {required this.user,
      required this.org,
      required this.onOpen,
      this.designation,
      this.avatarUrl});

  final String? designation;
  final String? avatarUrl;

  final AuthUser user;
  final OrgSnapshot? org;
  final Future<void> Function(String path, String label) onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // ⭐ রং থিমের ভূমিকা থেকে (নকশার নিয়ম ০.৪.৩৮ §১)
    final cs = Theme.of(context).colorScheme;
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.md),
      children: [
        Card(
          child: ListTile(
            // ⭐ ছবি আর পদবি — মালিক, ২ অক্টোবর ২০২৬: "profile photo dekhay na, role nadekiye designation dekhabe"
            leading: CircleAvatar(
              key: const ValueKey('profile-avatar'),
              backgroundColor: cs.primary,
              foregroundColor: cs.onPrimary,
              foregroundImage: avatarImage(avatarUrl),
              // ⓘ ছবি না এলে (৪০৪, নেট নেই) নামের প্রথম অক্ষর — ভুলটা লাল পর্দা হয়ে ওঠে না
              onForegroundImageError: avatarUrl == null ? null : (_, __) {},
              child: Text(user.name.isEmpty ? '?' : user.name.characters.first),
            ),
            title: Text(user.name,
                style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text([
              if (designation != null) designation!,
              if (user.email.isNotEmpty) user.email,
            ].join('\n')),
            isThreeLine: designation != null && user.email.isNotEmpty,
          ),
        ),
        if (org != null && !org!.isEmpty) ...[
          const SizedBox(height: AppSpacing.sm),
          Card(
            child: ListTile(
              leading: const Icon(Icons.business_outlined),
              title: Text(org!.company),
              subtitle: org!.branch.isEmpty ? null : Text(org!.branch),
            ),
          ),
        ],
        const SizedBox(height: AppSpacing.sm),
        Card(
          child: ListTile(
            key: const ValueKey('me-sync-status'),
            leading: const Icon(Icons.sync_outlined),
            title: Text(context.l10n.homeSyncStatus),
            subtitle: Text(context.l10n.homeSyncStatusHint),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => onOpen('sync-status', context.l10n.homeSyncStatus),
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        // ⭐ প্রিন্টার — থার্মাল (ব্লুটুথ) আর নরমাল (মালিক, ১৪ অক্টোবর ২০২৬)
        Card(
          child: ListTile(
            key: const ValueKey('me-printer'),
            leading: const Icon(Icons.print_outlined),
            title: Text(context.l10n.homePrinter),
            subtitle: Text(context.l10n.homePrinterHint),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
                builder: (_) => const PrinterSettingsScreen())),
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        // ⭐ অ্যাপ-তালার সময় — কতক্ষণ বাইরে থাকলে ফিরতে আঙুলের ছাপ বা PIN (অ্যাপের নিরাপত্তা, ১৯ অক্টোবর ২০২৬; [[LockDelay]])
        const _LockDelayTile(),
        // ⓘ আলো বা অন্ধকার এখন ছবির মেনুতে (মালিকের নকশা ৩ §৩, ২০ অক্টোবর ২০২৬; [[AvatarMenu]])
        const SizedBox(height: AppSpacing.sm),
        // ⭐ অ্যাপ হালনাগাদ — হাতে যাচাই (মালিক, ৭ অক্টোবর ২০২৬)
        const AppUpdateTile(),
        const SizedBox(height: AppSpacing.lg),
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(
            foregroundColor: cs.error,
            side: BorderSide(color: cs.error),
            padding: const EdgeInsets.symmetric(vertical: 14),
          ),
          onPressed: () => _confirmLogout(context, ref),
          icon: const Icon(Icons.logout),
          label: Text(context.l10n.homeSignOut),
        ),
      ],
    );
  }
}

/// বেরোনোর আগে জিজ্ঞেস — শেয়ার করা ফোনে এক ভুল চাপে কারও সেশন না যায় ("আমি" পাতা আর ছবির মেনু দুটোই)
Future<void> _confirmLogout(BuildContext context, WidgetRef ref) async {
  final pending = SyncEngine.instance.pendingCount;
  final yes = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(context.l10n.homeSignOutTitle),
      content: Text(pending > 0 ? context.l10n.homeSignOutPending(pending) : context.l10n.homeSignOutPassword),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: Text(context.l10n.homeStay),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(true),
          child: Text(context.l10n.homeSignOut),
        ),
      ],
    ),
  );
  if (yes == true) {
    await ref.read(authStateProvider.notifier).logout();
  }
}

/// অ্যাপ-তালার সময় বাছাই — "আমি" ট্যাবে; চারটা পছন্দ, "কখনো না" নেই ([[LockDelay]])
class _LockDelayTile extends StatelessWidget {
  const _LockDelayTile();

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<Duration>(
      valueListenable: LockDelay.instance.current,
      builder: (context, delay, _) => Card(
        child: ListTile(
          key: const ValueKey('me-lock-delay'),
          leading: const Icon(Icons.lock_clock_outlined),
          title: Text(context.l10n.homeLockDelay),
          subtitle: Text(context.l10n.homeLockDelayHint(LockDelay.label(delay.inMinutes))),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => _showLockDelaySheet(context),
        ),
      ),
    );
  }
}

/// অ্যাপ-তালার সময়ের চার পছন্দ — "আমি" পাতা আর ছবির মেনুর "পিন ও আঙুলের ছাপ" দুটোই খোলে ([[LockDelay]])
Future<void> _showLockDelaySheet(BuildContext context) {
  final delay = LockDelay.instance.current.value;
  return showModalBottomSheet<void>(
    context: context,
    builder: (sheet) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ListTile(title: Text(sheet.l10n.homeLockDelay, style: const TextStyle(fontWeight: FontWeight.w700))),
          for (final minutes in LockDelay.choices)
            ListTile(
              key: ValueKey('lock-delay-$minutes'),
              leading: Icon(delay.inMinutes == minutes ? Icons.radio_button_checked : Icons.radio_button_unchecked),
              title: Text(LockDelay.label(minutes)),
              onTap: () async {
                await LockDelay.instance.choose(minutes);
                if (sheet.mounted) Navigator.of(sheet).pop();
              },
            ),
        ],
      ),
    ),
  );
}

/// ⭐ মানুষের ছবি — যত ছোট দেখায় তত ছোট করে খোলা (১২০ পিক্সেল, ৪০dp গোলের ৩ গুণ)। ⛔ নইলে ফোনের ৪০০০ পিক্সেলের ছবি
/// পুরোটা মেমরিতে খুলত (প্রায় ৪৮ MB), কম-RAM ফোনে টানতে আটকাত (Android vitals; পরিকল্পনা ৪, অংশ ২; ২০ অক্টোবর ২০২৬)।
///
/// <p>⛔ ছবির দরজা টোকেন চায় (a6, ২০ অক্টোবর ২০২৬) — তাই [FaceImage]: অ্যাপের নিজের ক্লায়েন্টে, `Authorization: Bearer`,
/// লাইভের pinning আর ৪০১-এ নবায়নসহ; সাধারণ `NetworkImage` এর কোনোটাই করে না।
ImageProvider? avatarImage(String? url) => url == null ? null : ResizeImage(FaceImage(url), width: 120, policy: ResizeImagePolicy.fit);
