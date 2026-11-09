import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import 'approval_bulk_sheet.dart';
import 'approval_paper_screen.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/records/approval_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/day_part.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/number_with_party.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/l10n.dart';

/// What is waiting for this person's decision — docs/Contract §৫.
///
/// <p><b>Why this screen is the first thing on a phone that is not sync.</b>
/// Everything else in this app helps someone in a shop. This one helps
/// someone who is *not at their desk*, which is the only place the rest of
/// ABOS can be used. A bill waits for an owner who is travelling, and the
/// waiting is the cost.
///
/// <p><b>Online only, deliberately.</b> An approval moves a document forward —
/// stock shifts, money is released — and two phones out of coverage would
/// each approve the same bill once. Same reasoning as the owner's decision
/// that only orders may be written offline.
///
/// <p>⭐ <b>পরিকল্পনার পাতা ৬, ছবি ১ — অনুমোদন ভাগ ৩, দফা ২১ (৩০ অক্টোবর ২০২৬):</b> *"অ্যাপে একই ট্যাব, একই নাম আর একই
/// নিয়ম।"* ওয়েবের চার ট্যাব আর সংখ্যা, কাজের চিপ, কার্ডে শেষ সময় আর "ধাপ ২/২", নিচে দুই মোট, আর বাছাই করে একসাথে অনুমোদন।
/// ট্যাব, শেষ সময়ের লেখা আর রং, মোট — সবই সার্ভারের ([[ApprovalsApi.inbox]]); ফোন নিজে কিছু হিসাব করে না। ⓘ কার্ডে আর
/// সিদ্ধান্তের বোতাম নেই: কার্ড ছুঁলে কাগজের পাতা খোলে ([[ApprovalPaperScreen]], ছবি ২), বোতাম সেখানে নিচে স্থির।
/// ⚠️ পুরনো সার্ভারে `/approvals/inbox` নেই (৪০৪) — তখন আগের মতো একটাই তালিকা (`pending`), ট্যাব আর মোট ছাড়া।
class ApprovalInboxScreen extends StatefulWidget {
  const ApprovalInboxScreen({
    super.key,
    this.loadInbox,
    this.loadPending,
    this.approve,
    this.reject,
    this.sendBack,
    this.loadSheet,
    this.loadMore,
    this.bulkPreview,
    this.bulkApprove,
    this.loadAttachment,
    this.loadInboxSorted,
    this.deviceCheck,
    this.rejectWithCode,
  });

  /// বড় অঙ্কে ফোনের তালা — কাগজের পাতায় যায় ([[ApprovalApproveSheet]])
  final Future<bool?> Function()? deviceCheck;

  /// প্রত্যাখ্যান, কারণের কোডসহ — কাগজের পাতায় যায় ([[ApprovalRejectSheet]])
  final Future<void> Function(String id, String remarks, String code)? rejectWithCode;

  /// Seams, so this screen can be driven in a test without a server.
  ///
  /// <p>Not ceremony: the entire class of bug found on 12 September was
  /// screens that were never once run against a payload. A screen that cannot
  /// be run in a test is a screen whose first run is on somebody's phone.
  final Future<InboxPage> Function(String tab, String? module, bool late, int page)? loadInbox;

  /// পুরনো সার্ভারের তালিকা — এটা দেওয়া আর [loadInbox] না দেওয়া থাকলে পর্দা সরাসরি পুরনো রূপে চলে
  final Future<ApprovalPage> Function()? loadPending;
  final Future<void> Function(String id, String? remarks)? approve;
  final Future<void> Function(String id, String remarks)? reject;

  /// সংশোধনে ফেরত — না দিলে সার্ভার ([[ApprovalsApi.sendBack]])
  final Future<void> Function(String id, String remarks)? sendBack;

  /// সইয়ের আগে বিস্তারিত — না দিলে সার্ভার ([[ApprovalsApi.sheet]])
  final Future<ApprovalSheet> Function(String id)? loadSheet;

  /// পুরনো সার্ভারের পরের পাতা — না দিলে সার্ভার ([[ApprovalsApi.pending]] `cursor`)
  final Future<ApprovalPage> Function(String cursor)? loadMore;

  final Future<BulkPreview> Function(List<String> ids)? bulkPreview;
  final Future<BulkResult> Function(List<String> ids, String? remarks)? bulkApprove;
  final Future<List<int>> Function(String id, int file)? loadAttachment;

  /// ⭐ খোঁজা আর সাজানোসহ — নমুনা v4; দেওয়া থাকলে [loadInbox]-এর আগে এটা (টেস্ট খোঁজা/সাজানো দেখে)
  final Future<InboxPage> Function(String tab, String? module, bool late, int page, String q, String sort)? loadInboxSorted;

