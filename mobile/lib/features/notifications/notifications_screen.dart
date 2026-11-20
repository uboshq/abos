import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/notification_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/look_widgets.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/web_inside/web_inside.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/day_part.dart';
import '../../core/locale/l10n.dart';

/// The bell's page — this person's messages, newest first; a tap marks one
/// read, and "সব পড়া হয়েছে" empties the bell (owner, 6 Oct 2026).
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, this.fetch, this.markRead, this.markAllRead, this.openWeb, this.openId});

  /// ⭐ ফোনের বার্তায় চাপলে সেই নোটিফিকেশনটাই খোলে (সার্ভারের পুশ: `open: notification, id: <public_id>`)। তালিকায় না
  /// পেলে (পুরনো, বা অন্য পাতায়) কেবল তালিকা।
  final String? openId;

  /// Seams — the real ones need a server.
  final Future<NotificationPage> Function()? fetch;
  final Future<void> Function(String id)? markRead;
  final Future<void> Function()? markAllRead;

  /// Seam — the real one goes to the menu's own door for that web path (`/home/web/<code>`).
  final void Function(BuildContext context, String webPath)? openWeb;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  NotificationPage? _page;
  String? _error;
  bool _openedFromPush = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final page = await (widget.fetch ?? NotificationApi.fetch)();
      if (!mounted) return;
      setState(() {
        _page = page;
        _error = null;
      });
      final wanted = widget.openId;
      if (wanted != null && wanted.isNotEmpty && !_openedFromPush) {
        _openedFromPush = true; // ⓘ একবারই — পড়া দাগের পরের আনায় আবার নয়
        for (final item in page.items) {
          if (item.id == wanted) {
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (mounted) _open(item);
            });
            break;
          }
        }
      }
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = errorMessageFor(error,
          fallback: context.l10n.notificationsLoadFailed,
          whenAbsent: context.l10n.notificationsServerMissing));
    }
  }

  Future<void> _open(NotificationRecord item) async {
    if (!item.read) {
      try {
        await (widget.markRead ?? NotificationApi.markRead)(item.id);
      } catch (_) {
        // ⓘ পড়ার দাগ না বসলেও খবরটা দেখানো যায় — পরের বার আবার চেষ্টা
      }
      await _load();
    }
    if (!mounted) return;
    await showModalBottomSheet<void>(
      context: context,
      builder: (context) => Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(item.title,
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
            const SizedBox(height: AppSpacing.sm),
            Text(item.body),
            // ⭐ খবরের কাগজটা খোলা — মেনুর একই দরজা: ফোনের পর্দা থাকলে সেটা, নইলে ভিতরের ওয়েব-জানালা (fe, ১৯ অক্টোবর ২০২৬)
            if (item.webPath case final path?) ...[
              const SizedBox(height: AppSpacing.md),
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton.icon(
                  key: const ValueKey('notification-open'),
                  icon: const Icon(Icons.open_in_new, size: 18),
                  label: Text(context.l10n.commonOpen),
                  onPressed: () {
                    Navigator.of(context).pop();
                    (widget.openWeb ?? (c, p) => c.go('/home/${WebPaths.navPathFor(p)}'))(this.context, path);
                  },
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _readAll() async {
    try {
      await (widget.markAllRead ?? NotificationApi.markAllRead)();
    } catch (_) {}
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;

    return Scaffold(
      appBar: AbosScreenBar(
        title: context.l10n.notificationsTitle,
        actions: [
          if ((page?.unread ?? 0) > 0)
            TextButton(
              onPressed: _readAll,
              // ⓘ মাথার পট্টির লেখার রং — আলোতে সাদা (আগের মতো), অন্ধকারে থিমের
              child: Text(context.l10n.notificationsMarkAllRead,
                  style: TextStyle(color: Theme.of(context).appBarTheme.foregroundColor ?? Theme.of(context).colorScheme.onSurface)),
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: page == null
            ? ListView(children: [
                const SizedBox(height: AppSpacing.xxl),
                Center(child: Text(_error ?? context.l10n.notificationsLoading)),
              ])
            : page.items.isEmpty
                ? ListView(children: [
                    EmptyState(
                      icon: Icons.notifications_none,
                      title: context.l10n.notificationsEmptyTitle,
                      message: context.l10n.notificationsEmptyMessage,
                    ),
                  ])
                : ListView.separated(
                    itemCount: page.items.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (context, index) {
                      final item = page.items[index];
                      return ListTile(
                        key: ValueKey('notification-${item.id}'),
                        leading: Icon(
                          item.read ? Icons.notifications_none : Icons.notifications_active,
                          color: item.read ? context.palette.onSurfaceMuted : context.lookAccent(AppColors.primary),
                        ),
                        title: Text(item.title,
                            style: TextStyle(
                                fontWeight: item.read ? FontWeight.w400 : FontWeight.w700)),
                        subtitle: Text(
                          [
                            if (item.body.isNotEmpty) item.body,
                            if (item.at != null) stampText(item.at!),
                          ].join('\n'),
                          maxLines: 3,
                          overflow: TextOverflow.ellipsis,
                        ),
                        onTap: () => _open(item),
                      );
                    },
                  ),
      ),
    );
  }
}
