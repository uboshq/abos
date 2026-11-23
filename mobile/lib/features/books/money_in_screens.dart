import 'package:flutter/material.dart';

import '../../core/navigation/recent_papers.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/books/books_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/status/app_status_chip.dart';
import '../../core/status/paper_state.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/search_debouncer.dart';
import '../../core/widgets/shown_rows.dart';
import '../../core/widgets/state_views.dart';
import '../../core/widgets/totals_bar.dart';
import '../home/nav_bottom_bar.dart' show ActionBottomBar, BarFab;
import '../home/owner_home_cards.dart';
import '../printing/document_actions_sheet.dart';
import '../printing/row_actions.dart';
import 'books_widgets.dart';
import '../customers/deposit_request_screen.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';

/// ⭐ টাকা আদায় (Payment received) — মালিক, ৬ অক্টোবর ২০২৬।
///
/// <p>আদায়ের তালিকা: তারিখের পরিসর, গ্রাহক বা দোকান বা নম্বর খুঁজে, পদ্ধতি (নগদ · ব্যাংক · MFS · চেক), মাথায় মোট;
/// একটা চাপলে বিস্তারিত — কোন বিলে কত বসল। কেবল পড়া। SR-এর ফোনে কেবল তাঁর নিজের ডিলারের — সার্ভারের দেয়াল।
class MoneyInListScreen extends StatefulWidget {
  const MoneyInListScreen(
      {super.key,
      this.api = const ServerBooksApi(),
      this.today,
      this.canReportDeposit = false,
      this.dues,
      this.unsent,
      this.reportDeposit});

  final BooksApi api;
  final DateTime? today;

  /// ⭐ "টাকা জমার খবর" বোতাম — জমার খবরের চাবি (`sales.claim.submit`) থাকলে। ⛔ ফোনে "নতুন আদায়" আর নেই (মালিক, ২০ নভেম্বর
  /// ২০২৬: টাকা নেওয়ার এক কাগজ — কাউন্টার আর হিসাবের রসিদ; মাঠ থেকে কেবল জমার খবর)। এই তালিকা কেবল দেখা।
  final bool canReportDeposit;

  /// ⭐ দোকানের বকেয়া (০.৪.৪১ নমুনা) — ফোনে রাখা বকেয়া থেকে ([shopDuesInCache]); টেস্ট নিজের সারি দেয়
  final List<ShopDue> Function()? dues;

  /// ফোনে আটকে থাকা আদায় ([unsentCollections]); টেস্ট নিজের সারি দেয়
  final List<UnsentCollection> Function()? unsent;

  /// "টাকা জমার খবর" খোলে জমার খবরের পাতা ([DepositRequestScreen], দোকান পাতার ভেতরে বাছা); টেস্ট নিজের পাতা দেয়
  final Widget Function()? reportDeposit;

  /// অ্যাপের আসল জমার খবরের পাতা — পরীক্ষাও এটা দেখে যে এখানে আদায়ের পাতা আর বসে না
  static Widget defaultReportDeposit() => const DepositRequestScreen();

  @override
  State<MoneyInListScreen> createState() => _MoneyInListScreenState();
}

class _MoneyInListScreenState extends State<MoneyInListScreen> {
  late DateTime _to;
  late DateTime _from;
  String? _method;
  final _search = TextEditingController();
  late final _typed = SearchDebouncer(onSearch: (_) => _load());

