import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/books/books_api.dart';
import '../../core/books/collection_entry.dart';
import '../../core/books/collection_plan.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/permissions/camera_shot.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/status/app_status_chip.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../printing/document_actions_sheet.dart';
import 'money_in_screens.dart';

/// স্লিপের ছবি বাছাই — ক্যামেরা বা গ্যালারি; ফাইলের পথ, না বাছলে null
typedef SlipPicker = Future<String?> Function(BuildContext context, {required bool camera});

/// রসিদ খোলা — সার্ভারের PDF ([DocumentActionsSheet]: খোলা, ছাপা, WhatsApp বা ইমেইলে পাঠানো)
typedef ReceiptOpener = Future<void> Function(BuildContext context, CollectionSaved saved);

Future<String?> _pickSlip(BuildContext context, {required bool camera}) async {
  final shot = camera
      ? await takeCameraShot(context, imageQuality: 70, maxWidth: 1600)
      : await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 70, maxWidth: 1600);
  return shot?.path;
}

Future<void> _openReceipt(BuildContext context, CollectionSaved saved) => DocumentActionsSheet.show(context,
    type: 'Collection',
    id: saved.id,
    title: context.l10n.booksMoneyInPrintTitle(saved.no ?? ''),
    fileStem: saved.no);

Future<int> _fileSize(String path) => File(path).length();

/// ⭐ আদায় — এক পথ, এক পাতা, ওয়েবের একই ঘর আর নাম (মালিক, ২১ অক্টোবর ২০২৬: "ok ekoni kaj kore live e daw";
/// চূড়ান্ত নকশা ১০০, পাতা ১)।
///
/// <p>এক কলামে, ওয়েবের ক্রমে: মাথায় নম্বর · অবস্থা · শাখা · মাস; তিন অঙ্ক (বকেয়া · এই আদায় · বাকি থাকবে); গ্রাহক
/// ("নাম · পয়েন্ট · শাখা", বকেয়া, বাকির সীমা, সবচেয়ে পুরনো বাকি); তারিখ; টাকা ("পুরো বকেয়া"); পদ্ধতি আর যে খাতে জমা
/// হলো (নিজের হাতের নগদ আগে বাছা); ব্যাংক/MFS হলে রেফারেন্স, মূল্য-তারিখ, চার্জ; খোলা বিল ও ক্রেডিট (পুরনো আগে নিজে
/// বসে, গুটানো); ছাড়/রাউন্ডিং আর উৎসে কাটা; হিসাবের সারাংশ; খাতায় যা যাবে; নিয়ন্ত্রণ; স্লিপের ছবি আর বিবরণ। নিচে
/// স্থির "খসড়া রাখুন" আর "পোস্ট" — পোস্টের আগে সারাংশ, তারপর এক চাপ। সই লাগবে কি না সার্ভার ঠিক করে।
///
/// <p>⛔ কেবল নেট থাকলে; একই কাজ আবার পাঠালে একই চাবি, তাই একটাই রসিদ। ⛔ পুরনো সার্ভারে (নতুন দরজা 404/405) পর্দা
/// আজকের মতোই চলে — বিল সার্ভার নিজে বসায়, প্রিভিউ নেই, ছবি নেই, আর বসে গেলে আগের রসিদ-পাতা ([MoneyInScreen])।
class CollectScreen extends StatefulWidget {
  const CollectScreen({
    super.key,
    this.api = const ServerCollectionEntryApi(),
    this.books = const ServerBooksApi(),
    this.today,
    this.newChangeId = _engineChangeId,
    this.pickSlip = _pickSlip,
    this.openReceipt = _openReceipt,
    this.slipSize = _fileSize,
    this.previewDelay = const Duration(milliseconds: 500),
  });

  static String _engineChangeId() => SyncEngine.instance.newChangeId();

  final CollectionEntryApi api;

  /// পরীক্ষার পথ — একই কাজে একই চাবি কি না দেখতে
  final String Function() newChangeId;
  final BooksApi books;
  final DateTime? today;
  final SlipPicker pickSlip;
  final ReceiptOpener openReceipt;
  final Future<int> Function(String path) slipSize;

  /// লেখা থামার কতক্ষণ পরে খাতার প্রিভিউ চাওয়া — পরীক্ষায় শূন্য
  final Duration previewDelay;

  /// সার্ভারের ছবির সীমা — 5 MB
  static const slipLimit = 5 * 1024 * 1024;

  /// ⭐ ওয়েবের পদ্ধতি, ক্রমে — টাকার-পদ্ধতির মডেলের `instrument` (a5, ২১ অক্টোবর ২০২৬)
  static const methods = ['cash', 'mfs', 'transfer', 'deposit', 'cheque'];

  @override
  State<CollectScreen> createState() => _CollectScreenState();
}

enum _Bills { none, loading, loaded, absent, failed }

class _CollectScreenState extends State<CollectScreen> {
  static final _amountShape = RegExp(r'^\d{1,14}(\.\d{1,2})?$');

  final _amount = TextEditingController();
  final _reference = TextEditingController();
  final _depositSlip = TextEditingController();
  final _charge = TextEditingController();
  final _difference = TextEditingController();
  final _vds = TextEditingController();
  final _ait = TextEditingController();
  final _narration = TextEditingController();

  final _amountFocus = FocusNode(debugLabel: 'amount');
  final _referenceFocus = FocusNode(debugLabel: 'reference');
  final _depositSlipFocus = FocusNode(debugLabel: 'deposit-slip');
  final _chargeFocus = FocusNode(debugLabel: 'charge');
  final _differenceFocus = FocusNode(debugLabel: 'difference');
  final _vdsFocus = FocusNode(debugLabel: 'vds');
  final _aitFocus = FocusNode(debugLabel: 'ait');
  final _narrationFocus = FocusNode(debugLabel: 'narration');

  List<CollectionAccount> _accounts = const [];
  CollectionAccount? _account;
  String _method = 'cash';
  /// সেটআপের ট্রান্সফার মোড — ⓘ পুরনো সার্ভারে খালি, তখন বাছাইও নেই
  List<TransferMode> _transferModes = const [];
  String? _transferModeId;
  CustomerRecord? _customer;
  late DateTime _date;
  DateTime? _valueDate;

  _Bills _billsState = _Bills.none;
  OpenBills? _openBills;
  CollectionPlan _plan = CollectionPlan();
  final Map<PlanRow, TextEditingController> _rowText = {};
  final Map<PlanRow, String> _rowError = {};
  bool _unfolded = false;
  bool _manual = false;
  String _differenceKind = 'discount';

  CollectionPreview? _preview;
  bool _previewAbsent = false;
  Timer? _previewTimer;
  int _previewSeq = 0;

  String? _slipPath;
  bool _busy = false;
  String? _error;
  CollectionSaved? _saved;
  bool _slipFailed = false;

  /// একই কাজের চাবি — নেট গেলে বা উত্তর হারালে আবার চাপলে একই; সার্ভার ফেরালে নতুন
  String? _changeId;

