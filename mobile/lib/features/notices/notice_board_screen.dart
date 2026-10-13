import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';

/// ⭐ নোটিশ বোর্ড, ফোনে — মালিক, ১৩ অক্টোবর ২০২৬: *"app e baki module & menu"*।
///
/// <p>প্রতিষ্ঠানের নোটিশ — যেগুলো এই মানুষটার জন্য (ওয়েবের নোটিশ পাতার একই ছাঁকনি, [[NoticeBoard]])। খুললে পড়া হয়েছে বলে
/// দাগ পড়ে (ওয়েবের মতোই); সই চাওয়া নোটিশে "পড়েছি ও মানছি" বোতাম — সই সার্ভারে বসে, যন্ত্রের নাম আর সময়সহ
/// ([[NoticeAcknowledgement]])। ঘণ্টার খবর ([[NotificationsScreen]]) আলাদা: সেটা নিজের কাজের খবর, এটা প্রতিষ্ঠানের কথা।
class NoticeBoardScreen extends StatefulWidget {
  const NoticeBoardScreen({super.key, this.api = const ServerNoticeBoardApi()});

  final NoticeBoardApi api;

  @override
  State<NoticeBoardScreen> createState() => _NoticeBoardScreenState();
}

class _NoticeBoardScreenState extends State<NoticeBoardScreen> {
  NoticePage? _page;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.notices();
      if (!mounted) return;
      setState(() => _page = page);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'নোটিশ আনা গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(NoticeItem notice) async {
    if (notice.standing == 'unread') {
      try {
        await widget.api.markRead(notice.id);
      } catch (_) {
        // ⓘ দাগ না বসলেও নোটিশটা পড়া যায় — পরের বার খুললে আবার চেষ্টা
      }
    }
    if (!mounted) return;
    await Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => NoticeDetailScreen(notice: notice, api: widget.api),
    ));
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;

    return Scaffold(
      appBar: AppBar(title: const Text('নোটিশ বোর্ড')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy && page == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (page != null && page.unread > 0)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Text('${page.unread}টা নোটিশ এখনো পড়া হয়নি',
                    key: const ValueKey('notices-unread'),
                    style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.primary)),
              ),
            if (page != null && page.items.isEmpty)
              const EmptyState(
                icon: Icons.campaign_outlined,
                title: 'এখন কোনো নোটিশ নেই',
                message: 'প্রতিষ্ঠান নতুন কিছু জানালে এখানে দেখাবে।',
              ),
            if (page != null)
              for (final n in page.items)
                Card(
                  margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: ListTile(
                    key: ValueKey('notice-${n.id}'),
                    leading: Icon(
                      n.owesSignature ? Icons.draw_outlined : (n.standing == 'unread' ? Icons.mark_email_unread_outlined : Icons.drafts_outlined),
                      color: n.owesSignature
                          ? AppColors.danger
                          : (n.standing == 'unread' ? AppColors.primary : AppColors.onSurfaceMuted),
                    ),
                    title: Text(n.title,
                        style: TextStyle(fontWeight: n.standing == 'unread' ? FontWeight.w700 : FontWeight.w500)),
                    subtitle: Text(
                      [
                        if (n.summary.isNotEmpty) n.summary,
                        [
                          NoticeItem.priorityLabels[n.priority] ?? '',
                          NoticeItem.standingLabels[n.standing] ?? '',
                          if (n.publishedAt != null) _when(n.publishedAt),
                        ].where((s) => s.isNotEmpty).join(' · '),
                      ].join('\n'),
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                    ),
                    onTap: () => _open(n),
                  ),
                ),
          ],
        ),
      ),
    );
  }
}

String _when(String? iso) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  return at == null ? '' : DateFormat('dd/MM/yyyy hh:mm a').format(at);
}

/// একটা নোটিশ, পুরোটা — সই চাওয়া হলে নিচে "পড়েছি ও মানছি"
class NoticeDetailScreen extends StatefulWidget {
  const NoticeDetailScreen({super.key, required this.notice, required this.api});

  final NoticeItem notice;
  final NoticeBoardApi api;

  @override
  State<NoticeDetailScreen> createState() => _NoticeDetailScreenState();
}

class _NoticeDetailScreenState extends State<NoticeDetailScreen> {
  late String _standing = widget.notice.standing == 'unread' ? 'read' : widget.notice.standing;
  final _once = OnceKey();
  bool _busy = false;
  String? _error;

