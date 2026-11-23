import 'package:flutter/material.dart';

import '../../core/widgets/totals_bar.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/accounts/voucher_api.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/auth/session_profile.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/menu/me_api.dart';
import '../../core/records/money.dart';
import '../../core/records/party_search.dart';
import '../../core/status/app_status_chip.dart';
import '../../core/status/paper_state.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../home/nav_bottom_bar.dart' show ActionBottomBar, BarFab;
import '../books/books_widgets.dart';
import 'money_voucher_screen.dart';
import '../printing/document_actions_sheet.dart';
import '../printing/row_actions.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ ফোনে অফিসের লোকের ভাউচার — আদায়, পরিশোধ, খরচ (বাকিতেও), জাবেদা, কন্ট্রা (মালিক, ৭ অক্টোবর ২০২৬; সার্ভার 2bd0f620)।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস। নিয়ম সব সার্ভারের — এখানে কেবল যা না থাকলে পাঠানোই অর্থহীন। ⛔ কেবল নেট থাকলে।
/// ⭐ ভাউচারের অবস্থার চিপ — খাতায় তোলা আটকে থাকলে (`posting_failed`) আগে সেটাই: "পোস্ট হয়নি", চাপলে সার্ভারের বাংলা কারণ
/// (সার্ভারে অবস্থা তখনো খসড়া)। নইলে সার্ভারের অবস্থা, সইয়ের অপেক্ষায় ধাপ দুটো এলে "2-এর 1"।
Widget voucherStateChip(VoucherRow v, {Key? key, bool dense = false}) => v.postingFailed
    ? AppStatusChip(key: key, state: PaperState.notPosted, reason: v.postProblem, dense: dense)
    : AppStatusChip.forServer(v.state, key: key, fallbackLabel: v.stateWords, level: v.approvalLevel, levels: v.approvalLevels, dense: dense);

class VoucherListScreen extends StatefulWidget {
  const VoucherListScreen({
    super.key,
    this.api = const ServerVoucherApi(),
    this.newChangeId = _engineChangeId,
    this.branches = _myBranches,
    this.today,
    this.view,
    this.canCreate = false,
    this.mayCollect = false,
  });

  static String _engineChangeId() => SyncEngine.instance.newChangeId();

  /// ⭐ গ্রাহকের টাকার "রসিদ ভাউচার" দেখাবে কি না — `/me`-র `mayCollect` (সার্ভার: টাকা নেওয়া আর ভাউচারের চাবি, আর দেয়ালে
  /// বাঁধা নন — মালিকের নিয়ম, SR কেবল অর্ডার দেন, ৭ অক্টোবর ২০২৬)। ⛔ ভাউচারের চাবি একা যথেষ্ট নয়।
  /// ⭐ ২০ নভেম্বর ২০২৬ থেকে এটা খোলে রসিদ ভাউচারের ফর্ম (RCV), আদায়ের পাতা নয় — মালিক: গ্রাহকের টাকা কেবল কাউন্টারে আর হিসাবের
  /// রসিদে; ফোনে আদায় (COL) লেখা নেই।
  final bool mayCollect;

  /// ⓘ নাগালের শাখা — `/me` থেকে; না পেলে শাখার ছাঁকনিই দেখায় না
  static Future<List<OrgRef>> _myBranches() async {
    try {
      return (await MeApi.fetch()).profile.branches;
    } catch (_) {
      return const [];
    }
  }

  final VoucherApi api;
  final String Function() newChangeId;
  final Future<List<OrgRef>> Function() branches;

  /// পরীক্ষার জন্য — না দিলে আজ
  final DateTime? today;

  /// ⭐ মেনুর আলাদা ঘর থেকে খোলা ধরন ([VoucherView.key], `/home/vouchers/<key>`) — মালিক, ১৭ অক্টোবর ২০২৬।
  /// null বা অচেনা হলে "সব", আগের মতো।
  final String? view;

  /// ⭐ "নতুন ভাউচার" কেবল যার লেখার চাবি আছে (`accounts.voucher.create`) — পথ থেকে আসে, না দিলে বন্ধ
  final bool canCreate;

  @override
  State<VoucherListScreen> createState() => _VoucherListScreenState();
}