  DateTime get _today {
    final now = widget.today ?? DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void initState() {
    super.initState();
    _date = _today;
    for (final c in [_amount, _difference, _vds, _ait]) {
      c.addListener(_figuresChanged);
    }
    for (final c in [_reference, _depositSlip, _charge, _narration]) {
      c.addListener(_textChanged);
    }
    _loadAccounts();
  }

  @override
  void dispose() {
    _previewTimer?.cancel();
    for (final c in [_amount, _reference, _depositSlip, _charge, _difference, _vds, _ait, _narration, ..._rowText.values]) {
      c.dispose();
    }
    for (final f in [_amountFocus, _referenceFocus, _depositSlipFocus, _chargeFocus, _differenceFocus, _vdsFocus, _aitFocus, _narrationFocus]) {
      f.dispose();
    }
    super.dispose();
  }

  // ── অঙ্ক ──

  static int _paisa(String text) => ((Money.typed(text) ?? 0) * 100).round();

  int get _received => _paisa(_amount.text);
  int get _differencePaisa => _billsState == _Bills.loaded ? _paisa(_difference.text) : 0;
  int get _withheldPaisa => _billsState == _Bills.loaded ? _paisa(_vds.text) + _paisa(_ait.text) : 0;

  /// বকেয়া — নতুন সার্ভারে খোলা বিলের উত্তর থেকে (ওয়েবের একই), পুরনোয় ফোনে রাখা বকেয়া
  int? get _due {
    if (_customer == null) return null;
    if (_openBills case final b?) return b.due;
    final cached = CustomerDueRecord.forCustomer(_customer!.id);
    return cached == null ? null : (cached.outstandingInView * 100).round();
  }

  bool get _newServer => _billsState == _Bills.loaded;
  String get _kind => switch (_method) { 'cash' => 'cash', 'mfs' => 'mfs', _ => 'bank' };
  /// রেফারেন্স / TrxID — MFS, ট্রান্সফার আর চেকে (নগদে নয়; নগদ জমায় স্লিপ নম্বর)
  bool get _needsReference => _method == 'mfs' || _method == 'transfer' || _method == 'cheque';
  bool get _needsDepositSlip => _method == 'deposit';
  bool get _isBank => _kind == 'bank';
  bool get _slipAllowed => _newServer;
  bool get _slipRequired => _newServer && (_openBills?.attachmentRequired ?? false);

  void _figuresChanged() {
    setState(() {
      _plan
        ..received = _received
        ..difference = _differencePaisa
        ..withheld = _withheldPaisa;
      // ⓘ টাকা বা ছাড় বদলালে আবার "পুরনো বিল আগে" — হাতে বদলানো বণ্টন তখন নতুন অঙ্কে আর মেলে না
      _plan.autoAllocate();
      _manual = false;
      _rowError.clear();
      _syncRowText();
    });
    _schedulePreview();
  }

  void _textChanged() {
    setState(() {});
    _schedulePreview();
  }

  void _syncRowText() {
    for (final row in [..._plan.bills, ..._plan.credits]) {
      final c = _rowText.putIfAbsent(row, TextEditingController.new);
      final text = row.applied == 0 ? '' : paisaText(row.applied);
      if (c.text != text) c.text = text;
    }
  }

  // ── আনা ──

  Future<void> _loadAccounts() async {
    try {
      final setup = await widget.api.setup();
      final list = CollectionAccount.ownCashFirst(setup.accounts);
      if (!mounted) return;
      setState(() {
        _accounts = list;
        _transferModes = setup.transferModes;
        _transferModeId = _transferModes.firstOrNull?.id;
        _account = list.isEmpty ? null : list.first;
        if (_account != null) _method = _account!.kind == 'cash' ? 'cash' : (_account!.kind == 'mfs' ? 'mfs' : 'transfer');
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e, fallback: AppLocale.l10n.booksCollectAccountsFailed));
      }
    }
  }