  Future<void> _sign() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await _once.send(() => widget.api.acknowledge(widget.notice.id));
      if (!mounted) return;
      setState(() => _standing = 'acknowledged');
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('সই দেওয়া হলো।')));
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'সই দেওয়া গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final n = widget.notice;
    final signed = _standing == 'acknowledged';

    return Scaffold(
      appBar: AppBar(title: Text(n.no.isEmpty ? 'নোটিশ' : n.no)),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          Text(n.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          const SizedBox(height: AppSpacing.xs),
          Text(
            [
              NoticeItem.priorityLabels[n.priority] ?? '',
              if (n.author.isNotEmpty) n.author,
              if (n.publishedAt != null) _when(n.publishedAt),
            ].where((s) => s.isNotEmpty).join(' · '),
            style: const TextStyle(color: AppColors.onSurfaceMuted),
          ),
          if (n.summary.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(n.summary, style: const TextStyle(fontWeight: FontWeight.w600)),
          ],
          const SizedBox(height: AppSpacing.md),
          SelectableText(n.body),
          if (n.expiresAt != null) ...[
            const SizedBox(height: AppSpacing.md),
            Text('মেয়াদ ${_when(n.expiresAt)} পর্যন্ত',
                style: const TextStyle(fontSize: 12, color: AppColors.onSurfaceMuted)),
          ],
          if (n.ackRequired) ...[
            const Divider(height: AppSpacing.xl),
            if (n.ackDeadline != null && !signed)
              Text('সইয়ের শেষ সময় ${_when(n.ackDeadline)}',
                  style: TextStyle(color: _standing == 'overdue' || _standing == 'escalated' ? AppColors.danger : AppColors.warning)),
            if (signed)
              const Row(
                key: ValueKey('notice-signed'),
                children: [
                  Icon(Icons.verified_outlined, color: AppColors.success),
                  SizedBox(width: AppSpacing.xs),
                  Text('আপনি সই দিয়েছেন', style: TextStyle(color: AppColors.success, fontWeight: FontWeight.w700)),
                ],
              )
            else
              FilledButton.icon(
                key: const ValueKey('notice-sign'),
                onPressed: _busy ? null : _sign,
                icon: const Icon(Icons.draw_outlined),
                label: Text(_busy ? 'পাঠানো হচ্ছে…' : 'পড়েছি ও মানছি — সই দিন'),
              ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.xs),
                child: Text(_error!, style: const TextStyle(color: AppColors.danger)),
              ),
          ],
        ],
      ),
    );
  }
}

class NoticeItem {
  const NoticeItem({
    required this.id,
    this.no = '',
    required this.title,
    this.summary = '',
    this.body = '',
    this.priority = '',
    this.publishedAt,
    this.expiresAt,
    this.ackRequired = false,
    this.ackDeadline,
    this.standing = 'unread',
    this.author = '',
  });

  final String id;
  final String no;
  final String title;
  final String summary;
  final String body;
  final String priority;
  final String? publishedAt;
  final String? expiresAt;
  final bool ackRequired;
  final String? ackDeadline;

  /// unread · read · acknowledged · overdue · escalated — সার্ভারের [[NoticeStanding]]
  final String standing;
  final String author;

  bool get owesSignature => ackRequired && standing != 'acknowledged';

  static const Map<String, String> standingLabels = {
    'unread': 'পড়া হয়নি',
    'read': 'পড়া হয়েছে',
    'acknowledged': 'সই দেওয়া',
    'overdue': 'সইয়ের সময় পেরিয়েছে',
    'escalated': 'উপরে জানানো হয়েছে',
  };

  /// ওয়েবের `core.notice.priority` — হুবহু
  static const Map<String, String> priorityLabels = {
    'low': 'কম জরুরি',
    'normal': 'সাধারণ',
    'important': 'গুরুত্বপূর্ণ',
    'high': 'বেশি জরুরি',
    'critical': 'অত্যন্ত জরুরি',
    'emergency': 'জরুরি অবস্থা',
  };

  factory NoticeItem.fromJson(Map<dynamic, dynamic> j) => NoticeItem(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        title: j['title']?.toString() ?? '',
        summary: j['summary']?.toString() ?? '',
        body: j['body']?.toString() ?? '',
        priority: j['priority']?.toString() ?? '',
        publishedAt: j['published_at']?.toString(),
        expiresAt: j['expires_at']?.toString(),
        ackRequired: j['ack_required'] == true,
        ackDeadline: j['ack_deadline']?.toString(),
        standing: j['standing']?.toString() ?? 'unread',
        author: j['author']?.toString() ?? '',
      );
}

class NoticePage {
  const NoticePage({required this.items, this.unread = 0});

  final List<NoticeItem> items;
  final int unread;
}

abstract class NoticeBoardApi {
  Future<NoticePage> notices();

  Future<void> markRead(String id);

  Future<void> acknowledge(String id);
}

class ServerNoticeBoardApi implements NoticeBoardApi {
  const ServerNoticeBoardApi();

  @override
  Future<NoticePage> notices() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/notices');
    final body = response.data ?? const <String, dynamic>{};
    final data = body['data'];
    final meta = body['meta'];
    final items = [
      if (data is List)
        for (final n in data.whereType<Map>()) NoticeItem.fromJson(n),
    ];
    return NoticePage(
      items: items,
      // ⓘ পুরনো সার্ভার গোনা পাঠায় না — তখন এই পাতার না-পড়াগুলো
      unread: meta is Map && meta['unread'] is num
          ? (meta['unread'] as num).toInt()
          : items.where((n) => n.standing == 'unread').length,
    );
  }

  @override
  Future<void> markRead(String id) => ApiClient.dio.post<Map<String, dynamic>>('/notices/$id/read');

  @override
  Future<void> acknowledge(String id) => ApiClient.dio.post<Map<String, dynamic>>('/notices/$id/acknowledge');
}
