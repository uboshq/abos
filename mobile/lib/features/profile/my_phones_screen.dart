import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/auth/auth_state.dart';
import '../../core/auth/me_features.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/profile/profile_api.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/day_part.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/state_views.dart';
import '../books/books_widgets.dart' show MoreButton;
import '../../core/locale/l10n.dart';
import '../../core/locale/app_locale.dart';

/// ⭐ "আমার ফোনগুলো" — কোন কোন ফোনে আমি ঢোকা, আর একটা থেকে দূরে বসে বের করে দেওয়া (চুরি যাওয়া বা পুরনো ফোন; ছবির মেনু,
/// মালিকের অনুমোদিত নমুনা পাতা ২; অ্যাপ ০.৪.৪১, a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>প্রতিটা সারি: ফোনের নাম (সার্ভার বানায় — "Android ফোন · 0.4.40 · #AB12"), "শেষ ব্যবহার: 20/10/2026 সকাল 10:00" (অঙ্ক
/// ইংরেজিতে, শব্দ বাংলায় — অ্যাপের নিয়ম [banglaStamp]), এই ফোন হলে "এই ফোন" চিহ্ন, আর "বের করে দিন" — আগে ফোনের নাম ধরে
/// জিজ্ঞেস। ২০৪ → সারি সরে; এই ফোনটাই হলে অ্যাপের নিজের বের-হওয়া ([AuthController.signOutHere]) — লগইন পাতায়। ৪০৪ → ফোনটা
/// আগেই বের হয়ে গেছে: তালিকা নতুন করে, বাংলায় জানিয়ে।
///
/// <p>⭐ পাতা ভাগ: সার্ভার ২০টা করে দেয় (`next_page`); শেষে "আরও দেখুন"। ⓘ পুরনো সার্ভার (`/me`-র `my_phones` false, বা
/// তালিকার দরজা ৪০৪) → "এখনো এই সার্ভারে নেই"।
class MyPhonesScreen extends ConsumerStatefulWidget {
  const MyPhonesScreen({super.key, this.api = const ServerProfileApi(), this.signOutHere});

  final ProfileApi api;

  /// এই ফোন বের হলে — না দিলে অ্যাপের নিজের ([AuthController.signOutHere])
  final Future<void> Function()? signOutHere;

  static String get title => AppLocale.l10n.profilePhonesTitle;

  @override
  ConsumerState<MyPhonesScreen> createState() => _MyPhonesScreenState();
}

class _MyPhonesScreenState extends ConsumerState<MyPhonesScreen> {
  final List<MyPhone> _rows = [];
  int? _next;
  bool _busy = false;
  bool _loaded = false;
  bool _old = false;
  String? _error;

  /// এই মুহূর্তে যে ফোনটা বের করা হচ্ছে — তার বোতাম বন্ধ, দুবার চাপা যায় না
  String? _leaving;

  @override
  void initState() {
    super.initState();
    // ⭐ `/me` বলেছে সার্ভার "আমার ফোনগুলো" জানে না → ডাক ছাড়াই "এখনো এই সার্ভারে নেই"। ⓘ `/me` এখনো না এলে (ঠান্ডা শুরুতে সরাসরি
    // লিংক) তালিকা চাওয়াই শেষ পাহারা — ৪০৪ হলে একই কথা
    final features = ref.read(meFeaturesProvider);
    if (features != null && !features.myPhones) {
      _old = true;
    } else {
      _load();
    }
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.phones(more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        // ⓘ একই ফোন দুই পাতায় (মাঝে কেউ ঢুকলে সাজানো সরে) — একবারই
        final seen = {for (final r in _rows) r.id};
        _rows.addAll(page.rows.where((r) => seen.add(r.id)));
        _next = page.nextPage;
        _loaded = true;
        _old = false;
      });
    } on ProfileDoorsMissing {
      if (mounted) setState(() => _old = true);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.profilePhonesLoadFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _signOut(MyPhone phone) async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        key: const ValueKey('phone-out-confirm'),
        icon: const Icon(Symbols.logout),
        title: Text(phone.current ? dialog.l10n.profilePhonesSignOutHereTitle : dialog.l10n.profilePhonesSignOutOtherTitle),
        content: Text(
          phone.current
              ? dialog.l10n.profilePhonesSignOutHereBody(phone.name)
              : dialog.l10n.profilePhonesSignOutOtherBody(phone.name),
        ),
        actions: [
          TextButton(
            key: const ValueKey('phone-out-no'),
            onPressed: () => Navigator.of(dialog).pop(false),
            child: Text(dialog.l10n.profilePhonesKeep),
          ),
          FilledButton(
            key: const ValueKey('phone-out-yes'),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(dialog).colorScheme.error,
              foregroundColor: Theme.of(dialog).colorScheme.onError,
            ),
            onPressed: () => Navigator.of(dialog).pop(true),
            child: Text(dialog.l10n.profilePhonesSignOut),
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    setState(() => _leaving = phone.id);
    try {
      final done = await widget.api.signOutPhone(phone.id);
      if (!mounted) return;
      if (!done) {
        _say(context.l10n.profilePhonesAlreadyOut(phone.name));
        await _load();
        return;
      }
      setState(() => _rows.removeWhere((r) => r.id == phone.id));
      if (phone.current) {
        // ⛔ সার্ভার এই ফোনের টোকেন মুছে দিয়েছে — অ্যাপ নিজেও বের হয়, নইলে পরের ডাকেই ৪০১ আর মাঝপথে আটকানো পর্দা
        await (widget.signOutHere ?? () => ref.read(authStateProvider.notifier).signOutHere())();
        return;
      }
      _say(context.l10n.profilePhonesSignedOut(phone.name));
    } catch (e) {
      _say(errorMessageFor(e, fallback: context.l10n.profilePhonesSignOutFailed));
    } finally {
      if (mounted) setState(() => _leaving = null);
    }
  }

  void _say(String text) {
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(context)
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) {
    final top = <Widget>[
      if (_busy && !_loaded) const LoadingSkeleton(rows: 3),
      if (_busy && _loaded) const LinearProgressIndicator(),
      if (_old)
        EmptyState(
          key: const ValueKey('phones-server-old'),
          icon: Symbols.cloud_sync,
          title: context.l10n.profileServerMissingTitle,
          message: context.l10n.profilePhonesServerOld,
        ),
      if (_error != null) ErrorState(key: const ValueKey('phones-error'), message: _error!, onRetry: _busy ? null : _load),
      if (_loaded && _rows.isEmpty && _error == null && !_old)
        EmptyState(
            icon: Symbols.devices, title: context.l10n.profilePhonesEmptyTitle, message: context.l10n.profilePhonesEmptyMessage),
    ];
    final more = _next != null && !_old;
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.profilePhonesTitle, module: context.l10n.profileModuleMe),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView.builder(
          key: const ValueKey('phones-list'),
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          itemCount: top.length + _rows.length + (more ? 1 : 0),
          itemBuilder: (context, i) {
            if (i < top.length) return top[i];
            final r = i - top.length;
            if (r < _rows.length) {
              final phone = _rows[r];
              return _PhoneRow(phone: phone, leaving: _leaving == phone.id, onSignOut: _leaving == null ? () => _signOut(phone) : null);
            }
            return MoreButton(busy: _busy, onPressed: () => _load(more: true));
          },
        ),
      ),
    );
  }
}