  Future<void> _pickCustomer() async {
    final picked = await showModalBottomSheet<CustomerRecord>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _CustomerSheet(customers: widget.api.customers()),
    );
    if (picked == null || !mounted) return;
    setState(() {
      _customer = picked;
      _openBills = null;
      _billsState = _Bills.loading;
      _plan = CollectionPlan()..received = _received;
      _preview = null;
      _changeId = null;
    });
    try {
      final bills = await widget.api.openBills(picked.id);
      if (!mounted || _customer?.id != picked.id) return;
      setState(() {
        if (bills == null) {
          // ⛔ পুরনো সার্ভার — পর্দা চলে, বিল সার্ভার নিজে বসায়
          _billsState = _Bills.absent;
          return;
        }
        _openBills = bills;
        _billsState = _Bills.loaded;
        _plan = CollectionPlan(bills: bills.bills, credits: bills.credits);
      });
      _figuresChanged();
    } catch (_) {
      if (mounted && _customer?.id == picked.id) setState(() => _billsState = _Bills.failed);
    }
  }

  Future<void> _pickDate({bool value = false}) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: value ? (_valueDate ?? _date) : _date,
      firstDate: _today.subtract(const Duration(days: 365)),
      lastDate: value ? _today.add(const Duration(days: 365)) : _today,
    );
    if (picked == null || !mounted) return;
    setState(() => value ? _valueDate = picked : _date = picked);
    _schedulePreview();
  }

  void _chooseMethod(String method) {
    final kind = switch (method) { 'cash' => 'cash', 'mfs' => 'mfs', _ => 'bank' };
    setState(() {
      _method = method;
      if (_account?.kind != kind) _account = _accounts.where((a) => a.kind == kind).firstOrNull;
    });
    _schedulePreview();
  }

  // ── বিল ──

  void _tick(PlanRow row, bool on) {
    setState(() {
      _manual = true;
      _rowError.remove(row);
      if (row.item.isCredit) {
        if (!_plan.setCredit(row, on ? row.item.open : 0)) _rowError[row] = context.l10n.collectCreditFloor;
      } else {
        _plan.setBill(row, on ? row.item.open : 0);
      }
      _syncRowText();
    });
    _schedulePreview();
  }

  void _typeApplied(PlanRow row, String text) {
    final want = _paisa(text);
    setState(() {
      _manual = true;
      _rowError.remove(row);
      final ok = row.item.isCredit ? _plan.setCredit(row, want) : _plan.setBill(row, want);
      if (!ok) {
        _rowError[row] = row.item.isCredit
            ? context.l10n.collectCreditFloor
            : context.l10n.collectOverApplied(Money.taka(row.applied / 100));
        // ⛔ বেশি চাওয়া অঙ্ক থাকে না — যতটা বসল ততটাই লেখা
        _rowText[row]!.text = row.applied == 0 ? '' : paisaText(row.applied);
      }
    });
    _schedulePreview();
  }

  void _oldestFirst() {
    setState(() {
      _plan.autoAllocate();
      _manual = false;
      _rowError.clear();
      _syncRowText();
    });
    _schedulePreview();
  }

  // ── খসড়া, প্রিভিউ, পাঠানো ──

  bool get _amountValid {
    final text = Money.typedText(_amount.text);
    return _amountShape.hasMatch(text) && _received > 0;
  }

  CollectionDraft _draft({required bool confirm}) => CollectionDraft(
        customerId: _customer!.id,
        amount: Money.typedText(_amount.text),
        date: _date,
        accountId: _account?.id,
        // ⭐ পদ্ধতি = `instrument` (cash · mfs · transfer · deposit · cheque); মোড `transfer_mode_id`-এ, স্লিপ `deposit_slip_no`-এ
        instrument: _method,
        instrumentNo: _needsReference ? _reference.text.trim() : null,
        transferModeId: _method == 'transfer' ? _transferModeId : null,
        depositSlipNo: _needsDepositSlip ? _depositSlip.text.trim() : null,
        valueDate: _isBank ? _valueDate : null,
        bankCharge: _isBank && _paisa(_charge.text) > 0 ? paisaText(_paisa(_charge.text)) : null,
        vds: _newServer && _paisa(_vds.text) > 0 ? paisaText(_paisa(_vds.text)) : null,
        ait: _newServer && _paisa(_ait.text) > 0 ? paisaText(_paisa(_ait.text)) : null,
        narration: _narration.text,
        confirm: confirm,
        // ⛔ খোলা বিল না এলে লাইন নয় — সার্ভার নিজে পুরনো বিল আগে বসায়
        lines: _newServer ? _plan.lines : null,
        difference: _differencePaisa > 0 ? (kind: _differenceKind, amount: paisaText(_differencePaisa)) : null,
      );

  void _schedulePreview() {
    _previewTimer?.cancel();
    if (_previewAbsent || _customer == null || !_amountValid || _account == null || _saved != null) return;
    _previewTimer = Timer(widget.previewDelay, _runPreview);
  }

  Future<CollectionPreview?> _runPreview() async {
    if (_previewAbsent || _customer == null || !_amountValid || _account == null) return null;
    final seq = ++_previewSeq;
    try {
      final preview = await widget.api.preview(_draft(confirm: true));
      if (!mounted || seq != _previewSeq) return preview;
      setState(() {
        _preview = preview;
        _previewAbsent = preview == null;
      });
      return preview;
    } catch (_) {
      // ⓘ প্রিভিউ না এলে আগেরটা থাকে — সার্ভার পোস্টে নিজেই সব দেখে
      return null;
    }
  }

  String? _problem({required bool post}) {
    final w = context.l10n;
    if (_customer == null) return w.booksCollectPickCustomerError;
    if (!_amountValid) return w.booksCollectAmountWrong;
    if (_account == null) return w.booksCollectPickAccountError;
    if (_needsReference && _reference.text.trim().isEmpty) return w.collectReferenceMissing;
    if (_needsDepositSlip && _depositSlip.text.trim().isEmpty) return w.collectDepositSlipMissing;
    if (_differencePaisa + _withheldPaisa > _received) return w.collectDifferenceTooBig;
    if (post && _slipRequired && _slipPath == null) return w.collectSlipMissing;
    return null;
  }

  Future<void> _send({required bool confirm}) async {
    final problem = _problem(post: confirm);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (confirm) {
        _previewTimer?.cancel();
        final preview = await _runPreview() ?? (_previewAbsent ? null : _preview);
        if (!mounted) return;
        if (preview != null && preview.stops.isNotEmpty) {
          setState(() => _error = preview.stops.first);
          return;
        }
        setState(() => _busy = false);
        // ⭐ পোস্টের আগে সারাংশ — গ্রাহক, টাকা (কথায়), খাত, বিল, খাতায় যা যাবে; তারপর এক চাপ
        final go = await showModalBottomSheet<bool>(
          context: context,
          isScrollControlled: true,
          builder: (_) => CollectSummarySheet(data: _summary(preview)),
        );
        if (go != true || !mounted) return;
        setState(() => _busy = true);
      }
      await _push(confirm: confirm);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  CollectSummary _summary(CollectionPreview? preview) => CollectSummary(
        customer: _customer!.label,
        amount: _received,
        words: preview?.amountWords ?? Money.spoken(_received / 100, englishDigits: true),
        account: _account == null ? '' : '${_methodLabel(context, _method)} · ${_account!.label}',
        bills: _newServer ? [for (final r in [..._plan.bills, ..._plan.credits]) if (r.applied > 0) (r.item.no, r.item.isCredit ? -r.applied : r.applied)] : null,
        difference: _differencePaisa,
        withheld: _withheldPaisa,
        unapplied: _newServer ? _plan.unapplied : null,
        journal: preview?.lines,
        needsSignature: preview?.needsSignature,
      );

  Future<void> _push({required bool confirm}) async {
    final changeId = _changeId ??= widget.newChangeId();
    try {
      final outcome = await widget.api.send(_draft(confirm: confirm), changeId);
      if (!mounted) return;
      final landed = outcome.landedId;
      if (landed == null) {
        // ⓘ সার্ভার ফেরাল — কারণসহ (একই রেফারেন্স হলে আগের কাগজের নম্বরসহ); ঠিক করে আবার পাঠালে নতুন চাবি
        setState(() {
          _error = outcome.refusal ?? context.l10n.booksCollectNotLanded;
          _changeId = null;
        });
        return;
      }
      final saved = CollectionSaved.of(landed, outcome.details);
      if (_slipPath != null && _slipAllowed) {
        try {
          await widget.api.attachSlip(landed, _slipPath!);
        } catch (_) {
          _slipFailed = true;
        }
      }
      if (!mounted) return;
      if (!saved.rich) {
        // ⛔ পুরনো সার্ভার — নম্বর বা অবস্থা নেই: আজকের মতো, নম্বরসহ রসিদ-পাতা
        await Navigator.of(context).pushReplacement(MaterialPageRoute<void>(
          builder: (_) => MoneyInScreen(id: landed, api: widget.books),
        ));
        return;
      }
      setState(() => _saved = saved);
      // ⭐ বিষয় ১৭ — বসার পরেই রসিদ (টাকা কথায়), সেখান থেকে WhatsApp বা ইমেইল
      if (saved.receipt != null) await widget.openReceipt(context, saved);
    } on NoNetworkForThis catch (e) {
      // ⓘ চাবি থাকে — নেট এলে আবার চাপলে একই কাজ, দুবার নয়
      if (mounted) {
        setState(() => _error = e.mayHaveLanded ? context.l10n.booksCollectMayHaveLanded : context.l10n.booksCollectNeedsNet);
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e, fallback: AppLocale.l10n.booksCollectSendFailed));
      }
    }
  }

  Future<void> _chooseSlip(bool camera) async {
    final path = await widget.pickSlip(context, camera: camera);
    if (path == null || !mounted) return;
    final size = await widget.slipSize(path);
    if (!mounted) return;
    setState(() {
      if (size > CollectScreen.slipLimit) {
        _error = context.l10n.collectSlipTooBig;
      } else {
        _slipPath = path;
        if (_error == context.l10n.collectSlipMissing || _error == context.l10n.collectSlipTooBig) _error = null;
      }
    });
  }

  void _nextFrom(FocusNode node) {
    final order = [
      _amountFocus,
      if (_needsReference) _referenceFocus,
      if (_needsDepositSlip) _depositSlipFocus,
      if (_isBank) _chargeFocus,
      if (_newServer) _differenceFocus,
      _narrationFocus,
    ];
    final i = order.indexOf(node);
    if (i >= 0 && i + 1 < order.length) {
      order[i + 1].requestFocus();
    } else {
      node.unfocus();
    }
  }

  // ── আঁকা ──

  @override
  Widget build(BuildContext context) => UnsavedGuard(
      dirty: !_busy &&
          _saved == null &&
          (_customer != null || _amount.text.trim().isNotEmpty || _reference.text.trim().isNotEmpty || _narration.text.trim().isNotEmpty),
      child: _screen(context));

  Widget _screen(BuildContext context) {
    final w = context.l10n;
    final saved = _saved;
    return Scaffold(
      appBar: AbosScreenBar(title: w.collectTitle),
      bottomNavigationBar: _bottomBar(context),
      // ⓘ সব ঘর একসাথে আঁকা (অলস তালিকা নয়) — কীবোর্ডের Tab আর TalkBack পাতার নিচের ঘরেও পৌঁছায়
      body: SingleChildScrollView(
        key: const ValueKey('collect-list'),
        padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.xl),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          _header(context),
          const SizedBox(height: AppSpacing.sm),
          _Figures(due: _due, received: _received, settled: _differencePaisa + _withheldPaisa),
          if (_busy) const Padding(padding: EdgeInsets.only(top: AppSpacing.sm), child: LinearProgressIndicator()),
          if (_error != null) _ErrorCard(_error!),
          if (saved != null) ..._done(context, saved) else ..._form(context),
        ]),
      ),
    );
  }

  Widget _header(BuildContext context) {
    final w = context.l10n;
    final muted = TextStyle(color: context.palette.onSurfaceMuted, fontSize: 13);
    final branch = _customer?.branchName;
    final month = _openBills?.monthOpen;
    return Wrap(
      key: const ValueKey('collect-header'),
      spacing: AppSpacing.sm,
      runSpacing: AppSpacing.xs,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text(_saved?.no == null ? w.collectNoLater : w.collectNumber(_saved!.no!), key: const ValueKey('collect-number'), style: muted),
        if (_saved case final s? when s.state != null)
          AppStatusChip.forServer(s.state!, key: const ValueKey('collect-state'), fallbackLabel: s.state!, dense: true),
        if (branch != null) Text(w.collectBranchOf(branch), key: const ValueKey('collect-branch'), style: muted),
        // ⓘ টিক চিহ্ন Material Symbols-এর আইকন, লেখার ✓ নয় (আইকনের নিয়ম; পর্দা-পাঠক কেবল লেখাটা পড়ে)
        if (month == true)
          Row(key: const ValueKey('collect-month'), mainAxisSize: MainAxisSize.min, children: [
            Text(w.collectMonthOpen, style: muted.copyWith(color: context.palette.success)),
            const SizedBox(width: 2),
            ExcludeSemantics(child: Icon(Symbols.check_circle, size: 14, color: context.palette.success)),
          ]),
        if (month == false)
          Text(w.collectMonthClosed,
              key: const ValueKey('collect-month'), style: muted.copyWith(color: context.palette.danger, fontWeight: FontWeight.w600)),
      ],
    );
  }

  List<Widget> _done(BuildContext context, CollectionSaved saved) {
    final w = context.l10n;
    final words = saved.amountWords ?? Money.spoken(_received / 100, englishDigits: true);
    return [
      _Section(
        key: const ValueKey('collect-done'),
        title: w.collectDone(saved.no!),
        children: [
          Text(Money.taka(_received / 100), style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700)),
          if (words != null) Text(w.collectDoneWords(words), key: const ValueKey('collect-done-words')),
          if (_customer != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.xs), child: Text(_customer!.label)),
          for (final (no, paisa) in saved.applied)
            _AmountRow(label: no.isEmpty ? w.booksMoneyInAdvance : no, paisa: paisa),
          if (_slipFailed)
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.sm),
              child: Text(w.collectSlipFailed, key: const ValueKey('collect-slip-failed'), style: TextStyle(color: context.palette.warning)),
            ),
        ],
      ),
    ];
  }

  List<Widget> _form(BuildContext context) {
    final w = context.l10n;
    final due = _due;
    final over = due != null && _received + _differencePaisa + _withheldPaisa > due && _amountValid;
    final b = _openBills;
    final accounts = _accounts.where((a) => a.kind == _kind).toList();
    return [
      // ── সাধারণ তথ্য ──
      _Section(title: null, children: [
        _Tappable(
          key: const ValueKey('collect-customer'),
          label: w.collectCustomer,
          value: _customer == null ? null : CustomerLine(_customer!.label),
          placeholder: w.collectPickCustomer,
          icon: Symbols.person_search,
          onTap: _busy ? null : _pickCustomer,
        ),
        if (_customer != null)
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.xs, left: AppSpacing.xs),
            child: Text(
              [
                if (due != null) w.collectDue(Money.taka(due / 100)),
                if (b?.creditLimit case final limit?) w.collectCreditLimit(Money.taka(limit / 100)),
                if (b?.oldestOverdueDays case final days?) w.collectOldestOverdue(days),
              ].join(' · '),
              key: const ValueKey('collect-customer-facts'),
              style: TextStyle(color: context.palette.onSurfaceMuted, fontSize: 13),
            ),
          ),
        const SizedBox(height: AppSpacing.sm),
        _Tappable(
          key: const ValueKey('collect-date'),
          label: w.collectDate,
          value: Text(_dayText(_date)),
          icon: Symbols.calendar_today,
          onTap: _busy ? null : _pickDate,
        ),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          key: const ValueKey('collect-amount'),
          controller: _amount,
          focusNode: _amountFocus,
          enabled: !_busy,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          textInputAction: TextInputAction.next,
          onEditingComplete: () => _nextFrom(_amountFocus),
          decoration: InputDecoration(labelText: w.collectAmount, prefixText: '৳ '), // l10n-ok: the taka sign, the same in every language
        ),
        Wrap(spacing: AppSpacing.xs, children: [
          ActionChip(
            key: const ValueKey('collect-full-due'),
            avatar: const Icon(Symbols.done_all, size: 18),
            label: Text(w.collectFullDue),
            onPressed: due == null || due <= 0 || _busy ? null : () => _amount.text = paisaText(due),
          ),
        ]),
        if (over)
          _Note(
            key: const ValueKey('collect-over-due'),
            text: w.collectOverDue(Money.taka((_received + _differencePaisa + _withheldPaisa - due) / 100)),
            tone: _Tone.warn,
          ),
        const SizedBox(height: AppSpacing.sm),
        Text(w.collectMethod, style: Theme.of(context).textTheme.labelLarge),
        const SizedBox(height: AppSpacing.xs),
        Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
          for (final m in CollectScreen.methods)
            ChoiceChip(
              key: ValueKey('collect-method-$m'),
              label: Text(_methodLabel(context, m)),
              selected: _method == m,
              onSelected: _busy || !_accounts.any((a) => a.kind == switch (m) { 'cash' => 'cash', 'mfs' => 'mfs', _ => 'bank' })
                  ? null
                  : (_) => _chooseMethod(m),
            ),
        ]),
        const SizedBox(height: AppSpacing.sm),
        DropdownButtonFormField<CollectionAccount>(
          key: ValueKey('collect-account-$_kind'),
          initialValue: accounts.contains(_account) ? _account : null,
          isExpanded: true,
          decoration: InputDecoration(labelText: w.collectAccount),
          items: [
            for (final a in accounts)
              DropdownMenuItem(
                value: a,
                child: Text(a.mine ? w.collectAccountMine(a.label) : a.label, maxLines: 1, overflow: TextOverflow.ellipsis),
              ),
          ],
          onChanged: _busy
              ? null
              : (a) {
                  setState(() => _account = a);
                  _schedulePreview();
                },
        ),
        // ⓘ মোড সেটআপ থেকে — পুরনো সার্ভারে মোড নেই, বাছাইও নেই
        if (_method == 'transfer' && _transferModes.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.sm),
          DropdownButtonFormField<String>(
            key: const ValueKey('collect-transfer-mode'),
            initialValue: _transferModeId,
            decoration: InputDecoration(labelText: w.collectTransferMode),
            items: [for (final m in _transferModes) DropdownMenuItem(value: m.id, child: Text(m.name))],
            onChanged: _busy
                ? null
                : (m) {
                    setState(() => _transferModeId = m ?? _transferModeId);
                    _schedulePreview();
                  },
          ),
        ],
        if (_needsDepositSlip) ...[
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('collect-deposit-slip'),
            controller: _depositSlip,
            focusNode: _depositSlipFocus,
            enabled: !_busy,
            maxLength: 64,
            textInputAction: TextInputAction.next,
            onEditingComplete: () => _nextFrom(_depositSlipFocus),
            decoration: InputDecoration(labelText: w.collectDepositSlip, counterText: ''),
          ),
        ],
        if (_needsReference) ...[
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('collect-reference'),
            controller: _reference,
            focusNode: _referenceFocus,
            enabled: !_busy,
            maxLength: 64,
            textInputAction: TextInputAction.next,
            onEditingComplete: () => _nextFrom(_referenceFocus),
            decoration: InputDecoration(labelText: w.collectReference, counterText: ''),
          ),
        ],
        if (_isBank) ...[
          const SizedBox(height: AppSpacing.sm),
          _Tappable(
            key: const ValueKey('collect-value-date'),
            label: _method == 'cheque' ? w.collectChequeDate : w.collectValueDate,
            value: Text(_dayText(_valueDate ?? _date)),
            icon: Symbols.event,
            onTap: _busy ? null : () => _pickDate(value: true),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('collect-bank-charge'),
            controller: _charge,
            focusNode: _chargeFocus,
            enabled: !_busy,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.next,
            onEditingComplete: () => _nextFrom(_chargeFocus),
            decoration: InputDecoration(labelText: w.collectBankCharge, helperText: w.collectBankChargeHint, helperMaxLines: 3),
          ),
        ],
      ]),
      // ── খোলা বিল ও ক্রেডিট ──
      _bills(context),
      // ── ছাড় / রাউন্ডিং, উৎসে কাটা ──
      if (_newServer) _differenceSection(context),
      // ── হিসাবের সারাংশ ──
      if (_newServer && _customer != null) _summaryCard(context),
      // ── খাতায় যা যাবে ──
      if (_preview case final p? when p.lines.isNotEmpty) _JournalCard(preview: p),
      // ── নিয়ন্ত্রণ ──
      if (_customer != null) _controls(context),
      // ── সংযুক্তি ও বিবরণ ──
      _Section(title: w.collectAttachTitle, children: [
        if (_slipAllowed) ...[
          Text(_slipRequired ? w.collectSlipNeeded : w.collectSlip, style: Theme.of(context).textTheme.labelLarge),
          const SizedBox(height: AppSpacing.xs),
          if (_slipPath == null)
            Wrap(spacing: AppSpacing.sm, runSpacing: AppSpacing.xs, children: [
              OutlinedButton.icon(
                key: const ValueKey('collect-slip-camera'),
                style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                onPressed: _busy ? null : () => _chooseSlip(true),
                icon: const Icon(Symbols.photo_camera),
                label: Text(w.collectSlipCamera),
              ),
              OutlinedButton.icon(
                key: const ValueKey('collect-slip-gallery'),
                style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                onPressed: _busy ? null : () => _chooseSlip(false),
                icon: const Icon(Symbols.photo_library),
                label: Text(w.collectSlipGallery),
              ),
            ])
          else
            Row(children: [
              const Icon(Symbols.image),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(w.collectSlipChosen(_slipPath!.split(RegExp(r'[\\/]')).last),
                    key: const ValueKey('collect-slip-chosen'), maxLines: 2, overflow: TextOverflow.ellipsis),
              ),
              IconButton(
                key: const ValueKey('collect-slip-remove'),
                tooltip: w.collectSlipRemove,
                onPressed: _busy ? null : () => setState(() => _slipPath = null),
                icon: const Icon(Symbols.close),
              ),
            ]),
          const SizedBox(height: AppSpacing.sm),
        ],
        TextField(
          key: const ValueKey('collect-narration'),
          controller: _narration,
          focusNode: _narrationFocus,
          enabled: !_busy,
          maxLength: 500,
          textInputAction: TextInputAction.done,
          onEditingComplete: () => _nextFrom(_narrationFocus),
          decoration: InputDecoration(labelText: w.collectNarration),
        ),
        Text(w.collectReceiptHint, style: TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5)),
      ]),
    ];
  }

  Widget _bills(BuildContext context) {
    final w = context.l10n;
    final muted = TextStyle(color: context.palette.onSurfaceMuted);
    final rows = [..._plan.bills, ..._plan.credits];
    // ⓘ এক টেবিল, সার্ভারের ক্রমে (পুরনো আগে) — বিল আর ক্রেডিট মিশিয়ে
    final ordered = _openBills == null
        ? rows
        : [
            for (final item in _openBills!.items)
              if (rows.where((r) => identical(r.item, item)).firstOrNull case final r?) r,
          ];
    final applied = [for (final r in ordered) if (r.applied > 0) '${r.item.no} ${Money.taka((r.item.isCredit ? -r.applied : r.applied) / 100)}'];
    return _Section(
      key: const ValueKey('collect-bills'),
      title: w.collectBillsTitle,
      trailing: _billsState == _Bills.loaded && ordered.isNotEmpty
          ? TextButton(
              key: const ValueKey('collect-bills-change'),
              style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
              onPressed: () => setState(() => _unfolded = !_unfolded),
              child: Text(_unfolded ? w.collectBillsFold : w.collectBillsChange),
            )
          : null,
      children: [
        switch (_billsState) {
          _Bills.none => Text(w.collectBillsNeedCustomer, style: muted),
          _Bills.loading => const LinearProgressIndicator(),
          // ⛔ পুরনো সার্ভার: তালিকা নেই, সার্ভার নিজে পুরনো বিল আগে বসায়
          _Bills.absent => Text(w.collectBillsAuto, key: const ValueKey('collect-bills-auto')),
          _Bills.failed => Text(w.collectBillsFailed, key: const ValueKey('collect-bills-failed'), style: muted),
          _Bills.loaded when ordered.isEmpty => Text(w.collectBillsNone, key: const ValueKey('collect-bills-none'), style: muted),
          _Bills.loaded => Text(
              applied.isEmpty ? w.collectBillsAuto : (_manual ? applied.join(' · ') : w.collectBillsAutoWith(applied.join(' · '))),
              key: const ValueKey('collect-bills-summary'),
            ),
        },
        if (_billsState == _Bills.loaded && ordered.isNotEmpty && _unfolded) ...[
          const SizedBox(height: AppSpacing.xs),
          Text(w.collectBillsHint, style: muted.copyWith(fontSize: 12.5)),
          for (final r in ordered)
            _ItemRow(
              key: ValueKey('collect-item-${r.item.no}'),
              row: r,
              controller: _rowText.putIfAbsent(r, TextEditingController.new),
              error: _rowError[r],
              enabled: !_busy,
              onTick: (on) => _tick(r, on),
              onChanged: (text) => _typeApplied(r, text),
            ),
          const Divider(height: AppSpacing.lg),
          _AmountRow(key: const ValueKey('collect-applied-total'), label: w.collectAppliedTotal, paisa: _plan.netApplied, strong: true),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton.icon(
              key: const ValueKey('collect-oldest-first'),
              style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
              onPressed: _busy ? null : _oldestFirst,
              icon: const Icon(Symbols.history),
              label: Text(w.collectBillsOldestFirst),
            ),
          ),
        ],
      ],
    );
  }

  Widget _differenceSection(BuildContext context) {
    final w = context.l10n;
    return _Section(title: w.collectDifference, children: [
      Wrap(spacing: AppSpacing.xs, children: [
        for (final (kind, label) in [('discount', w.collectDifferenceDiscount), ('rounding', w.collectDifferenceRounding)])
          ChoiceChip(
            key: ValueKey('collect-difference-$kind'),
            label: Text(label),
            selected: _differenceKind == kind,
            onSelected: _busy ? null : (_) => setState(() => _differenceKind = kind),
          ),
      ]),
      const SizedBox(height: AppSpacing.xs),
      TextField(
        key: const ValueKey('collect-difference'),
        controller: _difference,
        focusNode: _differenceFocus,
        enabled: !_busy,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        textInputAction: TextInputAction.next,
        onEditingComplete: () => _nextFrom(_differenceFocus),
        decoration: InputDecoration(labelText: w.collectDifferenceAmount),
      ),
      if (_differencePaisa > 0) _Note(key: const ValueKey('collect-difference-sign'), text: w.collectDifferenceSign, tone: _Tone.info),
      ExpansionTile(
        key: const ValueKey('collect-withheld'),
        tilePadding: EdgeInsets.zero,
        childrenPadding: EdgeInsets.zero,
        title: Text(w.collectWithheldTitle),
        initiallyExpanded: _withheldPaisa > 0,
        children: [
          TextField(
            key: const ValueKey('collect-vds'),
            controller: _vds,
            focusNode: _vdsFocus,
            enabled: !_busy,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.next,
            onEditingComplete: () => _aitFocus.requestFocus(),
            decoration: InputDecoration(labelText: w.collectVds),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('collect-ait'),
            controller: _ait,
            focusNode: _aitFocus,
            enabled: !_busy,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.next,
            onEditingComplete: () => _narrationFocus.requestFocus(),
            decoration: InputDecoration(labelText: w.collectAit),
          ),
          const SizedBox(height: AppSpacing.sm),
        ],
      ),
    ]);
  }

  Widget _summaryCard(BuildContext context) {
    final w = context.l10n;
    return _Section(key: const ValueKey('collect-summary-card'), title: w.collectSummaryTitle, children: [
      _AmountRow(label: w.collectSumReceived, paisa: _received),
      if (_paisa(_vds.text) > 0) _AmountRow(label: w.collectVds, paisa: _paisa(_vds.text)),
      if (_paisa(_ait.text) > 0) _AmountRow(label: w.collectAit, paisa: _paisa(_ait.text)),
      _AmountRow(label: w.collectDifference, paisa: _differencePaisa),
      const Divider(),
      _AmountRow(key: const ValueKey('collect-net-applied'), label: w.collectSumApplied, paisa: _plan.netApplied, strong: true),
      _AmountRow(key: const ValueKey('collect-unapplied'), label: w.collectSumUnapplied, paisa: _plan.unapplied),
      if (_plan.balanced) _Note(key: const ValueKey('collect-balanced'), text: w.collectSumBalanced, tone: _Tone.good),
    ]);
  }

  Widget _controls(BuildContext context) {
    final w = context.l10n;
    final p = _preview;
    return _Section(key: const ValueKey('collect-controls'), title: w.collectControlsTitle, children: [
      if (p != null)
        for (final (i, stop) in p.stops.indexed) _Note(key: ValueKey('collect-stop-$i'), text: stop, tone: _Tone.stop),
      if (p != null && p.stops.isNotEmpty) _Note(text: w.collectControlStops, tone: _Tone.stop),
      if (_openBills?.monthOpen == false) _Note(key: const ValueKey('collect-month-closed'), text: w.collectMonthClosed, tone: _Tone.stop),
      if (p?.needsSignature == true) _Note(key: const ValueKey('collect-needs-signature'), text: w.collectControlSignature, tone: _Tone.info),
      if (p?.needsSignature == false) _Note(key: const ValueKey('collect-no-signature'), text: w.collectControlNoSignature, tone: _Tone.info),
      if (_slipRequired) _Note(key: const ValueKey('collect-slip-required'), text: w.collectControlSlip, tone: _Tone.warn),
      _Note(text: w.collectControlNoUndo, tone: _Tone.info),
    ]);
  }

  Widget _bottomBar(BuildContext context) {
    final w = context.l10n;
    final saved = _saved;
    final blocked = _preview?.stops.isNotEmpty ?? false;
    const size = Size(48, 48);
    return Material(
      elevation: 3,
      color: Theme.of(context).colorScheme.surface,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.sm),
          child: Row(children: [
            if (saved == null) ...[
              Expanded(
                child: OutlinedButton(
                  key: const ValueKey('collect-draft'),
                  style: OutlinedButton.styleFrom(minimumSize: size),
                  onPressed: _busy ? null : () => _send(confirm: false),
                  child: Text(w.collectDraft, textAlign: TextAlign.center),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: FilledButton.icon(
                  key: const ValueKey('collect-post'),
                  style: FilledButton.styleFrom(minimumSize: size),
                  onPressed: _busy || blocked ? null : () => _send(confirm: true),
                  icon: const Icon(Symbols.task_alt),
                  label: Text(w.collectPost, textAlign: TextAlign.center),
                ),
              ),
            ] else ...[
              Expanded(
                child: OutlinedButton(
                  key: const ValueKey('collect-close'),
                  style: OutlinedButton.styleFrom(minimumSize: size),
                  onPressed: () => Navigator.of(context).maybePop(),
                  child: Text(w.collectClose, textAlign: TextAlign.center),
                ),
              ),
              if (saved.receipt != null) ...[
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: FilledButton.icon(
                    key: const ValueKey('collect-open-receipt'),
                    style: FilledButton.styleFrom(minimumSize: size),
                    onPressed: () => widget.openReceipt(context, saved),
                    icon: const Icon(Symbols.receipt_long),
                    label: Text(w.collectOpenReceipt, textAlign: TextAlign.center),
                  ),
                ),
              ],
            ],
          ]),
        ),
      ),
    );
  }
}