class _VoucherListScreenState extends State<VoucherListScreen> {
  Future<void> _openVoucher(String id) async {
    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => VoucherScreen(id: id, api: widget.api)));
    if (mounted) await _load();
  }

  /// ⭐ উপরের বোতাম — সব · নগদ রসিদ · MFS রসিদ · ব্যাংক রসিদ · পেমেন্ট · কন্ট্রা · জার্নাল · খরচ (মালিক, ১৫ ও ১৭ অক্টোবর
  /// ২০২৬); তারপর এমন কোনো ধরন থাকলে যার বোতাম এখনো নেই
  static List<VoucherView> get _views => [
        ...VoucherView.kinds,
        for (final t in VoucherTypes.all)
          if (!VoucherView.kinds.any((v) => v.type == t)) VoucherView(t, VoucherTypes.label(t).replaceAll(AppLocale.l10n.vouchersKindSuffix, ''), type: t),
      ];

  late VoucherView _view = VoucherView.byKey(widget.view) ?? VoucherView.all;

  /// মেনুর আলাদা ঘর থেকে একটা ধরন নিয়ে খোলা
  bool get _preset => VoucherView.byKey(widget.view) != null && widget.view != VoucherView.all.key;
  bool _awaiting = false;
  late DateTime _from;
  late DateTime _to;
  List<OrgRef> _branches = const [];
  OrgRef? _branch;
  final List<VoucherRow> _rows = [];
  VoucherListPage? _page;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _to = DateTime(now.year, now.month, now.day);
    _from = DateTime(now.year, now.month);
    _load();
    widget.branches().then((list) {
      if (mounted) setState(() => _branches = list);
    });
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.list(
        type: _view.type,
        money: _view.money,
        awaiting: _awaiting,
        from: isoOf(_from),
        to: isoOf(_to),
        branch: _branch?.publicId,
        page: more ? (_page?.nextPage ?? 1) : 1,
      );
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(page.rows);
        _page = page;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e, fallback: context.l10n.vouchersListLoadFailed, whenAbsent: context.l10n.vouchersListNotOnServer));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// "অন্য আয়" — গ্রাহক ছাড়া কারো টাকা (যেমন ভাড়া): আদায় ভাউচারের পুরনো ফর্ম, আলাদা নামে
  static const _otherIncome = 'other-income';

  Future<void> _new() async {
    // ⭐ ধরন নিয়ে খোলা হলে সরাসরি সেই ধরনের ফর্ম; "সব"-এ আগের মতো ধরন বাছাই
    final preset = _preset ? _view.type : null;
    final type = preset ??
        await showModalBottomSheet<String>(
          context: context,
          // ⓘ ছোট ফোনে ২০০% অক্ষরে সব ধরন না ধরলে গড়ায় — নিচের ধরন কাটা পড়ে না
          builder: (sheet) => SafeArea(
            child: SingleChildScrollView(
                child: Column(mainAxisSize: MainAxisSize.min, children: [
              for (final t in VoucherTypes.all) ...[
                if (t != VoucherTypes.receipt || widget.mayCollect)
                  ListTile(
                    key: ValueKey('voucher-type-$t'),
                    title: Text(VoucherTypes.label(t)),
                    subtitle: t == VoucherTypes.receipt ? Text(sheet.l10n.vouchersReceiptCustomerHint) : null,
                    onTap: () => Navigator.of(sheet).pop(t),
                  ),
                // ⭐ গ্রাহক ছাড়া অন্য আয় — আলাদা, পরিষ্কার নামে; আদায়ের সাথে মেশানো নয় (মালিক, ২১ অক্টোবর ২০২৬)
                if (t == VoucherTypes.receipt)
                  ListTile(
                    key: const ValueKey('voucher-type-$_otherIncome'),
                    title: Text(sheet.l10n.collectOtherIncome),
                    subtitle: Text(sheet.l10n.collectOtherIncomeHint),
                    onTap: () => Navigator.of(sheet).pop(_otherIncome),
                  ),
              ],
            ])),
          ),
        );
    if (type == null || !mounted) return;
    // ⭐ গ্রাহকের টাকা হিসাবের রসিদ ভাউচারে (RCV) — আদায়ের পাতা আর নয় (মালিক, ২০ নভেম্বর ২০২৬: টাকা নেওয়ার এক কাগজ);
    // রসিদ আর "অন্য আয়" দুটোই রসিদের ফর্ম
    final formType = type == _otherIncome ? VoucherTypes.receipt : type;
    // ⭐ পরিশোধ আর খরচ — ওয়েবের টাকা দেওয়ার পর্দার গড়নে ([MoneyVoucherScreen]; মালিক, ২১ ও ২৪ অক্টোবর ২০২৬); বাকিরা আগের ফর্মে
    final landed = await Navigator.of(context).push<String>(MaterialPageRoute(
        builder: (_) => formType == VoucherTypes.payment || formType == VoucherTypes.expense
            ? MoneyVoucherScreen(type: formType, api: widget.api, newChangeId: widget.newChangeId)
            : VoucherFormScreen(type: formType, api: widget.api, newChangeId: widget.newChangeId)));
    if (!mounted) return;
    await _load();
    if (landed != null && mounted) {
      await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => VoucherScreen(id: landed, api: widget.api)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final total = _page?.total;

    return Scaffold(
      appBar: AbosScreenBar(
          title: !_preset
              ? context.l10n.vouchersListTitle
              : _view.type == null
                  ? context.l10n.vouchersListAllTitle
                  : _view.label),
      bottomNavigationBar: ActionBottomBar.maybe(
          action: widget.canCreate
              ? BarFab(
                  key: const ValueKey('voucher-new'),
                  onPressed: _new,
                  label: context.l10n.vouchersListNew,
                )
              : null),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
              for (final v in _views)
                ChoiceChip(
                    key: ValueKey('voucher-view-${v.key}'),
                    label: Text(v.label),
                    selected: _view.key == v.key,
                    onSelected: (_) {
                      setState(() => _view = v);
                      _load();
                    }),
              FilterChip(
                  key: const ValueKey('voucher-awaiting'),
                  label: Text(context.l10n.vouchersListAwaitingSign),
                  selected: _awaiting,
                  onSelected: (v) {
                    setState(() => _awaiting = v);
                    _load();
                  }),
            ]),
            const SizedBox(height: AppSpacing.xs),
            DateRangeBar(
              from: _from,
              to: _to,
              today: widget.today,
              onChanged: (from, to) {
                setState(() {
                  _from = from;
                  _to = to;
                });
                _load();
              },
            ),
            // ⓘ শাখা — নাগালে একাধিক থাকলে তবেই
            if (_branches.length > 1)
              Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
                ChoiceChip(
                    key: const ValueKey('voucher-branch-all'),
                    label: Text(context.l10n.vouchersListAllBranches),
                    selected: _branch == null,
                    onSelected: (_) {
                      setState(() => _branch = null);
                      _load();
                    }),
                for (final b in _branches)
                  ChoiceChip(
                      key: ValueKey('voucher-branch-${b.publicId}'),
                      label: Text(b.name),
                      selected: _branch?.publicId == b.publicId,
                      onSelected: (_) {
                        setState(() => _branch = b);
                        _load();
                      }),
              ]),
            // ⭐ ছাঁকা তালিকার মোট — সার্ভারের গোনা, পুরো ফলের (পাতার নয়)
            if (total != null)
              TotalStrip(
                  label: context.l10n.vouchersListTotal(_view.label),
                  value: Money.taka(total),
                  count: _page?.count,
                  period: TotalsPeriod.range(_from, _to, today: widget.today)),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (_page != null && _rows.isEmpty && !_busy)
              Padding(padding: const EdgeInsets.all(AppSpacing.lg), child: Center(child: Text(context.l10n.vouchersListEmpty))),
            for (final v in _rows)
              Card(
                child: ListTile(
                  key: ValueKey('voucher-${v.id}'),
                  title: Text('${v.no} · ${v.kindLabel}'),
                  subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(Money.taka(v.amount), style: const TextStyle(fontWeight: FontWeight.w700)),
                    // ⭐ অবস্থা — অ্যাপের এক চিপ: আইকন + রং + লেখা (নকশা ৩, অংশ ৫)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 2),
                      child: voucherStateChip(v, key: ValueKey('voucher-state-${v.id}'), dense: true),
                    ),
                    if (v.party != null) Text('${v.partyWords}: ${v.party!}'),
                    // ⭐ টাকা কোন খাতে — জমা/থেকে/দুই দিক (মালিক, ১৭ অক্টোবর ২০২৬); পুরনো সার্ভারে কিছুই নয়
                    if (v.moneyLine case final money?) Text(money, key: ValueKey('voucher-money-${v.id}')),
                    if (v.writtenBy.isNotEmpty) Text(context.l10n.vouchersListWrittenBy(v.writtenBy)),
                    if (v.date != null) Text(dayOf(v.date)),
                    // ⭐ ওয়েবের মতো "অ্যাকশন ▾" — দেখা · ছাপা ও পাঠানো · কাজ (মালিক, ৮ নভেম্বর ২০২৬) — সারির ডান কিনারে, তারিখের নিচে (৯ নভেম্বর: ৪৮dp হলে পাশের ঘরে নম্বর ভাঙত)
                    Align(
                      alignment: AlignmentDirectional.centerEnd,
                      child: RowActionsButton(
                        number: v.no,
                        state: v.stateLabel,
                        onView: () => _openVoucher(v.id),
                        paperType: 'Voucher',
                        paperId: v.id,
                        paperTitle: '${v.kindLabel} ${v.no}',
                        work: [
                          RowAction(
                            key: 'confirm',
                            label: context.l10n.rowActionsConfirmOnPage,
                            icon: Icons.task_alt_outlined,
                            onTap: v.state == 'draft' ? () => _openVoucher(v.id) : null,
                            why: switch (v.state) {
                              'draft' => null,
                              'awaiting' => context.l10n.rowActionsWhyAwaiting,
                              _ => context.l10n.rowActionsWhyNotDraft,
                            },
                          ),
                        ],
                      ),
                    ),
                  ]),
                  onTap: () => _openVoucher(v.id),
                ),
              ),
            if (_page?.nextPage != null) MoreButton(busy: _busy, onPressed: () => _load(more: true)),
            const SizedBox(height: 80),
          ],
        ),
      ),
    );
  }
}