  @override
  State<ApprovalInboxScreen> createState() => _ApprovalInboxScreenState();
}

class _ApprovalInboxScreenState extends State<ApprovalInboxScreen> {
  /// null = এখনো জানা নেই; true = নতুন দরজা আছে; false = পুরনো সার্ভার
  bool? _inboxDoor;
  InboxPage? _page;
  List<ApprovalRecord>? _rows;

  // ⛔ পরের পাতার চাবি — আগে কখনো পড়া হত না, তাই ৫০টার পরের কাগজ ফোনে কোনোদিন আসত না (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
  String? _nextCursor;
  int? _nextPage;
  String? _error;
  bool _busy = false;

  String _tab = 'waiting';
  String? _module;
  bool _late = false;

  /// ⭐ খোঁজা আর সাজানো — নমুনা v4 ("নম্বর, পক্ষ বা কে চেয়েছেন"; দেরি আগে · নতুন আগে · বড় অঙ্ক আগে)
  String _q = '';
  String _sort = 'late';
  final _search = TextEditingController();
  Timer? _typing;

  /// বাছাই করা কাগজ — একসাথে অনুমোদনের জন্য (কেবল সার্ভার যেগুলো `bulkable` বলে)
  final Set<String> _picked = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _typing?.cancel();
    _search.dispose();
    super.dispose();
  }

  /// ⓘ লেখা থামার একটু পরে খোঁজা — প্রতিটা অক্ষরে সার্ভারে যায় না
  void _typed(String text) {
    _typing?.cancel();
    _typing = Timer(const Duration(milliseconds: 400), () {
      if (!mounted || text.trim() == _q) return;
      setState(() => _q = text.trim());
      _load();
    });
  }

  bool get _legacyOnly => widget.loadInbox == null && widget.loadPending != null;

  Future<InboxPage> _inbox(int page) {
    final sorted = widget.loadInboxSorted;
    if (sorted != null) return sorted(_tab, _module, _late, page, _q, _sort);
    final plain = widget.loadInbox;
    if (plain != null) return plain(_tab, _module, _late, page);
    return ApprovalsApi.inbox(tab: _tab, module: _module, late: _late, page: page, q: _q, sort: _sort);
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (_inboxDoor != false && !_legacyOnly) {
        try {
          final page = await _inbox(1);
          if (!mounted) return;
          setState(() {
            _inboxDoor = true;
            _page = page;
            _rows = page.rows;
            _nextPage = page.nextPage;
            _picked.retainAll(page.rows.where((r) => r.bulkable).map((r) => r.id));
          });
          return;
        } on DioException catch (error) {
          // ⓘ পুরনো সার্ভার — দরজাটাই নেই; একবার জেনে নিয়ে আগের তালিকায় ফেরা
          if (error.response?.statusCode != 404) rethrow;
        }
      }
      final page = await (widget.loadPending ?? ApprovalsApi.pending)();
      if (!mounted) return;
      setState(() {
        _inboxDoor = false;
        _rows = page.rows;
        _nextCursor = page.nextCursor;
      });
    } catch (error) {
      if (!mounted) return;
      // An empty inbox and an unreachable server are opposite facts, and a
      // screen that shows "কিছু অপেক্ষা করছে না" for both would tell someone
      // their work is done when it is merely invisible.
      setState(() => _error =
          errorMessageFor(error, fallback: context.l10n.approvalsInboxLoadFailed, whenAbsent: context.l10n.approvalsInboxServerMissing));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _more() async {
    setState(() => _busy = true);
    try {
      final List<ApprovalRecord> more;
      if (_inboxDoor == true) {
        final page = await _inbox(_nextPage ?? 1);
        more = page.rows;
        _nextPage = page.nextPage;
      } else {
        final cursor = _nextCursor;
        if (cursor == null) return;
        final page = await (widget.loadMore ?? (String c) => ApprovalsApi.pending(cursor: c))(cursor);
        more = page.rows;
        _nextCursor = page.nextCursor;
      }
      if (!mounted) return;
      setState(() {
        final seen = {for (final r in _rows ?? const <ApprovalRecord>[]) r.id};
        _rows = [...?_rows, ...more.where((r) => !seen.contains(r.id))];
      });
    } catch (error) {
      if (mounted) _say(errorMessageFor(error, fallback: context.l10n.approvalsInboxMoreFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// ⓘ ভাগ কেবল "দেরি আগে" সাজানোয় আর সিদ্ধান্তের ট্যাবে — নতুন বা বড় অঙ্ক আগে সাজালে ভাগ ক্রম ভাঙত
  bool get _grouped => _inboxDoor == true && _sort == 'late' && _decidable;

  bool get _hasMore => _inboxDoor == true ? _nextPage != null : _nextCursor != null;

  void _say(String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

  void _switchTab(String tab) {
    if (tab == _tab) return;
    setState(() {
      _tab = tab;
      _module = null;
      _late = false;
      _picked.clear();
    });
    _load();
  }

  /// ⓘ কাজ আর দেরি আলাদা ছাঁকনি — একটা বদলালে অন্যটা থাকে (ওয়েবের চিপের মতো)
  void _filter(void Function() change) {
    setState(change);
    _load();
  }

  /// ⓘ সিদ্ধান্ত দেওয়া যায় কেবল অপেক্ষা আর অন্যের-হয়ে ট্যাবে — বাকি দুই ট্যাব কেবল দেখার (কাগজের পাতা সই-পাহারায় খোলে)
  bool get _decidable => _inboxDoor != true || _tab == 'waiting' || _tab == 'behalf';

  Future<void> _open(int index, {bool approve = false}) async {
    final rows = _rows;
    if (rows == null || !_decidable) return;
    await Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => ApprovalPaperScreen(
        rows: List.of(rows),
        start: index,
        askApproveFirst: approve,
        deviceCheck: widget.deviceCheck,
        rejectWithCode: widget.rejectWithCode,
        loadSheet: widget.loadSheet,
        approve: widget.approve,
        reject: widget.reject,
        sendBack: widget.sendBack,
        loadAttachment: widget.loadAttachment,
        onDecided: _drop,
        onAmountChanged: _load,
      ),
    ));
  }

  /// Takes a row off the list without a round trip — used for the decisions
  /// that succeeded *and* for the ones somebody else made first (docs/Contract
  /// §৫ rule খ). Builds a new list rather than mutating the one it was handed:
  /// handed an unmodifiable list, `removeWhere` threw.
  void _drop(String id) {
    if (!mounted) return;
    setState(() {
      _rows = (_rows ?? const <ApprovalRecord>[]).where((row) => row.id != id).toList();
      _picked.remove(id);
    });
  }

  Future<void> _bulk() async {
    final result = await ApprovalBulkSheet.show(
      context,
      ids: _picked.toList(),
      preview: widget.bulkPreview ?? ApprovalsApi.bulkPreview,
      approve: widget.bulkApprove ?? (List<String> ids, String? remarks) => ApprovalsApi.bulkApprove(ids, remarks: remarks),
    );
    if (result == null || !mounted) return;
    _say(result.skippedCount == 0
        ? context.l10n.approvalsBulkDone(result.done)
        : context.l10n.approvalsBulkDoneSkipped(result.done, result.skippedCount));
    setState(_picked.clear);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    final page = _page;
    final words = context.l10n;
    final bulkable = (rows ?? const <ApprovalRecord>[]).any((r) => r.bulkable);

    return Scaffold(
      appBar: AbosScreenBar(title: words.approvalsInboxTitle),
      body: Column(
        children: [
          // ⭐ মাথার তিন সংখ্যা আর খোঁজা — নমুনা v4, ছবি ১ (আগে দুই মোট তালিকার একদম নিচে ছিল)
          if (_inboxDoor == true && page != null) _SummaryBand(page: page, search: _search, onTyped: _typed),
          if (_busy) const LinearProgressIndicator(),
          if (_inboxDoor == true && page != null) ...[
            _Tabs(tabs: page.tabs, active: _tab, onTap: _switchTab),
            _Chips(
              page: page,
              module: _module,
              late: _late,
              onModule: (m) => _filter(() => _module = m),
              onLate: (l) => _filter(() => _late = l),
            ),
            _SortBar(sort: _sort, onSort: (s) => _filter(() => _sort = s)),
          ],
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              // Always a scrollable, so the pull gesture works on every state
              // — including the two that ask for it. Confirmed on a device in
              // September: an EmptyState outside a scrollable made the one
              // instruction on screen do nothing.
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  if (_error != null)
                    EmptyState(icon: Icons.cloud_off_outlined, title: words.approvalsInboxLoadFailedTitle, message: _error)
                  else if (rows == null)
                    const SizedBox(height: 200)
                  else if (rows.isEmpty)
                    // Not an error screen — an empty inbox is good news.
                    EmptyState(icon: Icons.inbox_outlined, title: words.approvalsInboxEmptyTitle, message: words.approvalsInboxEmptyMessage)
                  else ...[
                    for (final (i, approval) in rows.indexed) ...[
                      // ⭐ দেরি আগে, তিন ভাগে — লাল "দেরি হয়ে গেছে", কমলা "আজকের মধ্যে", সবুজ "সময় আছে" (নমুনা v4); কেবল "দেরি আগে" সাজানোয়
                      if (_grouped && (i == 0 || rows[i - 1].deadlineTone != approval.deadlineTone))
                        _GroupHead(tone: approval.deadlineTone, count: rows.where((r) => r.deadlineTone == approval.deadlineTone).length),
                      _ApprovalCard(
                        approval: approval,
                        picked: _picked.contains(approval.id),
                        onPick: approval.bulkable && _decidable
                            ? (on) => setState(() => on ? _picked.add(approval.id) : _picked.remove(approval.id))
                            : null,
                        onOpen: _decidable ? () => _open(i) : null,
                        onApprove: _decidable && (approval.status == null || approval.status == 'pending') ? () => _open(i, approve: true) : null,
                      ),
                    ],
                    if (_inboxDoor == true && page != null) _Totals(page: page),
                  ],
                  if (_error == null && rows != null && _hasMore)
                    OutlinedButton(
                      key: const ValueKey('approvals-more'),
                      onPressed: _busy ? null : _more,
                      child: Text(words.approvalsInboxShowMore),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
      // ⭐ একসাথে অনুমোদন — নিচে স্থির (পাতা ৬, ছবি ১); ⓘ বাছাই শূন্য হলে চাপা যায় না, আর বাছার মতো কাগজই না থাকলে বোতাম নেই
      // ⓘ নমুনা v4: বাছাই না থাকলে নিচে কোনো বোতাম নয় — লম্বা চাপে (বা চিহ্নে ছুঁয়ে) প্রথম কাগজ বাছলে তবে আসে
      bottomNavigationBar: _inboxDoor == true && bulkable && _decidable && _picked.isNotEmpty
          ? SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.sm),
                child: FilledButton(
                  key: const ValueKey('approvals-bulk'),
                  onPressed: _picked.isEmpty || _busy ? null : _bulk,
                  child: Text(words.approvalsBulkButton(_picked.length)),
                ),
              ),
            )
          : null,
    );
  }
}

/// ওয়েবের চার ট্যাব, সংখ্যাসহ — লিংক-ট্যাবের মতো: বাছলে তালিকা নতুন করে আসে।
/// ⓘ চারটা সমান ভাগে, এক সারিতে (পাতা ৬, ছবি ১) — লম্বা নাম ("আমি অনুমোদন দিয়েছি") দুই লাইনে ভাঙে; ⛔ পাশে সরানো সারিতে ৪১২-এ
/// চতুর্থ ট্যাবটা পর্দার বাইরে থাকত (৩০ অক্টোবরের ছবি)।
class _Tabs extends StatelessWidget {
  const _Tabs({required this.tabs, required this.active, required this.onTap});

  final List<(String, String, int)> tabs;
  final String active;
  final ValueChanged<String> onTap;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final scheme = Theme.of(context).colorScheme;
    return DecoratedBox(
      decoration: BoxDecoration(border: Border(bottom: BorderSide(color: palette.border))),
      // ⓘ IntrinsicHeight — চার ট্যাব একই উচ্চতায় (একটার নাম দুই লাইনে গেলেও নিচের দাগ এক রেখায়); ⛔ শুধু `stretch` কলামের ভেতরে
      // অসীম উচ্চতা চাইত আর প্রতিটা ফ্রেমে ভাঙত
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final (key, serverLabel, count) in tabs)
              Expanded(
                child: Semantics(
                  selected: key == active,
                  button: true,
                  child: InkWell(
                    key: ValueKey('approvals-tab-$key'),
                    onTap: () => onTap(key),
                    child: Container(
                      constraints: const BoxConstraints(minHeight: 48),
                      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs, vertical: AppSpacing.xs),
                      decoration: BoxDecoration(
                        border: Border(bottom: BorderSide(color: key == active ? scheme.primary : Colors.transparent, width: 2)),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Flexible(
                            child: Text(_shortTab(context, key, serverLabel),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                  fontSize: 13,
                                  fontWeight: key == active ? FontWeight.w700 : FontWeight.w400,
                                  color: key == active ? palette.onSurface : palette.onSurfaceMuted,
                                )),
                          ),
                          const SizedBox(width: AppSpacing.xs),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 5),
                            decoration: BoxDecoration(
                              color: key == active ? scheme.primary : palette.surfaceMuted,
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Text('$count',
                                style: TextStyle(fontSize: 11, color: key == active ? scheme.onPrimary : palette.onSurfaceMuted)),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// ⭐ ট্যাবের ছোট নাম — এক লাইনে আঁটে (নমুনা v4 দফা ৭: আগে "আমি অনুমোদন দিয়েছি" দুই লাইনে ভাঙত); অচেনা চাবিতে সার্ভারের নাম
String _shortTab(BuildContext context, String key, String serverLabel) {
  final words = context.l10n;
  return switch (key) {
    'waiting' => words.approvalsTabWaiting,
    'sent' => words.approvalsTabSent,
    'done' => words.approvalsTabDone,
    'behalf' => words.approvalsTabBehalf,
    _ => serverLabel,
  };
}

/// ⭐ বিভাগের রং — মালিকের নমুনা v4 ("ok", ৮ নভেম্বর ২০২৬): হিসাব বেগুনি, বিক্রয় নীল, ক্রয় কমলা, HR গোলাপি, মজুদ সবুজাভ-নীল;
/// বাকিগুলো অ্যাপের মডিউলের রং ([[AppColors.moduleColor]])
Color inboxModuleColor(String key) => switch (key) {
      'accounts' => const Color(0xFF4F46E5),
      'sales' => const Color(0xFF2563EB),
      'purchase' => const Color(0xFFEA580C),
      'hr' => const Color(0xFFDB2777),
      'inventory' => const Color(0xFF0D9488),
      _ => AppColors.moduleColor(key),
    };

/// হালকা পট — বিভাগের রং কার্ডের পটের উপর ১২%
Color inboxModuleTint(BuildContext context, String key) =>
    Color.alphaBlend(inboxModuleColor(key).withValues(alpha: 0.12), context.palette.surface);

/// ⓘ কার্ডের চিহ্ন — হিসাবে "৳", বাকিতে কাগজের নামের প্রথম অক্ষর (কার সাথে কার-চিহ্ন: "বি", "ক", "খ"; হসন্তে থামে)
String inboxGlyph(ApprovalRecord approval) {
  if (approval.moduleKey == 'accounts') return '৳'; // l10n-ok: the taka sign marks money papers
  final runes = approval.documentTypeLabel.runes.toList();
  if (runes.isEmpty) return '•';
  final out = StringBuffer(String.fromCharCode(runes.first));
  if (runes.length > 1 && runes[1] >= 0x09BE && runes[1] <= 0x09CC) out.write(String.fromCharCode(runes[1]));
  return out.toString();
}

/// ⭐ মাথার তিন সংখ্যা আর খোঁজার ঘর — নমুনা v4, ছবি ১: অপেক্ষায় · দেরি (লাল) · মোট টাকা (লাখে); নিচে খোঁজা
class _SummaryBand extends StatelessWidget {
  const _SummaryBand({required this.page, required this.search, required this.onTyped});

  final InboxPage page;
  final TextEditingController search;
  final ValueChanged<String> onTyped;

  String _amount(BuildContext context) {
    final value = Money.value(page.amount) ?? 0;
    if (value < 100000) return Money.taka(value);
    return '৳${context.l10n.approvalsLakh((value / 100000).toStringAsFixed(2))}'; // l10n-ok: the taka sign is the currency itself
  }

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    Widget box(String key, String figure, String label, {bool late = false}) => Expanded(
          child: Container(
            key: ValueKey('approvals-sum-$key'),
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 7),
            decoration: BoxDecoration(
              color: late ? const Color(0xFFC62828) : Colors.white.withValues(alpha: 0.12), // dark-ok: on the fixed dark-blue summary band
              border: Border.all(color: late ? const Color(0xFFEF5350) : Colors.white.withValues(alpha: 0.22)), // dark-ok: on the fixed dark-blue summary band
              borderRadius: BorderRadius.circular(12),
            ),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(figure,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w700, height: 1.2)), // dark-ok: white on the fixed dark-blue band
              Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: Colors.white.withValues(alpha: 0.9), fontSize: 11.5)), // dark-ok: white on the fixed dark-blue band
            ]),
          ),
        );

    return Container(
      key: const ValueKey('approvals-summary-band'),
      padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.md),
      decoration: const BoxDecoration(
        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Color(0xFF0F2B4C), Color(0xFF164A7A)]),
      ),
      child: Column(children: [
        Row(children: [
          box('waiting', '${page.allCount}', words.approvalsSumWaiting),
          const SizedBox(width: AppSpacing.sm),
          box('late', '${page.lateCount}', words.approvalsSumLate, late: page.lateCount > 0),
          const SizedBox(width: AppSpacing.sm),
          box('amount', _amount(context), words.approvalsSumAmount),
        ]),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          key: const ValueKey('approvals-search'),
          controller: search,
          onChanged: onTyped,
          onSubmitted: onTyped,
          textInputAction: TextInputAction.search,
          style: const TextStyle(color: Color(0xFF142130), fontSize: 14),
          decoration: InputDecoration(
            isDense: true,
            filled: true,
            fillColor: Colors.white, // dark-ok: a white field with fixed dark text, as the sample, in both modes
            prefixIcon: const Icon(Icons.search, color: Color(0xFF5F6F80), size: 20),
            hintText: words.approvalsSearchHint,
            hintStyle: const TextStyle(color: Color(0xFF5F6F80), fontSize: 13.5),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
            contentPadding: const EdgeInsets.symmetric(vertical: 10),
          ),
        ),
      ]),
    );
  }
}