String _dayText(DateTime d) =>
    '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';

String _methodLabel(BuildContext context, String method) {
  final w = context.l10n;
  return switch (method) {
    'cash' => w.collectMethodCash,
    'mfs' => 'MFS',
    'transfer' => w.collectMethodTransfer,
    'deposit' => w.collectMethodDeposit,
    _ => w.collectMethodCheque,
  };
}

/// ⭐ তিন অঙ্ক — গ্রাহকের বকেয়া · এই আদায় · বাকি থাকবে (ঋণাত্মক হলে "গ্রাহকের খাতায় জমা থাকবে")
class _Figures extends StatelessWidget {
  const _Figures({required this.due, required this.received, required this.settled});

  /// পয়সায়; না জানা গেলে null
  final int? due;
  final int received;

  /// ছাড় আর উৎসে কাটা — বকেয়া থেকে এরাও কমে
  final int settled;

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    final left = due == null ? null : due! - received - settled;
    final figures = [
      _Figure(key: const ValueKey('collect-figure-due'), label: w.collectFigureDue, value: due, colour: context.palette.danger),
      _Figure(key: const ValueKey('collect-figure-this'), label: w.collectFigureThis, value: received, colour: context.palette.success),
      _Figure(
        key: const ValueKey('collect-figure-left'),
        label: left != null && left < 0 ? w.collectFigureCredit : w.collectFigureLeft,
        value: left?.abs(),
        colour: Theme.of(context).colorScheme.onSurface,
      ),
    ];
    return LayoutBuilder(builder: (context, c) {
      final scale = MediaQuery.textScalerOf(context).scale(1);
      final wide = c.maxWidth / 3 >= 112 * scale;
      return Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.sm),
          child: wide
              ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [for (final f in figures) Expanded(child: f)])
              : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: figures),
        ),
      );
    });
  }
}