class VoucherScreen extends StatefulWidget {
  const VoucherScreen({super.key, required this.id, this.api = const ServerVoucherApi()});

  final String id;
  final VoucherApi api;

  @override
  State<VoucherScreen> createState() => _VoucherScreenState();
}

class _VoucherScreenState extends State<VoucherScreen> {
  VoucherPage? _page;
  String? _error;
  String? _notice;
  bool _busy = false;
  final _trx = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _trx.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final page = await widget.api.one(widget.id);
      if (mounted) setState(() => _page = page);
      // ⭐ খোলা কাগজ "সাম্প্রতিক"-এ (মালিক, ১২ অক্টোবর ২০২৬)
      RecentPapers.note(kind: 'voucher', id: widget.id, number: page.row.no);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.vouchersPageLoadFailed));
    }
  }

  Future<void> _post() async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    try {
      final answer = await widget.api.post(widget.id, instrumentNo: _trx.text);
      if (!mounted) return;
      setState(() {
        if (answer.posted) {
          _notice = answer.message;
        } else {
          _error = answer.message;
        }
        if (answer.page != null) _page = answer.page;
      });
    } catch (e) {
      // ⭐ সার্ভারের পুরো কথা, ফর্মের ঘরের নয় এমন চাবিরও (সই-না-পাওয়া ব্যাংক: `payee_bank`); খসড়া খসড়াই থাকে, পাতা যেমন ছিল
      if (mounted) {
        setState(() => _error =
            isNetworkError(e) ? errorMessageFor(e, fallback: context.l10n.vouchersPagePostFailed) : voucherRefusal(e) ?? context.l10n.vouchersPagePostFailed);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool get _needsTrx {
    final i = _page?.instrument;
    return (i == 'mfs' || i == 'transfer') && (_page?.instrumentNo ?? '').isEmpty;
  }

  @override
  Widget build(BuildContext context) {
    final p = _page;
    return Scaffold(
      appBar: AbosScreenBar(title: p?.row.no ?? context.l10n.vouchersListTitle, actions: [
        if (p != null)
          IconButton(
            key: const ValueKey('voucher-print'),
            tooltip: context.l10n.vouchersPagePrint,
            icon: const Icon(Icons.print_outlined),
            onPressed: () => DocumentActionsSheet.show(context, type: 'Voucher', id: p.row.id, title: '${p.row.kindLabel} ${p.row.no}'),
          ),
      ]),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_error != null) BooksError(_error!),
          if (_notice != null)
            Card(
                color: context.palette.successSurface,
                child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.sm),
                    child: Text(_notice!, key: const ValueKey('voucher-notice'), style: TextStyle(color: context.palette.success)))),
          if (p == null && _error == null) const LinearProgressIndicator(),
          if (p != null) ...[
            FactRow(context.l10n.vouchersPageType, p.row.kindLabel),
            PaperStateFact(chip: voucherStateChip(p.row, key: const ValueKey('voucher-state'))),
            FactRow(context.l10n.vouchersAmount, Money.taka(p.row.amount)),
            // ⭐ নিট — সার্ভারের হিসাব (চুক্তি ২), পুরনো সার্ভারে নেই, ফোন নিজে গোনে না
            if (p.grossAmount case final gross?) FactRow(context.l10n.payGrossShort, Money.taka(gross), key: const ValueKey('voucher-gross')),
            if (p.netAmount case final net?) FactRow(context.l10n.vouchersPageNet, Money.taka(net), key: const ValueKey('voucher-net')),
            if (p.row.date != null) FactRow(context.l10n.vouchersDate, dayOf(p.row.date)),
            if (p.row.party != null) FactRow(p.row.partyWords, p.row.party!),
            // ⭐ টাকা কোন খাতে — আদায়ে "জমা হয়েছে", পরিশোধে "যেখান থেকে দেওয়া", কনট্রায় দুই দিক (মালিক, ১৭ অক্টোবর ২০২৬)
            for (final (label, account) in p.moneyFacts) FactRow(label, account),
            if ((p.instrumentNo ?? '').isNotEmpty) FactRow(context.l10n.vouchersTrxNo, p.instrumentNo!),
            if (p.row.narration.isNotEmpty) FactRow(context.l10n.vouchersNarration, p.row.narration),
            if (p.writtenBy.isNotEmpty) FactRow(context.l10n.vouchersPageWriter, p.writtenBy),
            const Divider(),
            for (final l in p.lines)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(l.account),
                // ⓘ সারির পক্ষ আর বিবরণ এক লাইনে
                subtitle:
                    l.narration.isEmpty && l.party == null ? null : Text([if (l.party != null) l.party!, if (l.narration.isNotEmpty) l.narration].join(' · ')),
                trailing: Text(l.debit > 0 ? context.l10n.vouchersDebitLine(Money.taka(l.debit)) : context.l10n.vouchersCreditLine(Money.taka(l.credit))),
              ),
            if (p.awaitsAnotherHand)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child:
                    Text(context.l10n.vouchersPageAnotherHand, key: const ValueKey('voucher-another-hand'), style: TextStyle(color: context.palette.pending)),
              ),
            if (p.canPost) ...[
              if (_needsTrx)
                TextField(
                  key: const ValueKey('voucher-trx'),
                  controller: _trx,
                  decoration: InputDecoration(labelText: context.l10n.vouchersPageTrxLabel),
                ),
              const SizedBox(height: AppSpacing.sm),
              FilledButton.icon(
                key: const ValueKey('voucher-post'),
                onPressed: _busy ? null : _post,
                icon: const Icon(Icons.check_circle_outline),
                label: Text(context.l10n.vouchersPagePost),
              ),
            ],
          ],
        ],
      ),
    );
  }
}