/// কাজের চিপ, সংখ্যাসহ, আর দেরির চিপ — ⓘ যেখানে কিছু নেই সেই চিপ আসে না (সার্ভার পাঠায় না); দেরি শূন্য হলে দেরির চিপও নয়।
/// ⭐ নমুনা v4: "সব" কালো, দেরি লাল, প্রতিটা বিভাগের চিপে নিজের রঙের বিন্দু
class _Chips extends StatelessWidget {
  const _Chips({required this.page, required this.module, required this.late, required this.onModule, required this.onLate});

  final InboxPage page;
  final String? module;
  final bool late;
  final ValueChanged<String?> onModule;
  final ValueChanged<bool> onLate;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;

    Widget chip(Key key, String label, bool on, VoidCallback onTap, {Color? dot, Color? ink}) => Padding(
          padding: const EdgeInsets.only(right: 6),
          child: Semantics(
            selected: on,
            button: true,
            child: InkWell(
              key: key,
              borderRadius: BorderRadius.circular(999),
              onTap: onTap,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 4),
                decoration: BoxDecoration(
                  color: on ? (ink ?? palette.onSurface) : palette.surface,
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: ink ?? (on ? palette.onSurface : palette.border)),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  if (dot != null) ...[
                    Container(width: 9, height: 9, decoration: BoxDecoration(color: dot, shape: BoxShape.circle)),
                    const SizedBox(width: 6),
                  ],
                  Text(label, style: TextStyle(fontSize: 13, color: on ? palette.surface : (ink ?? palette.onSurface))),
                ]),
              ),
            ),
          ),
        );

    return Container(
      color: palette.surface,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            chip(const ValueKey('approvals-chip-all'), '${words.approvalsChipAll} ${page.allCount}', module == null && !late, () {
              if (late) onLate(false);
              onModule(null);
            }),
            if (page.lateCount > 0)
              chip(const ValueKey('approvals-chip-late'), '● ${words.approvalsChipLate} ${page.lateCount}', late, () => onLate(!late),
                  ink: palette.danger),
            for (final (key, label, count) in page.modules)
              chip(ValueKey('approvals-chip-$key'), '$label $count', module == key, () => onModule(module == key ? null : key),
                  dot: inboxModuleColor(key)),
          ],
        ),
      ),
    );
  }
}

