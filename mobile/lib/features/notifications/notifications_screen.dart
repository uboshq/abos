import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/records/notification_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// The bell's page — this person's messages, newest first; a tap marks one
/// read, and "সব পড়া হয়েছে" empties the bell (owner, 6 Oct 2026).
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, this.fetch, this.markRead, this.markAllRead});

  /// Seams — the real ones need a server.
  final Future<NotificationPage> Function()? fetch;
  final Future<void> Function(String id)? markRead;
  final Future<void> Function()? markAllRead;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  NotificationPage? _page;
  String? _error;

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
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = errorMessageFor(error,
          fallback: 'নোটিফিকেশন আনা গেল না।',
          whenAbsent: 'নোটিফিকেশন এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'));
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
      appBar: AppBar(
        title: const Text('নোটিফিকেশন'),
        actions: [
          if ((page?.unread ?? 0) > 0)
            TextButton(
              onPressed: _readAll,
              child: const Text('সব পড়া হয়েছে', style: TextStyle(color: Colors.white)),
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: page == null
            ? ListView(children: [
                const SizedBox(height: AppSpacing.xxl),
                Center(child: Text(_error ?? 'আনা হচ্ছে…')),
              ])
            : page.items.isEmpty
                ? ListView(children: const [
                    EmptyState(
                      icon: Icons.notifications_none,
                      title: 'কোনো নোটিফিকেশন নেই',
                      message: 'নতুন কিছু এলে এখানে দেখাবে।',
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
                          color: item.read ? AppColors.onSurfaceMuted : AppColors.primary,
                        ),
                        title: Text(item.title,
                            style: TextStyle(
                                fontWeight: item.read ? FontWeight.w400 : FontWeight.w700)),
                        subtitle: Text(
                          [
                            if (item.body.isNotEmpty) item.body,
                            if (item.at != null) DateFormat('dd/MM/yyyy hh:mm a').format(item.at!),
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