class VoucherFormScreen extends StatefulWidget {
  const VoucherFormScreen({
    super.key,
    required this.type,
    this.api = const ServerVoucherApi(),
    this.newChangeId = VoucherListScreen._engineChangeId,
  });

  final String type;
  final VoucherApi api;
  final String Function() newChangeId;

  @override
  State<VoucherFormScreen> createState() => _VoucherFormScreenState();
}

class _JournalLine {
  VoucherAccount? account;
  final debit = TextEditingController();
  final credit = TextEditingController();
  VoucherParty? party;
}

class _VoucherFormScreenState extends State<VoucherFormScreen> {
  static final _amountShape = RegExp(r'^\d{1,14}(\.\d{1,2})?$');

  VoucherSetup? _setup;
  VoucherAccount? _from;
  VoucherAccount? _to;
  VoucherParty? _party;
  late DateTime _date;
  final _amount = TextEditingController();
  final _narration = TextEditingController();
  final _trx = TextEditingController();
  final List<_JournalLine> _lines = [_JournalLine(), _JournalLine()];
  bool _keepAsDraft = false;
  bool _busy = false;
  String? _error;
  String? _changeId;

  bool get _journal => widget.type == VoucherTypes.journal;

  @override
  void initState() {
    super.initState();
    _date = DateTime.now();
    for (final c in [_amount, _narration, _trx]) {
      c.addListener(_typed);
    }
    widget.api.setup(widget.type).then((s) {
      if (mounted) {
        setState(() {
          _setup = s;
          _date = s.today;
        });
      }
    }, onError: (Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.vouchersFormSetupFailed));
    });
  }

  @override
  void dispose() {
    _amount.dispose();
    _narration.dispose();
    _trx.dispose();
    for (final l in _lines) {
      l.debit.dispose();
      l.credit.dispose();
    }
    super.dispose();
  }

  /// টাকার দিক — আদায়ে "কোথায়", বাকিগুলোয় "কোথা থেকে"
  VoucherAccount? get _moneySide => widget.type == VoucherTypes.receipt ? _to : _from;

  bool get _needsTrx {
    final i = _moneySide?.instrument;
    return widget.type != VoucherTypes.contra && (i == 'mfs' || i == 'transfer');
  }

  double _sum(Iterable<TextEditingController> cs) => cs.fold(0, (s, c) => s + (double.tryParse(c.text.trim()) ?? 0));

  String? _problem() {
    final t = context.l10n;
    if (_setup == null) return t.vouchersFormSetupMissing;
    if (_setup!.narrationRequired && _narration.text.trim().isEmpty) return t.vouchersFormNarrationRequired;
    if (_journal) {
      final filled = _lines.where((l) => l.account != null).toList();
      if (filled.length < 2) return t.vouchersFormTwoLines;
      for (final l in filled) {
        final d = l.debit.text.trim(), c = l.credit.text.trim();
        if ((d.isNotEmpty && !_amountShape.hasMatch(d)) || (c.isNotEmpty && !_amountShape.hasMatch(c))) {
          return t.vouchersFormAmountShape;
        }
        if ((double.tryParse(d) ?? 0) > 0 && (double.tryParse(c) ?? 0) > 0) return t.vouchersFormBothSides;
      }
      final dr = _sum(filled.map((l) => l.debit)), cr = _sum(filled.map((l) => l.credit));
      if (dr <= 0 || (dr - cr).abs() > 0.004) return t.vouchersFormUnbalanced;
      return null;
    }
    if (_from == null || _to == null) return t.vouchersFormPickAccounts;
    if (_from!.id == _to!.id) return t.vouchersFormSameAccount;
    final a = _amount.text.trim();
    if (!_amountShape.hasMatch(a) || (double.tryParse(a) ?? 0) <= 0) return t.vouchersFormAmountInvalid;
    return null;
  }

  VoucherDraft _draft() => VoucherDraft(
        type: widget.type,
        date: _date,
        narration: _narration.text,
        fromAccountId: _from?.id,
        toAccountId: _to?.id,
        amount: _amount.text.trim(),
        party: _party?.key,
        instrument: widget.type == VoucherTypes.contra ? null : _moneySide?.instrument,
        instrumentNo: _needsTrx ? _trx.text : null,
        keepAsDraft: _keepAsDraft,
        lines: [
          for (final l in _lines.where((l) => l.account != null))
            {
              'account_id': l.account!.id,
              'debit': l.debit.text.trim().isEmpty ? '0' : l.debit.text.trim(),
              'credit': l.credit.text.trim().isEmpty ? '0' : l.credit.text.trim(),
              if (l.party != null) 'party': l.party!.key,
            },
        ],
      );

  Future<void> _send() async {
    final problem = _problem();
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final changeId = _changeId ??= widget.newChangeId();
    try {
      final outcome = await widget.api.send(_draft(), changeId);
      if (!mounted) return;
      if (outcome.landedId != null) {
        Navigator.of(context).pop(outcome.landedId);
        return;
      }
      setState(() {
        _error = outcome.refusal ?? context.l10n.vouchersFormNotSaved;
        _changeId = null;
      });
    } on NoNetworkForThis catch (e) {
      if (mounted) {
        setState(() => _error = e.mayHaveLanded ? context.l10n.vouchersFormMayHaveLanded : context.l10n.vouchersFormNeedsNet);
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.vouchersFormSendFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<T?> _pick<T>(String title, List<T> options, String Function(T) label, {String? key}) => showModalBottomSheet<T>(
        context: context,
        isScrollControlled: true,
        builder: (_) => VoucherPickSheet<T>(title: title, options: options, label: label, keyPrefix: key),
      );

  Future<void> _pickDate() async {
    final today = _setup?.today ?? DateTime.now();
    final picked = await showDatePicker(context: context, initialDate: _date, firstDate: today.subtract(const Duration(days: 365)), lastDate: today);
    if (picked != null && mounted) setState(() => _date = picked);
  }

  Widget _accountTile(String key, String label, VoucherAccount? value, List<VoucherAccount> options, ValueChanged<VoucherAccount> set) => Card(
        child: ListTile(
          key: ValueKey(key),
          title: Text(value?.label ?? label),
          subtitle: value == null ? null : Text(label),
          trailing: const Icon(Icons.search),
          onTap: _busy
              ? null
              : () async {
                  final a = await _pick<VoucherAccount>(label, options, (x) => x.label, key: key);
                  if (a != null) setState(() => set(a));
                },
        ),
      );

  Widget _partyTile(String key, VoucherParty? value, ValueChanged<VoucherParty?> set) => Card(
        child: ListTile(
          key: ValueKey(key),
          title: Text(value?.label ?? context.l10n.vouchersFormPartyOptional),
          trailing: value == null
              ? const Icon(Icons.search)
              : IconButton(tooltip: context.l10n.vouchersFormClear, icon: const Icon(Icons.close), onPressed: () => setState(() => set(null))),
          onTap: _busy
              ? null
              : () async {
                  final p = await _pick<VoucherParty>(context.l10n.vouchersFormParty, _setup?.parties ?? const [], (x) => x.label, key: key);
                  if (p != null) setState(() => set(p));
                },
        ),
      );

  void _typed() {
    if (mounted) setState(() {});
  }

  /// লেখা আছে: খাত, পক্ষ, টাকা, বিবরণ, TrxID বা জাবেদার কোনো সারি
  bool get _dirty =>
      !_busy &&
      (_from != null ||
          _to != null ||
          _party != null ||
          [_amount, _narration, _trx].any((c) => c.text.trim().isNotEmpty) ||
          _lines.any((l) => l.account != null || l.party != null || l.debit.text.trim().isNotEmpty || l.credit.text.trim().isNotEmpty));

  // ⭐ খাত, টাকা বা বিবরণ লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা ভাউচার এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _dirty, child: _screen(context));

  Widget _screen(BuildContext context) {
    final s = _setup;
    return Scaffold(
      appBar: AbosScreenBar(title: VoucherTypes.label(widget.type)),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy || (s == null && _error == null)) const LinearProgressIndicator(),
          if (_error != null)
            Card(
                key: const ValueKey('voucher-error'),
                color: context.palette.dangerSurface,
                child: Padding(padding: const EdgeInsets.all(AppSpacing.sm), child: Text(_error!, style: TextStyle(color: context.palette.danger)))),
          if (s?.cashHiddenReason != null) Text(s!.cashHiddenReason!, style: TextStyle(color: context.palette.warning, fontSize: 12.5)),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(context.l10n.vouchersDate),
            subtitle: Text('${_date.day.toString().padLeft(2, '0')}/${_date.month.toString().padLeft(2, '0')}/${_date.year}'),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: _busy ? null : _pickDate,
          ),
          if (s != null && !_journal) ...[
            _accountTile('voucher-from', s.from?.label ?? context.l10n.vouchersFormFrom, _from, s.from?.accounts ?? const [], (a) => _from = a),
            _accountTile('voucher-to', s.to?.label ?? context.l10n.vouchersFormTo, _to, s.to?.accounts ?? const [], (a) => _to = a),
            TextField(
              key: const ValueKey('voucher-amount'),
              controller: _amount,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(labelText: context.l10n.vouchersAmount),
            ),
            _partyTile('voucher-party', _party, (p) => _party = p),
            if (_needsTrx)
              TextField(
                key: const ValueKey('voucher-form-trx'),
                controller: _trx,
                decoration: InputDecoration(labelText: context.l10n.vouchersFormTrxLabel),
              ),
          ],
          if (s != null && _journal) ...[
            for (var i = 0; i < _lines.length; i++)
              Card(
                key: ValueKey('voucher-line-$i'),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Column(children: [
                    _accountTile('voucher-line-$i-account', context.l10n.vouchersFormAccount, _lines[i].account, s.accounts, (a) => _lines[i].account = a),
                    Row(children: [
                      Expanded(
                          child: TextField(
                              key: ValueKey('voucher-line-$i-debit'),
                              controller: _lines[i].debit,
                              keyboardType: const TextInputType.numberWithOptions(decimal: true),
                              decoration: InputDecoration(labelText: context.l10n.vouchersFormDebit),
                              onChanged: (_) => setState(() {}))),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                          child: TextField(
                              key: ValueKey('voucher-line-$i-credit'),
                              controller: _lines[i].credit,
                              keyboardType: const TextInputType.numberWithOptions(decimal: true),
                              decoration: InputDecoration(labelText: context.l10n.vouchersFormCredit),
                              onChanged: (_) => setState(() {}))),
                    ]),
                    _partyTile('voucher-line-$i-party', _lines[i].party, (p) => _lines[i].party = p),
                  ]),
                ),
              ),
            Row(children: [
              TextButton.icon(
                  key: const ValueKey('voucher-add-line'),
                  onPressed: () => setState(() => _lines.add(_JournalLine())),
                  icon: const Icon(Icons.add),
                  label: Text(context.l10n.vouchersFormAddLine)),
              const Spacer(),
              Text(context.l10n.vouchersFormTotals(Money.taka(_sum(_lines.map((l) => l.debit))), Money.taka(_sum(_lines.map((l) => l.credit)))),
                  key: const ValueKey('voucher-totals')),
            ]),
          ],
          TextField(
            key: const ValueKey('voucher-narration'),
            controller: _narration,
            maxLength: 500,
            decoration: InputDecoration(labelText: s?.narrationRequired == true ? context.l10n.vouchersFormNarrationMust : context.l10n.vouchersNarration),
          ),
          SwitchListTile(
            key: const ValueKey('voucher-keep-draft'),
            contentPadding: EdgeInsets.zero,
            value: _keepAsDraft,
            onChanged: _busy ? null : (v) => setState(() => _keepAsDraft = v),
            title: Text(context.l10n.vouchersFormKeepDraft),
            subtitle: Text(context.l10n.vouchersFormKeepDraftHint),
          ),
          const SizedBox(height: AppSpacing.sm),
          FilledButton.icon(
            key: const ValueKey('voucher-send'),
            onPressed: _busy || s == null ? null : _send,
            icon: const Icon(Icons.save_outlined),
            label: Text(context.l10n.vouchersFormSave),
          ),
        ],
      ),
    );
  }
}