/// ⭐ সাজানো — "সাজানো: দেরি আগে ▾", ডানে বাকি দুই পছন্দ (নমুনা v4; সার্ভারের `sort`)
class _SortBar extends StatelessWidget {
  const _SortBar({required this.sort, required this.onSort});

  final String sort;
  final ValueChanged<String> onSort;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;
    final names = {'late': words.approvalsSortLate, 'new': words.approvalsSortNew, 'amount': words.approvalsSortAmount};
    final small = TextStyle(fontSize: 12.5, color: palette.onSurfaceMuted);
    final others = names.keys.where((k) => k != sort).toList();

    return Container(
      key: const ValueKey('approvals-sort'),
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
      decoration: BoxDecoration(color: palette.surface, border: Border(bottom: BorderSide(color: palette.border))),
      // ⓘ জায়গা না থাকলে (বড় লেখা, সরু ফোন) ডানের দুই পছন্দ নিচের লাইনে নামে — কাটে বা উপচায় না
      child: Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 2, children: [
        Row(mainAxisSize: MainAxisSize.min, children: [
          Text('${words.approvalsSortLabel} ', style: small),
          Text('${names[sort]} ▾', key: ValueKey('approvals-sort-now-$sort'), style: small.copyWith(color: palette.onSurface, fontWeight: FontWeight.w700)),
        ]),
        Row(mainAxisSize: MainAxisSize.min, children: [
          for (final (i, key) in others.indexed) ...[
            if (i > 0) Text(' · ', style: small),
            InkWell(
              key: ValueKey('approvals-sort-$key'),
              onTap: () => onSort(key),
              child: Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Text(names[key]!, style: small)),
            ),
          ],
        ]),
      ]),
    );
  }
}