class _Figure extends StatelessWidget {
  const _Figure({super.key, required this.label, required this.value, required this.colour});

  final String label;
  final int? value;
  final Color colour;

  @override
  Widget build(BuildContext context) {
    final text = value == null ? '—' : Money.taka(value! / 100);
    return Semantics(
      container: true,
      label: '$label, ${value == null ? '—' : Money.speakable(text)}',
      excludeSemantics: true,
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xs),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: TextStyle(fontSize: 12.5, color: context.palette.onSurfaceMuted)),
          Text(text, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: colour)),
        ]),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({super.key, required this.title, required this.children, this.trailing});

  final String? title;
  final Widget? trailing;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(top: AppSpacing.sm),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (title != null)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                // ⓘ Wrap — বড় লেখায় পাশের বোতাম নিচের লাইনে নামে, ডানে উপচে পড়ে না
                child: Wrap(
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Semantics(
                      header: true,
                      child: Text(title!, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                    ),
                    if (trailing != null) trailing!,
                  ],
                ),
              ),
            ...children,
          ]),
        ),
      );
}

/// চাপলে বাছাই খোলে — লেবেলসহ ঘর (গ্রাহক, তারিখ)
class _Tappable extends StatelessWidget {
  const _Tappable({super.key, required this.label, this.value, this.placeholder, required this.icon, this.onTap});