class VoucherPickSheet<T> extends StatefulWidget {
  const VoucherPickSheet({super.key, required this.title, required this.options, required this.label, this.keyPrefix});

  final String title;
  final List<T> options;
  final String Function(T) label;
  final String? keyPrefix;

  @override
  State<VoucherPickSheet<T>> createState() => _VoucherPickSheetState<T>();
}

class _VoucherPickSheetState<T> extends State<VoucherPickSheet<T>> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final q = _q.trim().toLowerCase();
    // ⭐ পক্ষ — সার্ভারের পক্ষ-খোঁজার নিয়মে ([partyMatches]: প্রতিটা শব্দ নাম/পয়েন্ট/শাখা বা পাশের লেখায়, মোবাইলের শেষ ৪ অঙ্কেও)
    final rows = widget.options
        .where((o) => q.isEmpty ||
            (o is VoucherParty
                ? partyMatches(q, fields: [widget.label(o), o.label, o.name, o.hint, o.find], phones: [o.hint, o.find])
                : widget.label(o).toLowerCase().contains(q)))
        .take(100)
        .toList();
    return SafeArea(
      child: Padding(
        padding:
            EdgeInsets.only(left: AppSpacing.md, right: AppSpacing.md, top: AppSpacing.md, bottom: MediaQuery.of(context).viewInsets.bottom + AppSpacing.md),
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.7,
          child: Column(children: [
            TextField(
              autofocus: true,
              decoration: InputDecoration(labelText: widget.title, prefixIcon: const Icon(Icons.search)),
              onChanged: (v) => setState(() => _q = v),
            ),
            Expanded(
              child: rows.isEmpty
                  ? Center(child: Text(context.l10n.vouchersPickNothing))
                  : ListView(children: [
                      for (var i = 0; i < rows.length; i++)
                        ListTile(
                          key: ValueKey('${widget.keyPrefix ?? 'pick'}-option-$i'),
                          title: Text(widget.label(rows[i])),
                          onTap: () => Navigator.of(context).pop(rows[i]),
                        ),
                    ]),
            ),
          ]),
        ),
      ),
    );
  }
}