/// ভাগের মাথা — রঙের বিন্দু, নাম আর সংখ্যা
class _GroupHead extends StatelessWidget {
  const _GroupHead({required this.tone, required this.count});

  final String? tone;
  final int count;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;
    final (colour, name) = switch (tone) {
      'danger' => (palette.danger, words.approvalsGroupLate),
      'warning' => (palette.warning, words.approvalsGroupNear),
      'success' => (palette.success, words.approvalsGroupOk),
      _ => (palette.onSurfaceMuted, words.approvalsGroupNoClock),
    };
    return Padding(
      key: ValueKey('approvals-group-${tone ?? 'none'}'),
      padding: const EdgeInsets.fromLTRB(2, 6, 2, 6),
      child: Row(children: [
        Container(width: 8, height: 8, decoration: BoxDecoration(color: colour, shape: BoxShape.circle)),
        const SizedBox(width: 8),
        Text(name, style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: colour)),
        const SizedBox(width: 4),
        Text(words.approvalsGroupCount(count), style: TextStyle(fontSize: 12.5, color: palette.onSurfaceMuted)),
      ]),
    );
  }
}

/// ⭐ কার্ড — নমুনা v4, ছবি ১: বাঁয়ে বিভাগের রঙে চিহ্ন; "কাজ · নম্বর  অঙ্ক" / পক্ষ / "চেয়েছেন কে · কখন" / শেষ সময় (রঙে) · ধাপের
/// বিন্দু আর নাম · 📎 সংখ্যা · অঙ্ক বদলেছে; নিচে "দেখুন" আর "✓ অনুমোদন দিন"। ⓘ লম্বা চাপে একসাথে অনুমোদনের বাছাই।
class _ApprovalCard extends StatelessWidget {
  const _ApprovalCard({required this.approval, required this.picked, required this.onPick, required this.onOpen, this.onApprove});

