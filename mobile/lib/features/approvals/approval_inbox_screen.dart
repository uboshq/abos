import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import 'approval_bulk_sheet.dart';
import 'approval_paper_screen.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/records/approval_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
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
  });

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

  /// বাছাই করা কাগজ — একসাথে অনুমোদনের জন্য (কেবল সার্ভার যেগুলো `bulkable` বলে)
  final Set<String> _picked = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  bool get _legacyOnly => widget.loadInbox == null && widget.loadPending != null;

  Future<InboxPage> _inbox(int page) => (widget.loadInbox ??
      (String t, String? m, bool l, int p) => ApprovalsApi.inbox(tab: t, module: m, late: l, page: p))(_tab, _module, _late, page);

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

  Future<void> _open(int index) async {
    final rows = _rows;
    if (rows == null || !_decidable) return;
    await Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => ApprovalPaperScreen(
        rows: List.of(rows),
        start: index,
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
                    for (final (i, approval) in rows.indexed)
                      _ApprovalCard(
                        approval: approval,
                        picked: _picked.contains(approval.id),
                        onPick: approval.bulkable && _decidable
                            ? (on) => setState(() => on ? _picked.add(approval.id) : _picked.remove(approval.id))
                            : null,
                        onOpen: _decidable ? () => _open(i) : null,
                      ),
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
      bottomNavigationBar: _inboxDoor == true && bulkable && _decidable
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
            for (final (key, label, count) in tabs)
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
                            child: Text(label,
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

/// কাজের চিপ, সংখ্যাসহ, আর দেরির চিপ — ⓘ যেখানে কিছু নেই সেই চিপ আসে না (সার্ভার পাঠায় না); দেরি শূন্য হলে দেরির চিপও নয়
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
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, 0),
      child: Row(
        children: [
          ChoiceChip(
            key: const ValueKey('approvals-chip-all'),
            label: Text('${words.approvalsChipAll} ${page.allCount}'),
            selected: module == null,
            onSelected: (_) => onModule(null),
          ),
          for (final (key, label, count) in page.modules) ...[
            const SizedBox(width: AppSpacing.xs),
            ChoiceChip(
              key: ValueKey('approvals-chip-$key'),
              label: Text('$label $count'),
              selected: module == key,
              onSelected: (_) => onModule(module == key ? null : key),
            ),
          ],
          if (page.lateCount > 0) ...[
            const SizedBox(width: AppSpacing.xs),
            FilterChip(
              key: const ValueKey('approvals-chip-late'),
              label: Text('${words.approvalsChipLate} ${page.lateCount}'),
              selected: late,
              onSelected: onLate,
            ),
          ],
        ],
      ),
    );
  }
}

/// ⭐ কার্ড — পাতা ৬, ছবি ১: "কাজ · নম্বর  অঙ্ক" / পক্ষ · শাখা / শেষ সময় (রঙে) · ধাপ ২/২
class _ApprovalCard extends StatelessWidget {
  const _ApprovalCard({required this.approval, required this.picked, required this.onPick, required this.onOpen});