  final List<MoneyInRow> _rows = [];
  MoneyInPage? _page;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _to = DateTime(now.year, now.month, now.day);
    _from = DateTime(now.year, now.month);
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _typed.dispose();
    super.dispose();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.moneyIn(
        from: isoOf(_from),
        to: isoOf(_to),
        q: _search.text.trim(),
        method: _method,
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
        setState(() => _error = errorMessageFor(e,
            fallback: AppLocale.l10n.booksMoneyInListFailed,
            whenAbsent: AppLocale.l10n.booksMoneyInListAbsent));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    // ⭐ এই পাতা = দেখানো সারি, "আরও দেখুন"-এর সব খেপ ([ShownRows], অ্যাপজুড়ে এক মানে); সর্বমোট = সার্ভারের গোটা ছাঁকনির
    // `count` আর `total` (নকশা ৩, পাতা ৩)। ⓘ ফোনে আটকে থাকা আদায় সারিতে থাকে, এই যোগে নয় — সার্ভারে এখনো পৌঁছায়নি।
    final shown = ShownRows.of(_rows, (r) => r.amount);
    final unsent = (widget.unsent ?? unsentCollections)();
    return Scaffold(
      // ⭐ ভেতরের পর্দার বার — "কোম্পানি · শাখা › বিক্রয় › আদায়" (০.৪.৪১ নমুনা, পাতা ২); অফলাইনের হলুদ পট্টি বার নিজে আঁকে
      appBar: AbosScreenBar(title: context.l10n.booksMoneyInTitle, module: context.l10n.booksModuleSales),
      // ⭐ "টাকা জমার খবর" — সব পাতার একই [BarFab], কেবল জমার খবরের চাবি থাকলে; নিচের বারে মোটের পাশে
      // ([ActionBottomBar]) — তালিকার কোনো সারি বা মোট কখনো ঢাকে না
      bottomNavigationBar: ActionBottomBar.maybe(
          totals: page == null || _rows.isEmpty
              ? null
              : TotalsBar(pageCount: shown.count, pageSum: shown.sum, allCount: page.count, allSum: page.total,
                  period: TotalsPeriod.range(_from, _to, today: widget.today)),
          action: widget.canReportDeposit
              ? BarFab(key: const ValueKey('money-in-deposit-report'), label: context.l10n.lookQuickDeposit, onPressed: _reportDeposit)
              : null),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
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
            TextField(
              key: const ValueKey('money-in-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                  prefixIcon: const Icon(Icons.search),
                  hintText: context.l10n.booksMoneyInSearchHint),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
            const SizedBox(height: AppSpacing.xs),
            Wrap(spacing: AppSpacing.xs, children: [
              ChoiceChip(
                key: const ValueKey('method-all'),
                label: Text(context.l10n.commonAll),
                selected: _method == null,
                onSelected: (_) {
                  setState(() => _method = null);
                  _load();
                },
              ),
              for (final m in MoneyInRow.methods)
                ChoiceChip(
                  key: ValueKey('method-$m'),
                  label: Text(MoneyInRow.methodLabel(m)),
                  selected: _method == m,
                  onSelected: (_) {
                    setState(() => _method = m);
                    _load();
                  },
                ),
            ]),
            // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০): প্রথম আনায় খসড়া সারি, পরে উপরে সরু দাগ; ভুলে "আবার চেষ্টা করুন"; খালিতে কাজের বোতাম
            const SizedBox(height: AppSpacing.sm),
            if (_busy && page == null) const LoadingSkeleton(),
            if (_busy && page != null) const LinearProgressIndicator(),
            if (_error != null)
              ErrorState(key: const ValueKey('money-in-error'), message: _error!, onRetry: _busy ? null : _load),
            if (page != null && _rows.isEmpty && !_busy && _error == null)
              EmptyState(
                key: const ValueKey('money-in-empty'),
                icon: Icons.savings_outlined,
                title: context.l10n.booksMoneyInEmptyTitle,
                message: context.l10n.booksMoneyInEmptyHint,
                // ⓘ জমার খবরের চাবি থাকলে সোজা টাকা জমার খবর; বাকিরা নতুন করে আনেন
                action: widget.canReportDeposit
                    ? FilledButton.icon(
                        key: const ValueKey('money-in-empty-deposit-report'),
                        style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
                        onPressed: _reportDeposit,
                        icon: const Icon(Icons.add),
                        label: Text(context.l10n.lookQuickDeposit))
                    : OutlinedButton.icon(
                        key: const ValueKey('money-in-empty-refresh'),
                        style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                        onPressed: _load,
                        icon: const Icon(Icons.refresh),
                        label: Text(context.l10n.booksRefresh)),
              ),
            // ⭐ ০.৪.৪১ নমুনা (a4: বাঁয়ে নম্বর · পক্ষ, ডানে টাকা আর চিপ) — এক কার্ডে; ফোনে আটকে থাকা আদায় আগে, "পাঠানো বাকি"
            if (_rows.isNotEmpty || unsent.isNotEmpty)
              HomeSectionCard(
                key: const ValueKey('money-in-rows'),
                title: _isToday ? context.l10n.booksMoneyInToday : context.l10n.booksMoneyInTitle,
                children: [
                  for (final (i, u) in unsent.indexed)
                    PaperRow(
                      key: ValueKey('money-in-unsent-$i'),
                      no: '',
                      party: u.party,
                      amount: u.amount,
                      chip: const AppStatusChip.compact(state: PaperState.unsent, kind: PaperKind.money),
                    ),
                  for (final row in _rows)
                    PaperRow(
                      key: ValueKey('money-in-${row.no}'),
                      no: row.no,
                      party: row.customer.isEmpty ? '—' : row.customer,
                      detail: _isToday
                          ? MoneyInRow.methodLabel(row.method)
                          : '${dayOf(row.date)} · ${MoneyInRow.methodLabel(row.method)}',
                      amount: row.amount,
                      // ⓘ তালিকা কেবল পাকা আদায়ের (সার্ভার POSTED ছাঁকে) — তাই "পাকা"
                      chip: const AppStatusChip.compact(state: PaperState.posted, kind: PaperKind.money),
                      onTap: () => _open(row.id),
                      // ⭐ "অ্যাকশন ▾" — দেখুন · ছাপা ও পাঠানো (সার্ভারের নিজের টাকার রসিদ, বিবরণ পাতার ছাপার একই ধরন), নমুনা v4;
                      //   পাকা আদায় বদলানো যায় না, তাই কাজের ভাগ নেই। ⓘ ০.৪.৫১-এ বাদ ছিল (দোকানের বাকির মোট স্থির অবস্থায় বোতামের
                      //   নিচে যেত); "＋ নতুন আদায়" নিচের বারে বাঁধা, তালিকা বারের ওপরে শেষ হয় — শেষ পর্যন্ত গেলে কিছুই ঢাকা নয়
                      actions: RowActionsButton(
                        number: row.no,
                        state: PaperState.posted.spec.label(kind: PaperKind.money, english: AppLocale.isEnglish()),
                        onView: () => _open(row.id),
                        paperType: 'Collection',
                        paperId: row.id,
                        paperTitle: context.l10n.booksMoneyInPrintTitle(row.no),
                      ),
                    ),
                ],
              ),
            if (page?.nextPage != null)
              MoreButton(busy: _busy, onPressed: () => _load(more: true)),
            // ⓘ কার্ডের ফাঁক কার্ডের নিজের নিচের মার্জিন (৮dp), অন্য কার্ডের মতো — ⛔ আগে এখানে আরও ৮dp, তাতে ৪১২dp ফোনে স্থির অবস্থায়
            // "＋ নতুন আদায়" "মোট · nটি দোকান"-এর সারির নিচের ৬dp ঢাকত (সমন্বয়ক, ২৪ অক্টোবর ২০২৬; totals_bar_keeps_its_height_test)
            ShopDuesCard(dues: (widget.dues ?? shopDuesInCache)()),
          ],
        ),
      ),
    );
  }

  void _open(String id) =>
      Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => MoneyInScreen(id: id, api: widget.api)));

  bool get _isToday {
    final now = widget.today ?? DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    return _from == today && _to == today;
  }

  Future<void> _reportDeposit() async {
    await Navigator.of(context)
        .push(MaterialPageRoute<void>(builder: (_) => widget.reportDeposit?.call() ?? MoneyInListScreen.defaultReportDeposit()));
    if (mounted) await _load();
  }
}