  final String label;
  final Widget? value;
  final String? placeholder;
  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(4),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 56),
            child: InputDecorator(
              decoration: InputDecoration(labelText: label, suffixIcon: Icon(icon), enabled: onTap != null),
              isEmpty: value == null,
              child: value ?? Text(placeholder ?? '', style: TextStyle(color: context.palette.onSurfaceMuted)),
            ),
          ),
        ),
      );
}

class _AmountRow extends StatelessWidget {
  const _AmountRow({super.key, required this.label, required this.paisa, this.strong = false});

  final String label;
  final int paisa;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final style = TextStyle(fontWeight: strong ? FontWeight.w700 : FontWeight.w400);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Wrap(alignment: WrapAlignment.spaceBetween, spacing: AppSpacing.sm, children: [
        Text(label, style: style),
        Text(Money.taka(paisa / 100), style: style),
      ]),
    );
  }
}

enum _Tone { info, warn, stop, good }

class _Note extends StatelessWidget {
  const _Note({super.key, required this.text, required this.tone});

  final String text;
  final _Tone tone;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    final (fg, bg, icon) = switch (tone) {
      _Tone.info => (p.pending, p.pendingSurface, Symbols.info),
      _Tone.warn => (p.warning, p.warningSurface, Symbols.warning),
      _Tone.stop => (p.danger, p.dangerSurface, Symbols.block),
      _Tone.good => (p.success, p.successSurface, Symbols.check_circle),
    };
    return Container(
      margin: const EdgeInsets.only(top: AppSpacing.xs),
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6), border: BorderDirectional(start: BorderSide(color: fg, width: 3))),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon, size: 18, color: fg),
        const SizedBox(width: AppSpacing.sm),
        Expanded(child: Text(text, style: TextStyle(color: p.onSurface, fontSize: 13))),
      ]),
    );
  }
}

