import 'package:flutter/material.dart';

import '../api_client/network_errors.dart';
import '../theme/app_spacing.dart';
import 'empty_state.dart';
import 'state_views.dart';
import '../../features/books/books_widgets.dart';
import '../navigation/top_bar/abos_screen_bar.dart';

/// ⭐ এক পাতা করে আনা তালিকা — টেনে নবায়ন, খালি অবস্থা, বাংলায় ভুল, আর "আরও দেখুন"; অর্থ, ডকুমেন্টস আর বেতনের স্লিপের
/// তালিকা একই ছাঁচে (১৩ অক্টোবর ২০২৬)।
///
/// <p>[load] পাতার নম্বর নেয় আর সারি ও পরের পাতা ফেরায়; [header] তালিকার মাথায় বসে (ট্যাব, মোট)। [reloadKey] বদলালে
/// (যেমন ট্যাব বদল) প্রথম পাতা থেকে আবার আনে।
class PagedList<T> extends StatefulWidget {
  const PagedList({
    super.key,
    required this.load,
    required this.item,
    required this.emptyTitle,
    required this.failed,
    this.emptyIcon = Icons.inbox_outlined,
    this.emptyMessage,
    this.header = const [],
    this.reloadKey,
    this.whenAbsent,
  });

  final Future<(List<T>, int?)> Function(int page) load;
  final Widget Function(BuildContext context, T row) item;
  final String emptyTitle;
  final String? emptyMessage;
  final IconData emptyIcon;
  final String failed;
  final String? whenAbsent;
  final List<Widget> header;
  final Object? reloadKey;

  @override
  State<PagedList<T>> createState() => _PagedListState<T>();
}

class _PagedListState<T> extends State<PagedList<T>> {
  final List<T> _rows = [];
  int? _next;
  bool _busy = false;
  bool _loaded = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant PagedList<T> old) {
    super.didUpdateWidget(old);
    if (old.reloadKey != widget.reloadKey) _load();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (rows, next) = await widget.load(more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(rows);
        _next = next;
        _loaded = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e, fallback: widget.failed, whenAbsent: widget.whenAbsent));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final top = <Widget>[
      ...widget.header,
      // ⭐ তিন অবস্থা (নকশা ৩): প্রথম আনায় খসড়া সারি; আগে আনা থাকলে উপরে সরু দাগ; ভুলে "আবার চেষ্টা করুন"
      if (_busy && !_loaded) const LoadingSkeleton(),
      if (_busy && _loaded) const LinearProgressIndicator(),
      if (_error != null) ErrorState(key: const ValueKey('books-error'), message: _error!, onRetry: _busy ? null : _load),
      if (_loaded && _rows.isEmpty && _error == null)
        EmptyState(icon: widget.emptyIcon, title: widget.emptyTitle, message: widget.emptyMessage),
    ];
    final more = _next != null;
    // ⭐ সারি বানায় কেবল যতটা পর্দায় আসে (ListView.builder) — "আরও দেখুন" চেপে চেপে হাজার সারি জমলেও টানতে আটকায় না
    // (Android vitals: ধীর ফ্রেম; ec-র সাথে ভাগ, ২০ অক্টোবর ২০২৬)। ⛔ আগে প্রতিটা সারি একসাথে children-এ তৈরি হত।
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(AppSpacing.md),
        itemCount: top.length + _rows.length + (more ? 1 : 0),
        itemBuilder: (context, i) {
          if (i < top.length) return top[i];
          final r = i - top.length;
          if (r < _rows.length) return widget.item(context, _rows[r]);
          return MoreButton(busy: _busy, onPressed: () => _load(more: true));
        },
      ),
    );
  }
}

/// একটা কাগজের পাতা — আনা, টেনে নবায়ন, বাংলায় ভুল; খোলা কাগজ "সাম্প্রতিক"-এ তোলা ডাকে দেয় [onLoaded]
class PaperPage<T> extends StatefulWidget {
  const PaperPage({
    super.key,
    required this.title,
    required this.load,
    required this.body,
    required this.failed,
    this.titleOf,
    this.onLoaded,
  });

  final String title;
  final String Function(T paper)? titleOf;
  final Future<T> Function() load;
  final List<Widget> Function(BuildContext context, T paper) body;
  final String failed;
  final void Function(T paper)? onLoaded;

  @override
  State<PaperPage<T>> createState() => _PaperPageState<T>();
}

class _PaperPageState<T> extends State<PaperPage<T>> {
  T? _paper;
  String? _error;
  bool _busy = false;

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
      final paper = await widget.load();
      if (!mounted) return;
      setState(() => _paper = paper);
      widget.onLoaded?.call(paper);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: widget.failed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final paper = _paper;
    return Scaffold(
      appBar: AbosScreenBar(
          title: paper == null || widget.titleOf == null ? widget.title : widget.titleOf!(paper)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy && paper == null) const LoadingSkeleton(),
            if (_error != null) ErrorState(key: const ValueKey('books-error'), message: _error!, onRetry: _busy ? null : _load),
            if (paper != null) ...widget.body(context, paper),
          ],
        ),
      ),
    );
  }
}

/// ট্যাবের সারি — চিপ, একটা বাছা
class TabChips extends StatelessWidget {
  const TabChips({super.key, required this.tabs, required this.selected, required this.onSelected});

  final Map<String, String> tabs;
  final String selected;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: Wrap(
          spacing: AppSpacing.xs,
          children: [
            for (final e in tabs.entries)
              ChoiceChip(
                key: ValueKey('tab-${e.key}'),
                label: Text(e.value),
                selected: selected == e.key,
                onSelected: (_) => onSelected(e.key),
              ),
          ],
        ),
      );
}

/// কার্ডের মাথায় ছোট শিরোনাম
class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(4, AppSpacing.md, 4, AppSpacing.xs),
        child: Text(text, style: const TextStyle(fontWeight: FontWeight.w700)),
      );
}