/// ফোনে রাখা, এখনো না-পাঠানো একটা আদায়।
class UnsentCollection {
  const UnsentCollection(this.party, this.amount);

  final String party;
  final double? amount;
}

/// সিঙ্কের সারিতে আটকে থাকা আদায় — ⓘ আজ আদায় কেবল নেটে যায় ([SyncEngine.pushNow], মালিকের নিয়ম "নেট না থাকলে শুধু
/// অর্ডার"), তাই এটা সাধারণত খালি; সারিতে কিছু থাকলে সেটা লুকানো হয় না, "পাঠানো বাকি" চিপে দেখায়। সার্ভারের মোটে নেই।
List<UnsentCollection> unsentCollections() => [
      for (final p in SyncEngine.instance.pendingPayloadsOf('Collection'))
        UnsentCollection(CustomerRecord.byId((p['customerId'] ?? '').toString())?.label ?? '—', Money.value(p['amount'])),
    ];

/// একটা দোকানের বকেয়া — নাম আগেই "নাম · পয়েন্ট · শাখা" ([CustomerRecord.label])।
class ShopDue {
  const ShopDue(this.label, this.amount);

  final String label;
  final double amount;
}

/// ফোনে রাখা বকেয়া — দেখা শাখার, শূন্যের বেশি, বড়টা আগে (বকেয়া তালিকার একই নিয়ম)। SR-এর ফোনে কেবল তাঁর ডিলার — সার্ভারের দেয়াল।
List<ShopDue> shopDuesInCache() => [
      for (final c in CustomerRecord.all())
        if (CustomerDueRecord.forCustomer(c.id) case final d? when d.outstandingInView > 0) ShopDue(c.label, d.outstandingInView),
    ]..sort((a, b) => b.amount.compareTo(a.amount));

/// ⭐ দোকানের বকেয়া (০.৪.৪১ নমুনা): প্রথম [shown]টা দোকান, নিচে সব দোকানের মোট। কোনো বকেয়া না থাকলে কার্ড নেই।
class ShopDuesCard extends StatelessWidget {
  const ShopDuesCard({super.key, required this.dues, this.shown = 5});

  final List<ShopDue> dues;
  final int shown;