class _ErrorCard extends StatelessWidget {
  const _ErrorCard(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(top: AppSpacing.sm),
        color: context.palette.dangerSurface,
        child: Semantics(
          liveRegion: true,
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Text(text, key: const ValueKey('collect-error'), style: TextStyle(color: context.palette.danger)),
          ),
        ),
      );
}

/// খোলা টেবিলের এক সারি — টিক, কাগজ, তারিখ, শেষ দিন, দেরি, মূল টাকা, বাকি, আর এখন প্রয়োগ
class _ItemRow extends StatelessWidget {
  const _ItemRow({
    super.key,
    required this.row,
    required this.controller,
    required this.error,
    required this.enabled,
    required this.onTick,
    required this.onChanged,
  });

  final PlanRow row;
  final TextEditingController controller;
  final String? error;
  final bool enabled;
  final ValueChanged<bool> onTick;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    final item = row.item;
    final sign = item.isCredit ? -1 : 1;
    final muted = TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5);
    final tag = switch (item.kind) {
      'credit_note' => w.collectItemCreditNote,
      'credit' => w.collectItemCredit,
      'debit_note' => w.collectItemDebitNote,
      _ => null,
    };
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.sm),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Checkbox(
            key: ValueKey('collect-tick-${item.no}'),
            value: row.ticked,
            semanticLabel: w.collectItemTick(item.no),
            onChanged: enabled ? (v) => onTick(v ?? false) : null,
          ),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Wrap(spacing: AppSpacing.xs, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Text(item.no, style: const TextStyle(fontWeight: FontWeight.w600)),
                if (tag != null)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                    decoration: BoxDecoration(color: context.palette.primarySoft, borderRadius: BorderRadius.circular(8)),
                    child: Text(tag, style: const TextStyle(fontSize: 12)),
                  ),
              ]),
              Text(
                [
                  if (item.date != null) _isoDay(item.date!),
                  if (item.dueDate != null) w.collectItemDueOn(_isoDay(item.dueDate!)),
                ].join(' · '),
                style: muted,
              ),
              if ((item.overdueDays ?? 0) > 0)
                Text(w.collectItemLate(item.overdueDays!),
                    key: ValueKey('collect-late-${item.no}'),
                    style: muted.copyWith(color: context.palette.danger, fontWeight: FontWeight.w600)),
              Text(
                '${w.collectItemOriginal(Money.taka(sign * item.amount / 100))} · ${w.collectItemOpen(Money.taka(sign * item.open / 100))}',
                style: muted,
              ),
            ]),
          ),
        ]),
        Padding(
          padding: const EdgeInsetsDirectional.only(start: 48, top: AppSpacing.sm),
          child: TextField(
            key: ValueKey('collect-apply-${item.no}'),
            controller: controller,
            enabled: enabled,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            onChanged: onChanged,
            decoration: InputDecoration(
              labelText: w.collectItemApplyOf(item.no),
              prefixText: item.isCredit ? '−' : null,
              errorText: error,
              errorMaxLines: 3,
              isDense: true,
            ),
          ),
        ),
      ]),
    );
  }
}

String _isoDay(String iso) {
  final d = DateTime.tryParse(iso);
  return d == null ? iso : _dayText(d);
}

/// ⭐ খাতায় যা যাবে — খাত, শাখা, ডেবিট, ক্রেডিট; মোট মেলে (বিষয় ১০)
class _JournalCard extends StatelessWidget {
  const _JournalCard({required this.preview});

  final CollectionPreview preview;

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    return _Section(
      key: const ValueKey('collect-journal'),
      title: w.collectJournalTitle,
      children: [
        Text(w.collectJournalHint, style: TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5)),
        JournalLines(lines: preview.lines, totalDr: preview.totalDr, totalCr: preview.totalCr),
      ],
    );
  }
}

/// খাতার লাইনগুলো আর মোট — পাতায় আর সারাংশে একই
class JournalLines extends StatelessWidget {
  const JournalLines({super.key, required this.lines, required this.totalDr, required this.totalCr});