  final ApprovalRecord approval;
  final bool picked;
  final ValueChanged<bool>? onPick;
  final VoidCallback? onOpen;
  final VoidCallback? onApprove;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final words = context.l10n;
    final small = TextStyle(fontSize: 12, color: palette.onSurfaceMuted);
    final (tone, toneBg) = switch (approval.deadlineTone) {
      'danger' => (palette.danger, palette.dangerSurface),
      'warning' => (palette.warning, palette.warningSurface),
      'success' => (palette.success, palette.successSurface),
      _ => (palette.onSurfaceMuted, palette.surfaceMuted),
    };
    final title = [approval.documentTypeLabel, if (approval.documentNo != null) approval.documentNo!].join(' · ');
    final place = approval.party ?? summaryAfterParty(approval.summary, null);
    final module = approval.moduleKey;
    final decided = approval.status != null && approval.status != 'pending';
    final (decidedInk, decidedBg) = switch (approval.status) {
      'approved' => (palette.success, palette.successSurface),
      'rejected' => (palette.danger, palette.dangerSurface),
      _ => (palette.warning, palette.warningSurface),
    };

    Widget pill(Key? key, Widget child, Color ink, Color bg) => Container(
          key: key,
          padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 2),
          decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
          child: DefaultTextStyle.merge(style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: ink), child: child),
        );

    final at = approval.stepAt, of = approval.stepOf;

    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      decoration: BoxDecoration(
        color: palette.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: picked ? Theme.of(context).colorScheme.primary : palette.border, width: picked ? 2 : 1),
      ),
      child: InkWell(
        key: Key('approval-open-${approval.id}'),
        borderRadius: BorderRadius.circular(14),
        onTap: onOpen,
        onLongPress: onPick == null ? null : () => onPick!(!picked),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ⓘ বাছা হলে চিহ্নের জায়গায় টিক — পর্দা-পাঠক কাগজের নম্বর শোনে; ⓘ চিহ্নে ছোঁয়া = বাছাই (লম্বা চাপের পাশে, যাতে প্রথমবারেও
              // খুঁজে পাওয়া যায়) — বাছতে গিয়ে কাগজ খোলে না
              Semantics(
                label: onPick == null ? null : words.approvalsPick(approval.documentNo ?? title),
                checked: onPick == null ? null : picked,
                child: GestureDetector(
                  key: onPick == null ? null : Key('approval-pick-${approval.id}'),
                  onTap: onPick == null ? null : () => onPick!(!picked),
                  child: Container(
                  key: Key('approval-glyph-${approval.id}'),
                  width: 44,
                  height: 44,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: picked ? Theme.of(context).colorScheme.primary : inboxModuleTint(context, module),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: picked
                      ? const Icon(Icons.check, color: Colors.white) // dark-ok: white tick on the primary-filled tile
                      : Text(inboxGlyph(approval), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: inboxModuleColor(module))),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(crossAxisAlignment: CrossAxisAlignment.baseline, textBaseline: TextBaseline.alphabetic, children: [
                      Expanded(
                        child: Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
                      ),
                      const SizedBox(width: AppSpacing.sm),
                      // Absent rather than zero when the server sent no amount —
                      // not every approvable document is about money.
                      if (approval.amount != null)
                        Text(approval.amountText!, key: Key('approval-amount-${approval.id}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                    ]),
                    // ⓘ লম্বা নাম এক লাইনে কাটা (…), পুরোটা চেপে ধরলে — কার্ড ভেঙে কয়েক লাইন নয়
                    if (place != null)
                      Tooltip(
                        message: place,
                        child: Text(place, key: Key('approval-place-${approval.id}'), style: const TextStyle(fontSize: 13.5), maxLines: 1, overflow: TextOverflow.ellipsis),
                      ),
                    if (approval.requesterName != null)
                      Text(
                        words.approvalsAskedBy(approval.requesterName!, approval.requestedAt == null ? '' : stampText(approval.requestedAt!)),
                        key: Key('approval-asker-${approval.id}'),
                        style: small,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        if (decided)
                          pill(
                            Key('approval-decided-${approval.id}'),
                            Text(approval.decidedAt == null
                                ? (approval.statusLabel ?? approval.status!)
                                : words.approvalsDecidedAt(approval.statusLabel ?? approval.status!, stampText(approval.decidedAt!))),
                            decidedInk,
                            decidedBg,
                          )
                        else if (approval.deadlineText != null)
                          pill(Key('approval-deadline-${approval.id}'), Text('⏱ ${approval.deadlineText!}'), tone, toneBg),
                        if (at != null && of != null)
                          pill(
                            Key('approval-step-${approval.id}'),
                            Row(mainAxisSize: MainAxisSize.min, children: [
                              for (var n = 1; n <= of && n <= 6; n++)
                                Container(
                                  width: 7,
                                  height: 7,
                                  margin: const EdgeInsets.only(right: 3),
                                  decoration: BoxDecoration(
                                    shape: BoxShape.circle,
                                    color: n < at ? palette.success : (n == at ? Theme.of(context).colorScheme.primary : palette.border),
                                  ),
                                ),
                              const SizedBox(width: 2),
                              Text(approval.stepName == null ? words.approvalsStepOf(at, of) : '${words.approvalsStepOf(at, of)} · ${approval.stepName}'),
                            ]),
                            palette.onSurfaceMuted,
                            palette.surfaceMuted,
                          )
                        else if (approval.currentLevel > 0)
                          pill(null, Text(words.approvalsInboxStep(approval.currentLevel)), palette.onSurfaceMuted, palette.surfaceMuted),
                        if (approval.attachmentCount > 0)
                          pill(Key('approval-files-${approval.id}'), Text('📎 ${approval.attachmentCount}'), palette.onSurfaceMuted, palette.surfaceMuted),
                        if (approval.amountChanged)
                          pill(Key('approval-changed-${approval.id}'), Text(words.approvalsAmountChangedPill), palette.warning, palette.warningSurface),
                        if (approval.standInFor != null) Text(words.approvalsOnBehalf(approval.standInFor!), style: small),
                      ],
                    ),
                    if (onOpen != null && onApprove != null) ...[
                      const SizedBox(height: 9),
                      Row(children: [
                        Expanded(
                          child: OutlinedButton(
                            key: Key('approval-view-${approval.id}'),
                            onPressed: onOpen,
                            style: OutlinedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(vertical: 7, horizontal: 6),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                              side: BorderSide(color: palette.border, width: 1.5),
                              foregroundColor: palette.onSurface,
                            ),
                            child: Text(words.approvalsCardView, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                          ),
                        ),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(
                          child: FilledButton(
                            key: Key('approval-card-approve-${approval.id}'),
                            onPressed: onApprove,
                            style: FilledButton.styleFrom(
                              backgroundColor: const Color(0xFF15803D),
                              // ⓘ অন্ধকারে থিমের লেখার রং গাঢ় হয়ে সবুজে মিলিয়ে যেত — সবুজের উপর সবসময় সাদা
                              foregroundColor: Colors.white, // dark-ok: white on the fixed green approve button
                              padding: const EdgeInsets.symmetric(vertical: 7, horizontal: 6),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                            ),
                            child: Text(words.approvalsCardApprove, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                          ),
                        ),
                      ]),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// ⭐ নিচের লাইন — "এই তালিকায় ৪টা · আরও ৩টা নিচে" আর এই পাতার অঙ্ক (নমুনা v4); ⓘ অঙ্ক সার্ভারের যোগ, ফোন যোগ করে না
class _Totals extends StatelessWidget {
  const _Totals({required this.page});

  final InboxPage page;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final muted = TextStyle(fontSize: 13, color: context.palette.onSurfaceMuted);
    final more = page.count - page.pageCount;
    return Padding(
      key: const ValueKey('approvals-totals'),
      padding: const EdgeInsets.fromLTRB(4, 4, 4, AppSpacing.sm),
      child: Row(children: [
        Expanded(child: Text(more > 0 ? words.approvalsTotalsMore(page.pageCount, more) : words.approvalsListTotal(page.pageCount), style: muted)),
        Text(Money.taka(page.pageAmount), key: const ValueKey('approvals-totals-amount'), style: muted),
      ]),
    );
  }
}