  @override
  Widget build(BuildContext context) {
    if (dues.isEmpty) return const SizedBox.shrink();
    final rest = dues.length - shown;
    return HomeSectionCard(
      key: const ValueKey('shop-dues'),
      title: context.l10n.booksShopDuesTitle,
      children: [
        for (final d in dues.take(shown)) AmountLine(label: d.label, amount: d.amount, labelIsName: true),
        if (rest > 0)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Text(context.l10n.booksShopDuesMore(rest), style: TextStyle(fontSize: 12.5, color: context.palette.onSurfaceMuted)),
          ),
        AmountLine(
            key: const ValueKey('shop-dues-total'),
            label: context.l10n.booksShopDuesTotal(dues.length),
            amount: sumOf(dues.map((d) => d.amount)),
            total: true),
      ],
    );
  }
}

/// একটা আদায় — কে দিল, কোন খাতে, কোন পদ্ধতিতে, কোন বিলে কত
class MoneyInScreen extends StatefulWidget {
  const MoneyInScreen(
      {super.key, required this.id, this.api = const ServerBooksApi()});

  final String id;
  final BooksApi api;

  @override
  State<MoneyInScreen> createState() => _MoneyInScreenState();
}

class _MoneyInScreenState extends State<MoneyInScreen> {
  MoneyInDetail? _detail;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// ⭐ আবার চেষ্টাও এখান থেকেই (নকশা ৩, পাতা ৮) — আগে ভুল হলে পর্দা ফেরার পথ ছাড়া কিছু দিত না
  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await widget.api.moneyInDetail(widget.id);
      if (mounted) setState(() => _detail = d);
      // ⭐ খোলা কাগজ "সাম্প্রতিক"-এ (মালিক, ১২ অক্টোবর ২০২৬)
      RecentPapers.note(kind: 'money-in', id: widget.id, number: d.row.no, party: d.row.customer);
    } catch (e) {
      if (mounted) {
        setState(
            () => _error = errorMessageFor(e, fallback: AppLocale.l10n.booksMoneyInFailed));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _detail;
    return Scaffold(
      appBar: AbosScreenBar(title: d?.row.no ?? context.l10n.booksMoneyInTitle, module: context.l10n.booksModuleSales, actions: [
        // ⭐ টাকার রসিদ ছাপা — নরমাল বা থার্মাল (মালিক, ১৪ অক্টোবর ২০২৬)
        if (d != null)
          IconButton(
            key: const ValueKey('money-in-print'),
            tooltip: context.l10n.booksPrintSend,
            icon: const Icon(Icons.print_outlined),
            onPressed: () => DocumentActionsSheet.show(context,
                type: 'Collection', id: widget.id, title: context.l10n.booksMoneyInPrintTitle(d.row.no), fileStem: d.row.no),
          ),
      ]),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_error != null) ErrorState(key: const ValueKey('money-in-detail-error'), message: _error!, onRetry: _load),
          if (d == null && _error == null) const LoadingSkeleton(rows: 3),
          if (d != null) ...[
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(children: [
                  FactRow(context.l10n.booksFactCustomer, d.row.customer),
                  FactRow(context.l10n.booksFactDate, dayOf(d.row.date)),
                  FactRow(context.l10n.booksFactAmount, Money.taka(d.row.amount)),
                  if (d.statusLabel.isNotEmpty) FactRow(context.l10n.commonStatus, d.statusLabel),
                  FactRow(context.l10n.booksFactMethod, MoneyInRow.methodLabel(d.row.method)),
                  FactRow(context.l10n.booksFactAccount, d.row.account),
                  if (d.instrumentNo.isNotEmpty)
                    FactRow(context.l10n.booksFactNumber, d.instrumentNo),
                  if (d.instrumentDate != null)
                    FactRow(context.l10n.booksFactInstrumentDate, dayOf(d.instrumentDate)),
                  if (d.narration.isNotEmpty) FactRow(context.l10n.booksFactNarration, d.narration),
                  if (d.by.isNotEmpty) FactRow(context.l10n.booksFactWrittenBy, d.by),
                ]),
              ),
            ),
            if (d.lines.isNotEmpty) ...[
              Padding(
                padding:
                    const EdgeInsets.only(top: AppSpacing.md, bottom: AppSpacing.xs),
                child: Text(context.l10n.booksMoneyInLines,
                    style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
              for (final (invoice, amount) in d.lines)
                Card(
                  child: ListTile(
                    title: Text(invoice.isEmpty ? context.l10n.booksMoneyInAdvance : invoice),
                    trailing: Text(Money.taka(amount)),
                  ),
                ),
            ],
          ],
        ],
      ),
    );
  }
}