  final ApprovalRecord approval;
  final bool picked;
  final ValueChanged<bool>? onPick;
  final VoidCallback? onOpen;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final words = context.l10n;
    final small = Theme.of(context).textTheme.bodySmall;
    final tone = switch (approval.deadlineTone) {
      'danger' => palette.danger,
      'warning' => palette.warning,
      'success' => palette.success,
      _ => palette.onSurfaceMuted,
    };
    final title = [approval.documentTypeLabel, if (approval.documentNo != null) approval.documentNo!].join(' · ');
    // ⓘ ওয়েবের কার্ডের তিন লাইন: কাজ · নম্বর আর অঙ্ক; পক্ষ · শাখা (মালিক, ১৯ অক্টোবর ২০২৬ — নম্বরের কাছে পক্ষ; পাতা ৬-এ নম্বরের
    // ঠিক নিচে); কী বাবদ · টাকা কোথায় (মালিক, ২০ সেপ্টেম্বর ২০২৬: "kothy theke eseche kothay joma holo")। পুরনো সার্ভারে পক্ষ
    // আলাদা আসে না — তখন সারাংশটাই, আগের মতো।
    final place = approval.party;
    final rest = summaryAfterParty(approval.summary, approval.party);

    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: InkWell(
        key: Key('approval-open-${approval.id}'),
        onTap: onOpen,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ⓘ চেকবক্সের নিজের ছোঁয়া — বাছতে গিয়ে কাগজ খুলে যায় না; পর্দা-পাঠক কাগজের নম্বর শোনে
              if (onPick != null)
                Padding(
                  padding: const EdgeInsets.only(right: AppSpacing.sm),
                  child: SizedBox(
                    width: 24,
                    height: 24,
                    child: Checkbox(
                      key: Key('approval-pick-${approval.id}'),
                      value: picked,
                      semanticLabel: words.approvalsPick(approval.documentNo ?? title),
                      onChanged: (on) => onPick!(on ?? false),
                    ),
                  ),
                ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w700))),
                        const SizedBox(width: AppSpacing.sm),
                        // Absent rather than zero when the server sent no amount —
                        // not every approvable document is about money.
                        if (approval.amount != null) Text(approval.amountText!, style: const TextStyle(fontWeight: FontWeight.w700)),
                      ],
                    ),
                    // ⓘ লম্বা নাম এক লাইনে কাটা (…), পুরোটা চেপে ধরলে — কার্ড ভেঙে কয়েক লাইন নয়
                    if (place != null)
                      Tooltip(
                        message: place,
                        child: Text(place,
                            key: Key('approval-place-${approval.id}'), style: small, maxLines: 1, overflow: TextOverflow.ellipsis),
                      ),
                    if (rest != null) Text(rest, key: Key('approval-about-${approval.id}'), style: small),
                    const SizedBox(height: AppSpacing.xs),
                    Wrap(
                      spacing: AppSpacing.sm,
                      runSpacing: AppSpacing.xs,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        if (approval.status != null && approval.status != 'pending')
                          Text(approval.statusLabel ?? approval.status!, style: small?.copyWith(fontWeight: FontWeight.w600))
                        else if (approval.deadlineText != null)
                          Text(approval.deadlineText!,
                              key: Key('approval-deadline-${approval.id}'),
                              style: small?.copyWith(color: tone, fontWeight: FontWeight.w600)),
                        if (approval.stepAt != null && approval.stepOf != null)
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                            decoration: BoxDecoration(color: palette.surfaceMuted, borderRadius: BorderRadius.circular(8)),
                            child: Text(words.approvalsStepOf(approval.stepAt!, approval.stepOf!), style: small),
                          )
                        else if (approval.currentLevel > 0)
                          Text(words.approvalsInboxStep(approval.currentLevel), style: small),
                        if (approval.standInFor != null) Text(words.approvalsOnBehalf(approval.standInFor!), style: small),
                      ],
                    ),
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

/// ⭐ দুই মোট — এই তালিকার আর সবগুলোর (ওয়েবের দফা ৬)। ⓘ অঙ্ক সার্ভারের যোগ; ফোন যোগ করে না
class _Totals extends StatelessWidget {
  const _Totals({required this.page});

  final InboxPage page;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final muted = TextStyle(color: context.palette.onSurfaceMuted);
    return Padding(
      key: const ValueKey('approvals-totals'),
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
      child: Column(
        children: [
          Row(children: [
            Expanded(child: Text(words.approvalsListTotal(page.pageCount), style: const TextStyle(fontWeight: FontWeight.w700))),
            Text(Money.taka(page.pageAmount), style: const TextStyle(fontWeight: FontWeight.w700)),
          ]),
          const SizedBox(height: AppSpacing.xs),
          Row(children: [
            Expanded(child: Text(words.approvalsAllTotal(page.count), style: muted)),
            Text(Money.taka(page.amount), style: muted),
          ]),
        ],
      ),
    );
  }
}