/// "শেষ ব্যবহার: 20/10/2026 সকাল 10:00" (ইংরেজিতে "Last used: 20/10/2026 10:00 AM") — অ্যাপের ভাষায়
String lastUsedLine(MyPhone phone) {
  final when = phone.lastUsedAt;
  final words = AppLocale.l10n;
  return words.profilePhonesLastUsed(when == null ? words.profilePhonesLastUsedUnknown : stampText(when));
}

class _PhoneRow extends StatelessWidget {
  const _PhoneRow({required this.phone, required this.leaving, required this.onSignOut});

  final MyPhone phone;
  final bool leaving;
  final VoidCallback? onSignOut;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final p = context.palette;
    final ios = (phone.platform ?? '').toLowerCase() == 'ios';
    final used = lastUsedLine(phone);
    return Card(
      key: ValueKey('phone-${phone.id}'),
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.sm, AppSpacing.sm),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          MergeSemantics(
            child: Semantics(
              label: [phone.name, if (phone.current) context.l10n.profilePhonesThisPhone, used].join(', '),
              child: ExcludeSemantics(
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Padding(
                    padding: const EdgeInsets.only(top: 2, right: AppSpacing.sm),
                    child: Icon(ios ? Symbols.phone_iphone : Symbols.smartphone, color: cs.primary),
                  ),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Wrap(spacing: AppSpacing.sm, runSpacing: AppSpacing.xs, crossAxisAlignment: WrapCrossAlignment.center, children: [
                        Text(phone.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                        if (phone.current)
                          Container(
                            key: const ValueKey('phone-current'),
                            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm, vertical: 2),
                            decoration: BoxDecoration(color: p.successSurface, borderRadius: BorderRadius.circular(999)),
                            child: Text(context.l10n.profilePhonesThisPhone, style: TextStyle(color: p.success, fontWeight: FontWeight.w700, fontSize: 12.5)),
                          ),
                      ]),
                      const SizedBox(height: 2),
                      Text(used, style: TextStyle(color: p.onSurfaceMuted)),
                    ]),
                  ),
                ]),
              ),
            ),
          ),
          Align(
            alignment: AlignmentDirectional.centerEnd,
            // ⓘ পর্দা-পাঠক ফোনের নাম ধরে বলে — পাঁচটা "বের করে দিন" এক রকম শোনালে কোনটা কোন ফোনের, বোঝা যেত না
            child: TextButton.icon(
              key: ValueKey('phone-out-${phone.id}'),
              style: TextButton.styleFrom(foregroundColor: cs.error, minimumSize: const Size(48, 48)),
              onPressed: onSignOut,
              icon: leaving
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Symbols.logout),
              label: Text(context.l10n.profilePhonesSignOut, semanticsLabel: context.l10n.profilePhonesSignOutNamed(phone.name)),
            ),
          ),
        ]),
      ),
    );
  }
}