  final List<JournalLine> lines;
  final int totalDr;
  final int totalCr;

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    final muted = TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5);
    Widget amounts(int dr, int cr, {bool strong = false}) {
      final style = TextStyle(fontWeight: strong ? FontWeight.w700 : FontWeight.w400);
      return Wrap(spacing: AppSpacing.md, children: [
        Text('${w.collectJournalDr} ${dr == 0 ? '—' : Money.amount(dr / 100)}', style: style),
        Text('${w.collectJournalCr} ${cr == 0 ? '—' : Money.amount(cr / 100)}', style: style),
      ]);
    }

    final balanced = totalDr == totalCr;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (final (i, l) in lines.indexed)
        Padding(
          key: ValueKey('collect-journal-$i'),
          padding: const EdgeInsets.only(top: AppSpacing.sm),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(l.account, style: const TextStyle(fontWeight: FontWeight.w600)),
            if (l.branch.isNotEmpty) Text('${w.collectJournalBranch}: ${l.branch}', style: muted),
            amounts(l.dr, l.cr),
          ]),
        ),
      const Divider(height: AppSpacing.lg),
      Text(w.collectJournalTotal, style: const TextStyle(fontWeight: FontWeight.w700)),
      amounts(totalDr, totalCr, strong: true),
      Padding(
        padding: const EdgeInsets.only(top: AppSpacing.xs),
        child: Row(key: const ValueKey('collect-journal-balanced'), mainAxisSize: MainAxisSize.min, children: [
          Flexible(
            child: Text(
              balanced ? w.collectJournalBalanced : w.collectJournalUnbalanced,
              style: TextStyle(color: balanced ? context.palette.success : context.palette.danger, fontWeight: FontWeight.w600),
            ),
          ),
          if (balanced) ...[
            const SizedBox(width: 4),
            ExcludeSemantics(child: Icon(Symbols.check_circle, size: 16, color: context.palette.success)),
          ],
        ]),
      ),
    ]);
  }
}

/// পোস্টের আগের সারাংশের তথ্য
class CollectSummary {
  const CollectSummary({
    required this.customer,
    required this.amount,
    required this.words,
    required this.account,
    this.bills,
    this.difference = 0,
    this.withheld = 0,
    this.unapplied,
    this.journal,
    this.needsSignature,
  });

  final String customer;

  /// পয়সায়
  final int amount;
  final String? words;
  final String account;

  /// (নম্বর, পয়সা); null = সার্ভার নিজে বসাবে (পুরনো সার্ভার)
  final List<(String, int)>? bills;
  final int difference;
  final int withheld;
  final int? unapplied;

  /// null = প্রিভিউয়ের দরজা নেই — "খাতায় যা যাবে" দেখায় না
  final List<JournalLine>? journal;
  final bool? needsSignature;
}

/// ⭐ পোস্টের আগে দেখে নিন — গ্রাহক, টাকা, কথায়, খাত, বিল, খাতায় যা যাবে; তারপর এক চাপ (বিষয় ১০)
class CollectSummarySheet extends StatelessWidget {
  const CollectSummarySheet({super.key, required this.data});

  final CollectSummary data;

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    final d = data;
    final label = Theme.of(context).textTheme.labelMedium?.copyWith(color: context.palette.onSurfaceMuted);
    Widget fact(String title, Widget value, {Key? key}) => Padding(
          key: key,
          padding: const EdgeInsets.only(top: AppSpacing.sm),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(title, style: label), value]),
        );
    final journal = d.journal;
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.9),
        child: Column(
          key: const ValueKey('collect-summary'),
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Flexible(
              child: ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.md, AppSpacing.md, 0),
                children: [
                  Semantics(
                    header: true,
                    child: Text(w.collectCheckTitle, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                  ),
                  fact(w.collectCheckCustomer, Text(d.customer)),
                  fact(w.collectCheckAmount,
                      Text(Money.taka(d.amount / 100), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700))),
                  if (d.words != null) fact(w.collectCheckWords, Text(d.words!, key: const ValueKey('collect-summary-words'))),
                  fact(w.collectCheckAccount, Text(d.account)),
                  fact(
                    w.collectCheckBills,
                    d.bills == null || d.bills!.isEmpty
                        ? Text(w.collectBillsAuto)
                        : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                            for (final (no, paisa) in d.bills!) _AmountRow(label: no, paisa: paisa),
                          ]),
                    key: const ValueKey('collect-summary-bills'),
                  ),
                  if (d.withheld > 0) _AmountRow(label: w.collectWithheldTitle, paisa: d.withheld),
                  if (d.difference > 0) ...[
                    _AmountRow(label: w.collectDifference, paisa: d.difference),
                    _Note(text: w.collectDifferenceSign, tone: _Tone.info),
                  ],
                  if (d.unapplied case final u? when u > 0) _AmountRow(label: w.collectSumUnapplied, paisa: u),
                  if (journal != null && journal.isNotEmpty)
                    fact(
                      w.collectJournalTitle,
                      JournalLines(
                        lines: journal,
                        totalDr: journal.fold(0, (s, l) => s + l.dr),
                        totalCr: journal.fold(0, (s, l) => s + l.cr),
                      ),
                      key: const ValueKey('collect-summary-journal'),
                    ),
                  if (d.needsSignature == true) _Note(text: w.collectControlSignature, tone: _Tone.info),
                  if (d.needsSignature == false) _Note(text: w.collectControlNoSignature, tone: _Tone.info),
                  const SizedBox(height: AppSpacing.md),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Row(children: [
                Expanded(
                  child: OutlinedButton(
                    key: const ValueKey('collect-check-back'),
                    style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                    onPressed: () => Navigator.of(context).pop(false),
                    child: Text(w.collectCheckBack, textAlign: TextAlign.center),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: FilledButton(
                    key: const ValueKey('collect-check-post'),
                    style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
                    onPressed: () => Navigator.of(context).pop(true),
                    child: Text(w.collectCheckPost, textAlign: TextAlign.center),
                  ),
                ),
              ]),
            ),
          ],
        ),
      ),
    );
  }
}

class _CustomerSheet extends StatefulWidget {
  const _CustomerSheet({required this.customers});

  final List<CustomerRecord> customers;

  @override
  State<_CustomerSheet> createState() => _CustomerSheetState();
}

class _CustomerSheetState extends State<_CustomerSheet> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final rows = widget.customers.where((c) => c.isActive).where((c) => c.matches(_q)).take(100).toList();
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
            left: AppSpacing.md,
            right: AppSpacing.md,
            top: AppSpacing.md,
            bottom: MediaQuery.of(context).viewInsets.bottom + AppSpacing.md),
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.7,
          child: Column(
            children: [
              TextField(
                key: const ValueKey('collect-customer-search'),
                autofocus: true,
                decoration: InputDecoration(
                    labelText: context.l10n.booksCollectSearchLabel, prefixIcon: const Icon(Symbols.search)),
                onChanged: (v) => setState(() => _q = v),
              ),
              Expanded(
                child: rows.isEmpty
                    ? Center(child: Text(context.l10n.booksCollectNoCustomer))
                    : ListView(
                        children: [
                          for (final c in rows)
                            ListTile(
                              key: ValueKey('collect-pick-${c.id}'),
                              // ⭐ "নাম · পয়েন্ট · শাখা" এক লাইনে — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম); বকেয়া পাশে
                              title: CustomerLine(c.label),
                              subtitle: Text(CustomerDueRecord.forCustomer(c.id)?.outstandingLabel ?? ''),
                              onTap: () => Navigator.of(context).pop(c),
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
