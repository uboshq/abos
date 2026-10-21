import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/accounts/voucher_api.dart';
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
import '../../core/status/paper_state.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../printing/document_actions_sheet.dart';
import '../vouchers/voucher_screens.dart';
import 'money_in_screens.dart';

/// স্লিপের ছবি বাছাই — ক্যামেরা বা গ্যালারি; ফাইলের পথ, না বাছলে null
typedef SlipPicker = Future<String?> Function(BuildContext context, {required bool camera});

/// রসিদের চার পথ — পোস্টের পরের কার্ডের চিপ (চূড়ান্ত নকশা, পর্দা ২)
enum ReceiptAction { pdf, whatsapp, sms, print }

/// রসিদ খোলা — সার্ভারের PDF ([DocumentActionsSheet]: খোলা, ছাপা, WhatsApp/SMS/ইমেইলে পাঠানো)
typedef ReceiptOpener = Future<void> Function(BuildContext context, CollectionSaved saved, ReceiptAction action);

/// গ্রাহক নয় এমন পক্ষ (সরবরাহকারী, কর্মী, অন্য) — আদায় নয়; আজ তাদের টাকা নেওয়ার পথ রসিদ ভাউচারের ফর্ম ("অন্য আয়")
typedef OtherPartyOpener = Future<void> Function(BuildContext context, String kind);

Future<String?> _pickSlip(BuildContext context, {required bool camera}) async {
  final shot = camera
      ? await takeCameraShot(context, imageQuality: 70, maxWidth: 1600)
      : await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 70, maxWidth: 1600);
  return shot?.path;
}

/// ⓘ চার চিপই একই রসিদ-পাতা খোলে — সেখানে PDF খোলা, ছাপা আর ফোনের নিজের পাঠানোর পাতা (WhatsApp, SMS, ইমেইল)
Future<void> _openReceipt(BuildContext context, CollectionSaved saved, ReceiptAction action) => DocumentActionsSheet.show(context,
    type: 'Collection', id: saved.id, title: context.l10n.booksMoneyInPrintTitle(saved.no ?? ''), fileStem: saved.no);

Future<void> _otherParty(BuildContext context, String kind) => Navigator.of(context)
    .push(MaterialPageRoute<void>(builder: (_) => const VoucherFormScreen(type: VoucherTypes.receipt)));

Future<int> _fileSize(String path) => File(path).length();

/// ⭐ টাকা নেওয়া — এক পথ, দুই পর্দা, ওয়েবের একই ঘর আর নাম (মালিক, ২১ অক্টোবর ২০২৬: "ok ekoni kaj kore live e daw",
/// "100% quality"; চূড়ান্ত নকশা ১০০, পাতা ৫–৬, পর্দা ১ ও ২)।
///
/// <p><b>পর্দা ১</b> ("টাকা নেওয়া"), এক কলামে: বারে অবস্থার চিপ (আর নম্বর, বসার পরে); তিন অঙ্ক (বকেয়া · এই আদায় · বাকি
/// থাকবে); "সাধারণ তথ্য" — পক্ষের ধরন, গ্রাহক ("নাম · পয়েন্ট · শাখা", নিচে সীমা আর পুরনো বাকি), টাকা ("পুরো বকেয়া",
/// "নির্বাচিত বিল"), যে খাতে জমা, পদ্ধতি, রেফারেন্স / TrxID, ব্যাংকে এক লাইনে মোড · মূল্য-তারিখ · চার্জ ("বদলান");
/// "খোলা বিল ও ক্রেডিট" — ছোট সারি, পুরনো আগে নিজে বসা, সারি চাপলে অঙ্ক বদলানো; "কাটা ও ছাড়" (গুটানো); "সংযুক্তি ও
/// বিবরণ"। নিচে স্থির "খসড়া" আর "দেখে পোস্ট ›"।
///
/// <p><b>পর্দা ২</b> ([CollectReviewScreen], "পোস্টের আগে দেখুন"): হিসাবের সারাংশ, খাতায় যা যাবে, নিয়ন্ত্রণ; "ফিরে যান" আর
/// "পোস্ট"; বসার পরে "পোস্টের পরে" কার্ড — নম্বর, অবস্থা, রসিদ PDF · WhatsApp · SMS · ছাপুন।
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
    this.otherParty = _otherParty,
    this.slipSize = _fileSize,
  });

  static String _engineChangeId() => SyncEngine.instance.newChangeId();

  final CollectionEntryApi api;

  /// পরীক্ষার পথ — একই কাজে একই চাবি কি না দেখতে
  final String Function() newChangeId;
  final BooksApi books;
  final DateTime? today;
  final SlipPicker pickSlip;
  final ReceiptOpener openReceipt;
  final OtherPartyOpener otherParty;
  final Future<int> Function(String path) slipSize;

  /// সার্ভারের ছবির সীমা — 5 MB
  static const slipLimit = 5 * 1024 * 1024;

  /// ⭐ রাউন্ডিংয়ের তিন ধাপ (মালিকের নিয়ম, ২ অক্টোবর ২০২৬; fe, ২২ অক্টোবর ২০২৬) — পয়সায়, পূর্ণসংখ্যায় মাপা, তাই সীমায় ভুল নেই:
  /// ৳0.50 পর্যন্ত সই ছাড়া; তার বেশি ৳10 পর্যন্ত মালিকের সই লাগে (আটকায় না); ৳10-এর বেশি ফোনেই আটকায়। আসল পাহারা সার্ভারের।
  static const roundingFreeLimit = 50;
  static const roundingLimit = 1000;

  /// রাউন্ডিংয়ে মালিকের সই লাগবে কি না
  static bool roundingNeedsSign(int paisa) => paisa > roundingFreeLimit;

  /// ⭐ ওয়েবের পদ্ধতি, ক্রমে — টাকার-পদ্ধতির মডেলের `instrument` (a5, ২১ অক্টোবর ২০২৬)
  static const methods = ['cash', 'mfs', 'transfer', 'deposit', 'cheque'];

  /// পক্ষের ধরন — গ্রাহক এই পাতার; বাকিরা রসিদ ভাউচারের ফর্মে
  static const partyKinds = ['customer', 'supplier', 'staff', 'other'];

  @override
  State<CollectScreen> createState() => _CollectScreenState();
}

enum _Bills { none, loading, loaded, absent, failed, outOfReach }

/// পর্দা ২-এর পোস্টের ফল
class CollectPostResult {
  const CollectPostResult({this.saved, this.oldLanded, this.error});

  /// নতুন সার্ভার — নম্বর আর অবস্থাসহ
  final CollectionSaved? saved;

  /// পুরনো সার্ভার — কেবল আইডি; আজকের রসিদ-পাতা খোলে
  final String? oldLanded;
  final String? error;
}

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
  PlanRow? _editing;
  bool _folded = false;
  bool _manual = false;
  String _differenceKind = 'discount';

  /// পক্ষের ধরন — গ্রাহক ছাড়া বাছলে নিচে নোট আর পুরনো ফর্মের বোতাম; এই পাতা থেকে পাঠানো বন্ধ
  String _party = 'customer';

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
    // ⭐ টাকার ঘর ছাড়লে টাকার রূপে ("45,000.00", ডানে) — লেখার সময় যেমন লেখা তেমন (চূড়ান্ত নকশা, পর্দা ১)
    _amountFocus.addListener(() {
      if (!_amountFocus.hasFocus) _formatAmount();
    });
    _loadSetup();
  }

  @override
  void dispose() {
    for (final c in [_amount, _reference, _depositSlip, _charge, _difference, _vds, _ait, _narration, ..._rowText.values]) {
      c.dispose();
    }
    for (final f in [_amountFocus, _referenceFocus, _depositSlipFocus, _narrationFocus]) {
      f.dispose();
    }
    super.dispose();
  }

  // ── অঙ্ক ──

  static int _paisa(String text) => ((Money.typed(text) ?? 0) * 100).round();

  int get _received => _paisa(_amount.text);
  int get _differencePaisa => _newServer ? _paisa(_difference.text) : 0;
  int get _vdsPaisa => _newServer ? _paisa(_vds.text) : 0;
  int get _aitPaisa => _newServer ? _paisa(_ait.text) : 0;
  int get _withheldPaisa => _vdsPaisa + _aitPaisa;

  /// বকেয়া — নতুন সার্ভারে খোলা বিলের উত্তর থেকে (ওয়েবের একই), পুরনোয় ফোনে রাখা বকেয়া
  int? get _due {
    if (_customer == null) return null;
    if (_openBills case final b?) return b.due;
    final cached = CustomerDueRecord.forCustomer(_customer!.id);
    return cached == null ? null : (cached.outstandingInView * 100).round();
  }

  bool get _newServer => _billsState == _Bills.loaded;
  static String _kindOf(String method) => switch (method) { 'cash' => 'cash', 'mfs' => 'mfs', _ => 'bank' };
  String get _kind => _kindOf(_method);

  /// রেফারেন্স / TrxID — MFS, ট্রান্সফার আর চেকে (নগদে নয়; নগদ জমায় স্লিপ নম্বর)
  bool get _needsReference => _method == 'mfs' || _method == 'transfer' || _method == 'cheque';
  bool get _needsDepositSlip => _method == 'deposit';
  bool get _isBank => _kind == 'bank';
  bool get _slipAllowed => _newServer;
  bool get _slipRequired => _newServer && (_openBills?.attachmentRequired ?? false);
  List<PlanRow> get _rows => [..._plan.bills, ..._plan.credits];

  /// এক টেবিল, সার্ভারের ক্রমে (পুরনো আগে)
  List<PlanRow> get _ordered {
    final rows = _rows;
    final b = _openBills;
    if (b == null) return rows;
    return [
      for (final item in b.items)
        if (rows.where((r) => identical(r.item, item)).firstOrNull case final r?) r,
    ];
  }

  /// লেখা টাকা টাকার রূপে — "45000" → "45,000.00"; পড়া না গেলে যেমন আছে (ভুলটা পাঠানোর সময় ধরা পড়ে)
  void _formatAmount() {
    final text = Money.typedText(_amount.text);
    if (!_amountShape.hasMatch(text)) return;
    final formatted = Money.amount(_received / 100);
    if (_amount.text != formatted) _amount.text = formatted;
  }

  /// টাকার ঘরে একটা অঙ্ক, টাকার রূপে
  void _setAmount(int paisa) => _amount.text = Money.amount(paisa / 100);

  /// ⓘ অঙ্ক না বদলালে (যেমন কেবল টাকার রূপে সাজানো) বণ্টন যেমন ছিল তেমন — হাতে বদলানো বিল হারায় না
  void _figuresChanged() {
    if (_plan.received == _received && _plan.difference == _differencePaisa && _plan.withheld == _withheldPaisa) {
      setState(() {});
      return;
    }
    _reallocate();
  }

  void _reallocate() {
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
  }

  void _textChanged() => setState(() {});

  void _syncRowText() {
    for (final row in _rows) {
      final c = _rowText.putIfAbsent(row, TextEditingController.new);
      final text = row.applied == 0 ? '' : paisaText(row.applied);
      if (c.text != text) c.text = text;
    }
  }

  // ── আনা ──

  Future<void> _loadSetup() async {
    try {
      final setup = await widget.api.setup();
      final list = CollectionAccount.ownCashFirst(setup.accounts);
      if (!mounted) return;
      setState(() {
        _accounts = list;
        _transferModes = setup.transferModes;
        _transferModeId = _transferModes.firstOrNull?.id;
        _account = list.isEmpty ? null : list.first;
        if (_account != null) _method = _defaultMethod(_account!.kind);
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e, fallback: AppLocale.l10n.booksCollectAccountsFailed));
      }
    }
  }

  static String _defaultMethod(String kind) => switch (kind) { 'cash' => 'cash', 'mfs' => 'mfs', _ => 'transfer' };

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
      _editing = null;
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
      _reallocate();
    } on CustomerOutOfReach {
      // ⛔ গ্রাহক নাগালের বাইরে (সার্ভারের ইচ্ছাকৃত ৪০৪) — পুরনো সার্ভার নয়; বলা হয়, পাঠানো বন্ধ
      if (mounted && _customer?.id == picked.id) {
        setState(() {
          _billsState = _Bills.outOfReach;
          _error = context.l10n.collectOutOfBranch;
        });
      }
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
  }

  void _chooseMethod(String method) {
    final kind = _kindOf(method);
    setState(() {
      _method = method;
      if (_account?.kind != kind) _account = _accounts.where((a) => a.kind == kind).firstOrNull;
    });
  }

  void _chooseAccount(CollectionAccount? a) {
    if (a == null) return;
    setState(() {
      _account = a;
      if (_kindOf(_method) != a.kind) _method = _defaultMethod(a.kind);
    });
  }

  /// ⭐ গ্রাহক ছাড়া অন্য পক্ষ — চুপচাপ পুরনো ফর্ম নয়: নিচে নোট, আর বোতাম চাপলে তবেই রসিদ ভাউচারের ফর্ম (fe, ২১ অক্টোবর ২০২৬;
  /// চার ধরনের এক পাতা পরে, সার্ভার তৈরি হলে)
  void _chooseParty(String kind) => setState(() => _party = kind);

  /// ⓘ রাউন্ডিং ৳10-এর বেশি — ফোনের আগাম সতর্কতা; আসল পাহারা সার্ভারের (ফেরালে তার কথাই দেখায়)
  bool get _roundingTooBig => _differenceKind == 'rounding' && _differencePaisa > CollectScreen.roundingLimit;

  // ── বিল ──

  void _tick(PlanRow row, bool on) {
    setState(() {
      _manual = true;
      _rowError.remove(row);
      row.selected = on;
      if (row.item.isCredit) {
        if (!_plan.setCredit(row, on ? row.item.open : 0)) _rowError[row] = context.l10n.collectCreditFloor;
      } else {
        _plan.setBill(row, on ? row.item.open : 0);
      }
      _syncRowText();
    });
  }

  void _typeApplied(PlanRow row, String text) {
    final want = _paisa(text);
    setState(() {
      _manual = true;
      _rowError.remove(row);
      final ok = row.item.isCredit ? _plan.setCredit(row, want) : _plan.setBill(row, want);
      row.selected = row.applied > 0;
      if (!ok) {
        _rowError[row] = row.item.isCredit
            ? context.l10n.collectCreditFloor
            : context.l10n.collectOverApplied(Money.taka(row.applied / 100));
        // ⛔ বেশি চাওয়া অঙ্ক থাকে না — যতটা বসল ততটাই লেখা
        _rowText[row]!.text = row.applied == 0 ? '' : paisaText(row.applied);
      }
    });
  }

  void _oldestFirst() {
    setState(() {
      _plan.autoAllocate();
      _manual = false;
      _rowError.clear();
      _syncRowText();
    });
  }

  /// ⭐ "নির্বাচিত বিল" — টিক দেওয়া বিলের বাকি (ক্রেডিট বাদে, ছাড় আর কাটা বাদে) টাকার ঘরে, আর ঠিক সেই বিলগুলোয় বসে
  void _fillSelected() {
    final chosen = {for (final r in _rows) if (r.selected) r};
    final bills = _plan.bills.where(chosen.contains).fold(0, (s, r) => s + r.item.open);
    final credits = _plan.credits.where(chosen.contains).fold(0, (s, r) => s + r.item.open);
    var target = bills - credits - _differencePaisa - _withheldPaisa;
    if (target < 0) target = 0;
    _setAmount(target);
    setState(() {
      _plan.received = target;
      for (final r in _rows) {
        r.applied = 0;
        r.selected = chosen.contains(r);
      }
      for (final c in _plan.credits.where(chosen.contains)) {
        _plan.setCredit(c, c.item.open);
      }
      for (final b in _plan.bills.where(chosen.contains)) {
        _plan.setBill(b, b.item.open);
      }
      _manual = true;
      _rowError.clear();
      _syncRowText();
    });
  }

  // ── খসড়া, পাঠানো ──

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
        vds: _vdsPaisa > 0 ? paisaText(_vdsPaisa) : null,
        ait: _aitPaisa > 0 ? paisaText(_aitPaisa) : null,
        narration: _narration.text,
        confirm: confirm,
        // ⛔ খোলা বিল না এলে লাইন নয় — সার্ভার নিজে পুরনো বিল আগে বসায়; সব টিক তোলা হলে `[]` — কিছুই বসে না, সব গ্রাহকের জমা
        lines: _newServer ? _plan.lines : null,
        difference: _differencePaisa > 0 ? (kind: _differenceKind, amount: paisaText(_differencePaisa)) : null,
      );

  String? _problem({required bool post}) {
    final w = context.l10n;
    if (_customer == null) return w.booksCollectPickCustomerError;
    if (!_amountValid) return w.booksCollectAmountWrong;
    if (_account == null) return w.booksCollectPickAccountError;
    if (_needsReference && _reference.text.trim().isEmpty) return w.collectReferenceMissing;
    if (_needsDepositSlip && _depositSlip.text.trim().isEmpty) return w.collectDepositSlipMissing;
    if (_differencePaisa + _withheldPaisa > _received) return w.collectDifferenceTooBig;
    if (_differenceKind == 'rounding' && _differencePaisa > CollectScreen.roundingLimit) return w.collectRoundingTooBig;
    if (post && _slipRequired && _slipPath == null) return w.collectSlipMissing;
    return null;
  }

  /// "খসড়া" — সারাংশ ছাড়া, নিশ্চিত না করে
  Future<void> _saveDraft() async {
    final problem = _problem(post: false);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final result = await _push(confirm: false);
    if (!mounted) return;
    setState(() => _busy = false);
    if (result.oldLanded case final id?) await _openOldReceipt(id);
  }

  /// ⭐ "দেখে পোস্ট ›" — পর্দা ২: সারাংশ আর খাতার প্রিভিউ, তারপর এক চাপ
  Future<void> _review() async {
    final problem = _problem(post: true);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    CollectionPreview? preview;
    try {
      preview = await widget.api.preview(_draft(confirm: true));
    } on CustomerOutOfReach {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _billsState = _Bills.outOfReach;
        _error = context.l10n.collectOutOfBranch;
      });
      return;
    } catch (_) {
      // ⓘ প্রিভিউ না এলে সারাংশ তবু খোলে — সার্ভার পোস্টে নিজেই সব দেখে
      preview = null;
    }
    if (!mounted) return;
    setState(() => _busy = false);
    final landed = await Navigator.of(context).push<String>(MaterialPageRoute(
      builder: (_) => CollectReviewScreen(
        data: _reviewData(preview),
        post: () => _push(confirm: true),
        openReceipt: widget.openReceipt,
      ),
    ));
    if (landed != null && mounted) await _openOldReceipt(landed);
  }

  Future<void> _openOldReceipt(String id) => Navigator.of(context).pushReplacement(MaterialPageRoute<void>(
        builder: (_) => MoneyInScreen(id: id, api: widget.books),
      ));

  CollectReview _reviewData(CollectionPreview? preview) => CollectReview(
        customer: _customer!.label,
        account: _account == null ? '' : '${_methodLabel(context, _method)} · ${_account!.label}',
        received: _received,
        withheld: _withheldPaisa,
        discount: _differenceKind == 'discount' ? _differencePaisa : 0,
        rounding: _differenceKind == 'rounding' ? _differencePaisa : 0,
        netApplied: _newServer ? _plan.netApplied : null,
        unapplied: _newServer ? _plan.unapplied : null,
        bills: _newServer
            ? [for (final r in _ordered) if (r.applied > 0) (r.item.no, r.item.isCredit ? -r.applied : r.applied)]
            : null,
        words: preview?.amountWords ?? Money.spoken(_received / 100, englishDigits: true),
        preview: preview,
        monthClosed: _openBills?.monthOpen == false,
        slipRequired: _slipRequired,
      );

  /// পাঠানো — দুই পর্দার একটাই পথ; একই চাবি আবার পাঠালে একটাই রসিদ
  Future<CollectPostResult> _push({required bool confirm}) async {
    final changeId = _changeId ??= widget.newChangeId();
    try {
      final outcome = await widget.api.send(_draft(confirm: confirm), changeId);
      final landed = outcome.landedId;
      if (landed == null) {
        // ⓘ সার্ভার ফেরাল — কারণসহ (একই রেফারেন্স হলে আগের কাগজের নম্বরসহ); ঠিক করে আবার পাঠালে নতুন চাবি
        final reason = outcome.refusal ?? AppLocale.l10n.booksCollectNotLanded;
        if (mounted) {
          setState(() {
            _error = reason;
            _changeId = null;
          });
        }
        return CollectPostResult(error: reason);
      }
      var saved = CollectionSaved.of(landed, outcome.details);
      if (_slipPath != null && _slipAllowed) {
        try {
          // ⭐ স্লিপ পৌঁছালে সার্ভার "পোস্ট" চাওয়া খসড়াকে পোস্ট করে — চিপে সেই নতুন অবস্থা
          saved = saved.withState(await widget.api.attachSlip(landed, _slipPath!));
        } catch (_) {
          _slipFailed = true;
        }
      }
      // ⛔ পুরনো সার্ভার — নম্বর বা অবস্থা নেই: আজকের মতো, নম্বরসহ রসিদ-পাতা
      if (!saved.rich) return CollectPostResult(oldLanded: landed);
      if (mounted) setState(() => _saved = saved);
      return CollectPostResult(saved: saved);
    } on NoNetworkForThis catch (e) {
      // ⓘ চাবি থাকে — নেট এলে আবার চাপলে একই কাজ, দুবার নয়
      final reason = e.mayHaveLanded ? AppLocale.l10n.booksCollectMayHaveLanded : AppLocale.l10n.booksCollectNeedsNet;
      if (mounted) setState(() => _error = reason);
      return CollectPostResult(error: reason);
    } catch (e) {
      final reason = errorMessageFor(e, fallback: AppLocale.l10n.booksCollectSendFailed);
      if (mounted) setState(() => _error = reason);
      return CollectPostResult(error: reason);
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
      _narrationFocus,
    ];
    final i = order.indexOf(node);
    if (i >= 0 && i + 1 < order.length) {
      order[i + 1].requestFocus();
    } else {
      node.unfocus();
    }
  }

  /// ব্যাংকের মোড, মূল্য-তারিখ আর চার্জ — "বদলান" চাপলে
  Future<void> _editBank() async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => StatefulBuilder(builder: (sheet, refresh) {
        final w = sheet.l10n;
        return SafeArea(
          child: Padding(
            padding: EdgeInsets.fromLTRB(
                AppSpacing.md, AppSpacing.md, AppSpacing.md, MediaQuery.of(sheet).viewInsets.bottom + AppSpacing.md),
            child: Column(
              key: const ValueKey('collect-bank-editor'),
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Semantics(
                  header: true,
                  child: Text(w.collectBankEditorTitle, style: Theme.of(sheet).textTheme.titleMedium),
                ),
                if (_method == 'transfer' && _transferModes.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.sm),
                  DropdownButtonFormField<String>(
                    key: const ValueKey('collect-transfer-mode'),
                    initialValue: _transferModeId,
                    decoration: InputDecoration(labelText: w.collectTransferMode),
                    items: [for (final m in _transferModes) DropdownMenuItem(value: m.id, child: Text(m.name))],
                    onChanged: (m) {
                      setState(() => _transferModeId = m ?? _transferModeId);
                      refresh(() {});
                    },
                  ),
                ],
                const SizedBox(height: AppSpacing.sm),
                _Tappable(
                  key: const ValueKey('collect-value-date'),
                  label: _method == 'cheque' ? w.collectChequeDate : w.collectValueDate,
                  value: Text(_dayText(_valueDate ?? _date)),
                  icon: Symbols.event,
                  onTap: () async {
                    await _pickDate(value: true);
                    refresh(() {});
                  },
                ),
                const SizedBox(height: AppSpacing.sm),
                TextField(
                  key: const ValueKey('collect-bank-charge'),
                  controller: _charge,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(labelText: w.collectBankCharge, helperText: w.collectBankChargeHint, helperMaxLines: 3),
                ),
                const SizedBox(height: AppSpacing.md),
                FilledButton(
                  key: const ValueKey('collect-bank-done'),
                  style: FilledButton.styleFrom(minimumSize: const Size(48, 48), textStyle: _buttonText(context)),
                  onPressed: () => Navigator.of(sheet).pop(),
                  child: Text(w.collectBankDone),
                ),
              ],
            ),
          ),
        );
      }),
    );
    if (mounted) setState(() {});
  }

  // ── আঁকা ──

  @override
  Widget build(BuildContext context) => UnsavedGuard(
      dirty: !_busy &&
          _saved == null &&
          (_customer != null ||
              _amount.text.trim().isNotEmpty ||
              _reference.text.trim().isNotEmpty ||
              _depositSlip.text.trim().isNotEmpty ||
              _narration.text.trim().isNotEmpty),
      child: _screen(context));

  Widget _screen(BuildContext context) {
    final w = context.l10n;
    final saved = _saved;
    // ⓘ বড় লেখায় (২০০%) বারে জায়গা নেই — চিপ আর নম্বর তখন নিচের লাইনে
    final big = MediaQuery.textScalerOf(context).scale(1) > 1.3;
    return Scaffold(
      appBar: AbosScreenBar(title: w.collectTitle, actions: [
        if (!big) ..._stateMarks(saved),
      ]),
      bottomNavigationBar: _bottomBar(context),
      // ⓘ সব ঘর একসাথে আঁকা (অলস তালিকা নয়) — কীবোর্ডের Tab আর TalkBack পাতার নিচের ঘরেও পৌঁছায়
      body: SingleChildScrollView(
        key: const ValueKey('collect-list'),
        padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.xl),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (big) Wrap(spacing: AppSpacing.sm, crossAxisAlignment: WrapCrossAlignment.center, children: _stateMarks(saved)),
          _Figures(due: _due, received: _received, settled: _differencePaisa + _withheldPaisa),
          _header(context),
          if (_busy) const Padding(padding: EdgeInsets.only(top: AppSpacing.sm), child: LinearProgressIndicator()),
          if (_error != null) _ErrorCard(_error!),
          if (saved != null) ..._done(context, saved) else ..._form(context),
        ]),
      ),
    );
  }

  /// ⭐ অবস্থা — বসার আগে "খসড়া", পরে সার্ভারের অবস্থা (চূড়ান্ত নকশা, পর্দা ১: বারের ডানে); নম্বর নিচের লাইনে, বারে জায়গা কম
  List<Widget> _stateMarks(CollectionSaved? saved) => [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
          child: Center(
            child: saved?.state == null
                ? const AppStatusChip(key: ValueKey('collect-state-draft'), state: PaperState.draft, dense: true)
                : AppStatusChip.forServer(saved!.state!, key: const ValueKey('collect-state'), fallbackLabel: saved.state!, dense: true),
          ),
        ),
      ];

  /// গ্রাহকের শাখা আর মাস — বিষয় ১৩ ও ১৪
  Widget _header(BuildContext context) {
    final w = context.l10n;
    final muted = TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5);
    // ⓘ সার্ভার যে শাখা বলে (খোলা বিলের `customer.branch`), নইলে ফোনে রাখা গ্রাহকের শাখা
    final branch = _openBills?.branch ?? _customer?.branchName;
    final month = _openBills?.monthOpen;
    final no = _saved?.no;
    if (branch == null && month == null && no == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.xs),
      child: Wrap(
        key: const ValueKey('collect-header'),
        spacing: AppSpacing.sm,
        runSpacing: AppSpacing.xs,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          if (no != null) Text(w.collectNumber(no), key: const ValueKey('collect-number'), style: muted.copyWith(fontWeight: FontWeight.w600)),
          if (branch != null) Text(w.collectBranchOf(branch), key: const ValueKey('collect-branch'), style: muted),
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
      ),
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
          for (final (no, paisa) in saved.applied) _AmountRow(label: no.isEmpty ? w.booksMoneyInAdvance : no, paisa: paisa),
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
    final muted = TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5);
    final anySelected = _rows.any((r) => r.selected);
    final facts = [
      if (b?.creditLimit case final limit?) w.collectCreditLimit(Money.taka(limit / 100)),
      if (b?.oldestOverdueDays case final days?) w.collectOldestOverdue(days),
      // ⓘ পুরনো সার্ভারে সীমা নেই — তখন ফোনে রাখা বকেয়া
      if (b == null && due != null) w.collectDue(Money.taka(due / 100)),
    ];
    return [
      // ── সাধারণ তথ্য ──
      _Section(title: w.collectGeneralTitle, children: [
        _Label(w.collectPartyKind),
        Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
          for (final k in CollectScreen.partyKinds)
            _Segment(
              key: ValueKey('collect-party-$k'),
              label: Text(switch (k) {
                'customer' => w.collectPartyCustomer,
                'supplier' => w.collectPartySupplier,
                'staff' => w.collectPartyStaff,
                _ => w.collectPartyOther,
              }),
              selected: _party == k,
              onSelected: _busy ? null : (_) => _chooseParty(k),
            ),
        ]),
        if (_party != 'customer')
          Container(
            key: const ValueKey('collect-party-note'),
            margin: const EdgeInsets.only(top: AppSpacing.xs),
            padding: const EdgeInsetsDirectional.only(start: AppSpacing.sm),
            decoration: BoxDecoration(
              color: context.palette.pendingSurface,
              borderRadius: BorderRadius.circular(6),
              border: BorderDirectional(start: BorderSide(color: context.palette.pending, width: 3)),
            ),
            child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [
              Text(w.collectPartyOldNote, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: context.palette.onSurface)),
              TextButton(
                key: const ValueKey('collect-party-open-old'),
                style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                onPressed: () => widget.otherParty(context, _party),
                child: Text(w.collectPartyOldOpen),
              ),
            ]),
          ),
        const SizedBox(height: AppSpacing.sm),
        _Tappable(
          key: const ValueKey('collect-customer'),
          label: w.collectCustomer,
          value: _customer == null ? null : CustomerLine(_customer!.label),
          placeholder: w.collectPickCustomer,
          icon: Symbols.person_search,
          onTap: _busy ? null : _pickCustomer,
        ),
        if (_customer != null && facts.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.xs, left: AppSpacing.xs),
            child: Text(facts.join(' · '), key: const ValueKey('collect-customer-facts'), style: muted),
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
          textAlign: TextAlign.end,
          style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          textInputAction: TextInputAction.next,
          onEditingComplete: () {
            _formatAmount();
            _nextFrom(_amountFocus);
          },
          decoration: InputDecoration(labelText: w.collectAmount, prefixText: '৳ '), // l10n-ok: the taka sign, the same in every language
        ),
        const SizedBox(height: AppSpacing.xs),
        Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
          _TonalChip(
            key: const ValueKey('collect-full-due'),
            label: w.collectFullDue,
            onPressed: due == null || due <= 0 || _busy ? null : () => _setAmount(due),
          ),
          if (_newServer)
            _TonalChip(
              key: const ValueKey('collect-selected-bills'),
              label: w.collectSelectedBills,
              onPressed: !anySelected || _busy ? null : _fillSelected,
            ),
        ]),
        if (over)
          _Note(
            key: const ValueKey('collect-over-due'),
            text: w.collectOverDue(Money.taka((_received + _differencePaisa + _withheldPaisa - due) / 100)),
            tone: _Tone.warn,
          ),
        const SizedBox(height: AppSpacing.sm),
        DropdownButtonFormField<CollectionAccount>(
          key: const ValueKey('collect-account'),
          initialValue: _accounts.contains(_account) ? _account : null,
          isExpanded: true,
          decoration: InputDecoration(labelText: w.collectAccount),
          items: [
            for (final a in _accounts)
              DropdownMenuItem(
                value: a,
                child: Text(a.mine ? w.collectAccountMine(a.label) : a.label, maxLines: 1, overflow: TextOverflow.ellipsis),
              ),
          ],
          onChanged: _busy ? null : _chooseAccount,
        ),
        const SizedBox(height: AppSpacing.sm),
        _Label(w.collectMethod),
        Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
          for (final m in CollectScreen.methods)
            _Segment(
              key: ValueKey('collect-method-$m'),
              label: Text(_methodLabel(context, m)),
              selected: _method == m,
              onSelected: _busy || !_accounts.any((a) => a.kind == _kindOf(m)) ? null : (_) => _chooseMethod(m),
            ),
        ]),
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
        if (_isBank) _bankLine(context),
      ]),
      // ── খোলা বিল ও ক্রেডিট ──
      _bills(context),
      // ── কাটা ও ছাড় (গুটানো) ──
      if (_newServer) _adjustSection(context),
      // ── সংযুক্তি ও বিবরণ ──
      _Section(title: w.collectAttachTitle, children: [
        if (_slipAllowed) ...[
          if (_slipRequired) _Label(w.collectSlipNeeded, key: const ValueKey('collect-slip-required')),
          if (_slipPath == null)
            _DashedBox(
              key: const ValueKey('collect-slip-box'),
              child: Wrap(alignment: WrapAlignment.center, crossAxisAlignment: WrapCrossAlignment.center, children: [
                TextButton.icon(
                  key: const ValueKey('collect-slip-camera'),
                  style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                  onPressed: _busy ? null : () => _chooseSlip(true),
                  icon: const Icon(Symbols.photo_camera),
                  label: Text(w.collectSlipTake),
                ),
                ExcludeSemantics(child: Text('/', style: muted)),
                TextButton(
                  key: const ValueKey('collect-slip-gallery'),
                  style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                  onPressed: _busy ? null : () => _chooseSlip(false),
                  child: Text(w.collectSlipGallery),
                ),
              ]),
            )
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
          decoration: InputDecoration(labelText: w.collectNarration, counterText: ''),
        ),
      ]),
    ];
  }

  /// ⭐ ব্যাংকের এক লাইন — "মোড NPSB · মূল্য-তারিখ আজ · চার্জ 0  বদলান" (চূড়ান্ত নকশা, পর্দা ১)
  Widget _bankLine(BuildContext context) {
    final w = context.l10n;
    final mode = _transferModes.where((m) => m.id == _transferModeId).firstOrNull?.name;
    final day = _valueDate == null || _valueDate == _today ? w.collectToday : _dayText(_valueDate!);
    final parts = [
      if (_method == 'transfer' && mode != null) w.collectBankMode(mode),
      _method == 'cheque' ? w.collectBankChequeDate(day) : w.collectBankValueDate(day),
      w.collectBankChargeShort(Money.plain(_paisa(_charge.text) / 100)),
    ];
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.xs),
      child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [
        Text(parts.join(' · '), key: const ValueKey('collect-bank-line'),
            style: TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5)),
        TextButton(
          key: const ValueKey('collect-bank-change'),
          style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
          onPressed: _busy ? null : _editBank,
          child: Text(w.collectBillsChange),
        ),
      ]),
    );
  }

  Widget _bills(BuildContext context) {
    final w = context.l10n;
    final muted = TextStyle(color: context.palette.onSurfaceMuted);
    final ordered = _ordered;
    final applied = [
      for (final r in ordered)
        if (r.applied > 0) '${r.item.no} ${Money.taka((r.item.isCredit ? -r.applied : r.applied) / 100)}',
    ];
    final table = _billsState == _Bills.loaded && ordered.isNotEmpty;
    return _Section(
      key: const ValueKey('collect-bills'),
      title: w.collectBillsTitle,
      trailing: table
          ? TextButton.icon(
              key: const ValueKey('collect-bills-change'),
              style: TextButton.styleFrom(minimumSize: const Size(48, 48), foregroundColor: context.palette.onSurfaceMuted),
              iconAlignment: IconAlignment.end,
              onPressed: () => setState(() => _folded = !_folded),
              icon: Icon(_folded ? Symbols.expand_more : Symbols.expand_less, size: 18),
              label: Text(w.collectBillsCount(ordered.length)),
            )
          : null,
      children: [
        switch (_billsState) {
          _Bills.none => Text(w.collectBillsNeedCustomer, style: muted),
          _Bills.loading => const LinearProgressIndicator(),
          // ⛔ পুরনো সার্ভার: তালিকা নেই, সার্ভার নিজে পুরনো বিল আগে বসায়
          _Bills.absent => Text(w.collectBillsAuto, key: const ValueKey('collect-bills-auto')),
          _Bills.failed => Text(w.collectBillsFailed, key: const ValueKey('collect-bills-failed'), style: muted),
          _Bills.outOfReach => Text(w.collectOutOfBranch, key: const ValueKey('collect-out-of-branch'), style: muted),
          _Bills.loaded when ordered.isEmpty => Text(w.collectBillsNone, key: const ValueKey('collect-bills-none'), style: muted),
          _Bills.loaded when _folded => Text(
              applied.isEmpty ? w.collectBillsAuto : (_manual ? applied.join(' · ') : w.collectBillsAutoWith(applied.join(' · '))),
              key: const ValueKey('collect-bills-summary'),
            ),
          _Bills.loaded => const SizedBox.shrink(),
        },
        if (table && !_folded) ...[
          for (final (i, r) in ordered.indexed) ...[
            if (i > 0) const Divider(height: 1),
            _ItemRow(
              key: ValueKey('collect-item-${r.item.no}'),
              row: r,
              editing: identical(_editing, r),
              controller: _rowText.putIfAbsent(r, TextEditingController.new),
              error: _rowError[r],
              enabled: !_busy,
              onTick: (on) => _tick(r, on),
              onTap: () => setState(() => _editing = identical(_editing, r) ? null : r),
              onChanged: (text) => _typeApplied(r, text),
            ),
          ],
          const Divider(height: AppSpacing.md),
        ],
        if (table)
          _AmountRow(key: const ValueKey('collect-applied-total'), label: w.collectAppliedTotal, paisa: _plan.netApplied, strong: true),
        if (table && _manual && !_folded)
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
    );
  }

  /// ⭐ কাটা ও ছাড় — ছাড়/রাউন্ডিং আর উৎসে কাটা; নকশার পর্দা ১-এ নেই, তাই গুটানো কার্ডে, পর্দা ২-এ সারাংশের লাইন
  Widget _adjustSection(BuildContext context) {
    final w = context.l10n;
    return Card(
      margin: const EdgeInsets.only(top: AppSpacing.sm),
      child: ExpansionTile(
        key: const ValueKey('collect-adjust'),
        shape: const Border(),
        collapsedShape: const Border(),
        tilePadding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
        childrenPadding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.md),
        title: Text(w.collectWithheldTitle, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
        initiallyExpanded: _differencePaisa > 0 || _withheldPaisa > 0,
        expandedCrossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _Label(w.collectDifference),
          Wrap(spacing: AppSpacing.xs, children: [
            for (final (kind, label) in [('discount', w.collectDifferenceDiscount), ('rounding', w.collectDifferenceRounding)])
              _Segment(
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
            enabled: !_busy,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: w.collectDifferenceAmount),
          ),
          if (_differenceKind == 'discount' && _differencePaisa > 0)
            _Note(key: const ValueKey('collect-difference-sign'), text: w.collectDifferenceSign, tone: _Tone.info),
          if (_differenceKind == 'rounding' && CollectScreen.roundingNeedsSign(_differencePaisa) && !_roundingTooBig)
            _Note(
                key: const ValueKey('collect-rounding-sign-1'),
                text: w.collectRoundingSign(Money.taka(_differencePaisa / 100)),
                tone: _Tone.info),
          if (_roundingTooBig) _Note(key: const ValueKey('collect-rounding-warning'), text: w.collectRoundingTooBig, tone: _Tone.stop),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('collect-vds'),
            controller: _vds,
            enabled: !_busy,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: w.collectVds),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('collect-ait'),
            controller: _ait,
            enabled: !_busy,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: w.collectAit),
          ),
        ],
      ),
    );
  }

  Widget _bottomBar(BuildContext context) {
    final w = context.l10n;
    final saved = _saved;
    return _BottomBar(children: [
      if (saved == null) ...[
        Expanded(
          child: OutlinedButton(
            key: const ValueKey('collect-draft'),
            style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48), textStyle: _buttonText(context)),
            onPressed: _busy || _party != 'customer' || _billsState == _Bills.outOfReach ? null : _saveDraft,
            child: Text(w.collectDraft, textAlign: TextAlign.center),
          ),
        ),
        const SizedBox(width: AppSpacing.sm),
        Expanded(
          flex: 2,
          child: FilledButton(
            key: const ValueKey('collect-post'),
            style: FilledButton.styleFrom(minimumSize: const Size(48, 48), textStyle: _buttonText(context)),
            onPressed: _busy || _party != 'customer' || _roundingTooBig || _billsState == _Bills.outOfReach ? null : _review,
            child: Text(w.collectPost, textAlign: TextAlign.center),
          ),
        ),
      ] else ...[
        Expanded(
          child: OutlinedButton(
            key: const ValueKey('collect-close'),
            style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48), textStyle: _buttonText(context)),
            onPressed: () => Navigator.of(context).maybePop(),
            child: Text(w.collectClose, textAlign: TextAlign.center),
          ),
        ),
        if (saved.receipt != null) ...[
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: FilledButton.icon(
              key: const ValueKey('collect-open-receipt'),
              style: FilledButton.styleFrom(minimumSize: const Size(48, 48), textStyle: _buttonText(context)),
              onPressed: () => widget.openReceipt(context, saved, ReceiptAction.pdf),
              icon: const Icon(Symbols.receipt_long),
              label: Text(w.collectOpenReceipt, textAlign: TextAlign.center),
            ),
          ),
        ],
      ],
    ]);
  }
}

/// ⭐ নিচের বোতামের লেখা — Material 3-এর labelLarge: 14, w600 (fe, ২২ অক্টোবর ২০২৬; থিমের মাপ নিচে নামলেও 14)
TextStyle _buttonText(BuildContext context) =>
    (Theme.of(context).textTheme.labelLarge ?? const TextStyle()).copyWith(fontSize: 14, fontWeight: FontWeight.w600);

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

/// পর্দা ২-এর তথ্য — পর্দা ১ যা জানে
class CollectReview {
  const CollectReview({
    required this.customer,
    required this.account,
    required this.received,
    this.withheld = 0,
    this.discount = 0,
    this.rounding = 0,
    this.netApplied,
    this.unapplied,
    this.bills,
    this.words,
    this.preview,
    this.monthClosed = false,
    this.slipRequired = false,
  });

  final String customer;
  final String account;

  /// পয়সায়
  final int received;
  final int withheld;
  final int discount;
  final int rounding;

  /// null = পুরনো সার্ভার (বিল সার্ভার নিজে বসাবে)
  final int? netApplied;
  final int? unapplied;
  final List<(String, int)>? bills;
  final String? words;

  /// null = প্রিভিউয়ের দরজা নেই — "খাতায় যা যাবে" দেখায় না
  final CollectionPreview? preview;
  final bool monthClosed;
  final bool slipRequired;
}

/// ⭐ পর্দা ২ — "পোস্টের আগে দেখুন" (চূড়ান্ত নকশা, বিষয় ১০ ও ১৭): হিসাবের সারাংশ, খাতায় যা যাবে, নিয়ন্ত্রণ; "ফিরে যান" আর
/// "পোস্ট"; বসার পরে "পোস্টের পরে" কার্ড আর রসিদের চার চিপ।
class CollectReviewScreen extends StatefulWidget {
  const CollectReviewScreen({super.key, required this.data, required this.post, required this.openReceipt});

  final CollectReview data;
  final Future<CollectPostResult> Function() post;
  final ReceiptOpener openReceipt;

  @override
  State<CollectReviewScreen> createState() => _CollectReviewScreenState();
}

class _CollectReviewScreenState extends State<CollectReviewScreen> {
  bool _busy = false;
  String? _error;
  CollectionSaved? _saved;

  Future<void> _post() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final result = await widget.post();
    if (!mounted) return;
    setState(() => _busy = false);
    if (result.oldLanded case final id?) {
      Navigator.of(context).pop(id);
      return;
    }
    if (result.saved case final saved?) {
      setState(() => _saved = saved);
      // ⭐ বিষয় ১৭ — বসার পরেই রসিদ (টাকা কথায়)
      if (saved.receipt != null) await widget.openReceipt(context, saved, ReceiptAction.pdf);
      return;
    }
    setState(() => _error = result.error);
  }

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    final d = widget.data;
    final p = d.preview;
    final saved = _saved;
    final stops = p?.stops ?? const <String>[];
    // ⭐ মালিকের সই লাগবে — ছাড় যেকোনো অঙ্কে, রাউন্ডিং ৳0.50-এর বেশি হলে (মালিক, ২ অক্টোবর ২০২৬), বা সার্ভারের প্রিভিউ বললে
    final sign = d.discount > 0 || CollectScreen.roundingNeedsSign(d.rounding) || p?.needsSignature == true;
    return Scaffold(
      appBar: AbosScreenBar(title: w.collectCheckTitle),
      bottomNavigationBar: _BottomBar(children: [
        if (saved == null) ...[
          Expanded(
            child: OutlinedButton(
              key: const ValueKey('collect-check-back'),
              style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48), textStyle: _buttonText(context)),
              onPressed: _busy ? null : () => Navigator.of(context).pop(),
              child: Text(w.collectCheckBack, textAlign: TextAlign.center),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: FilledButton(
              key: const ValueKey('collect-check-post'),
              style: FilledButton.styleFrom(minimumSize: const Size(48, 48), textStyle: _buttonText(context)),
              onPressed: _busy || stops.isNotEmpty ? null : _post,
              // ⓘ সই লাগলে বোতামের কথাই বলে কী হবে — "সইয়ে পাঠান"
              child: Text(sign ? w.collectSendForSign : w.collectCheckPost, textAlign: TextAlign.center),
            ),
          ),
        ] else
          Expanded(
            child: OutlinedButton(
              key: const ValueKey('collect-check-close'),
              style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48), textStyle: _buttonText(context)),
              onPressed: () => Navigator.of(context).pop(),
              child: Text(w.collectClose, textAlign: TextAlign.center),
            ),
          ),
      ]),
      body: SingleChildScrollView(
        key: const ValueKey('collect-review-list'),
        padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.xl),
        child: Column(
          key: const ValueKey('collect-summary'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) _ErrorCard(_error!),
            // ── হিসাবের সারাংশ ──
            _Section(key: const ValueKey('collect-summary-card'), title: w.collectSummaryTitle, children: [
              Text('${d.customer} · ${d.account}',
                  key: const ValueKey('collect-review-who'),
                  style: TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5)),
              const SizedBox(height: AppSpacing.xs),
              _AmountRow(key: const ValueKey('collect-sum-received'), label: w.collectSumReceived, paisa: d.received),
              if (d.netApplied != null) ...[
                _AmountRow(key: const ValueKey('collect-sum-withheld'), label: w.collectSumWithheld, paisa: d.withheld),
                _AmountRow(key: const ValueKey('collect-sum-discount'), label: w.collectSumDiscount, paisa: d.discount),
                _AmountRow(key: const ValueKey('collect-sum-rounding'), label: w.collectSumRounding, paisa: d.rounding),
                Divider(thickness: 2, color: Theme.of(context).colorScheme.onSurface),
                _AmountRow(key: const ValueKey('collect-net-applied'), label: w.collectSumApplied, paisa: d.netApplied!, strong: true),
                if (d.bills case final bills? when bills.isNotEmpty)
                  Text(
                    [for (final (no, paisa) in bills) '$no ${Money.amount(paisa / 100)}'].join(' · '),
                    key: const ValueKey('collect-summary-bills'),
                    style: TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5),
                  ),
                _AmountRow(key: const ValueKey('collect-unapplied'), label: w.collectSumUnapplied, paisa: d.unapplied!),
              ] else
                // ⛔ পুরনো সার্ভার — বিল সার্ভার নিজে বসাবে
                Text(w.collectBillsAuto, key: const ValueKey('collect-summary-bills')),
              _Banner(
                key: const ValueKey('collect-balanced'),
                text: d.words == null ? w.collectSumBalancedPlain : w.collectSumBalanced(d.words!),
                detail: d.netApplied == null ? null : w.collectSumFormula,
              ),
            ]),
            // ── খাতায় যা যাবে ──
            if (p != null && p.lines.isNotEmpty)
              _Section(key: const ValueKey('collect-journal'), title: w.collectJournalTitle, children: [
                JournalLines(
                  lines: p.lines,
                  totalDr: p.totalDr,
                  totalCr: p.totalCr,
                  serverBalanced: p.balanced,
                  // ⓘ গ্রাহকের পাওনা থেকে যা কমে = পাওয়া + কাটা + ছাড়/রাউন্ডিং = বিলে প্রয়োগ + অপ্রয়োগকৃত (সার্ভারের Cr)
                  expected: d.netApplied == null ? null : d.netApplied! + d.unapplied!,
                ),
              ]),
            // ── নিয়ন্ত্রণ ──
            _Section(key: const ValueKey('collect-controls'), title: w.collectControlsTitle, children: [
              for (final (i, stop) in stops.indexed) _Note(key: ValueKey('collect-stop-$i'), text: stop, tone: _Tone.stop),
              if (stops.isNotEmpty) _Note(text: w.collectControlStops, tone: _Tone.stop),
              if (d.monthClosed) _Note(key: const ValueKey('collect-month-closed'), text: w.collectMonthClosed, tone: _Tone.stop),
              if (sign) _Note(key: const ValueKey('collect-needs-signature'), text: w.collectOwnerSign, tone: _Tone.info),
              if (!sign && p?.needsSignature == false)
                _Note(key: const ValueKey('collect-no-signature'), text: w.collectControlNoSignature, tone: _Tone.info),
              if (d.slipRequired) _Note(text: w.collectControlSlip, tone: _Tone.info),
              _Note(text: w.collectControlNoUndo, tone: _Tone.info),
              _Note(text: w.collectControlNet, tone: _Tone.warn),
            ]),
            // ── পোস্টের পরে ──
            if (saved != null) _after(context, saved),
          ],
        ),
      ),
    );
  }

  Widget _after(BuildContext context, CollectionSaved saved) {
    final w = context.l10n;
    final words = saved.amountWords ?? widget.data.words;
    return _Section(key: const ValueKey('collect-after'), title: w.collectAfterTitle, children: [
      Container(
        padding: const EdgeInsets.all(AppSpacing.sm),
        decoration: BoxDecoration(
          color: context.palette.surfaceMuted,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: context.palette.border),
        ),
        child: Wrap(spacing: AppSpacing.sm, runSpacing: AppSpacing.xs, crossAxisAlignment: WrapCrossAlignment.center, children: [
          Text(saved.no!, key: const ValueKey('collect-after-no'), style: const TextStyle(fontWeight: FontWeight.w600)),
          AppStatusChip.forServer(saved.state!, key: const ValueKey('collect-review-state'), fallbackLabel: saved.state!),
          ExcludeSemantics(child: Icon(Symbols.check_circle, size: 18, color: context.palette.success)),
        ]),
      ),
      if (words != null)
        Padding(
          padding: const EdgeInsets.only(top: AppSpacing.xs),
          child: Text(w.collectDoneWords(words), key: const ValueKey('collect-after-words')),
        ),
      const SizedBox(height: AppSpacing.sm),
      if (saved.receipt != null)
        Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
          for (final (action, label) in [
            (ReceiptAction.pdf, w.collectReceiptPdf),
            (ReceiptAction.whatsapp, 'WhatsApp'),
            (ReceiptAction.sms, 'SMS'),
            (ReceiptAction.print, w.collectReceiptPrint),
          ])
            _TonalChip(
              key: ValueKey('collect-receipt-${action.name}'),
              label: label,
              onPressed: () => widget.openReceipt(context, saved, action),
            ),
        ]),
    ]);
  }
}

/// ⭐ বাছা চিপের রং — বাছা আর না-বাছা স্পষ্ট আলাদা (fe, ২২ অক্টোবর ২০২৬; WCAG 2.2: সীমানা ৩:১, লেখা ৪.৫:১): বাছা চিপ থিমের
/// primary-তে ভরা, লেখা onPrimary, চারপাশে ২dp primary; না-বাছা চিপ কার্ডের রঙের উপর। অ্যাপের নীলচে-সবুজ (teal) থাকে।
class CollectChipColors {
  const CollectChipColors({required this.selectedFill, required this.selectedBorder, required this.selectedLabel, required this.unselectedSurface});

  final Color selectedFill;
  final Color selectedBorder;
  final Color selectedLabel;

  /// না-বাছা চিপ যার উপর বসে — কার্ডের রং
  final Color unselectedSurface;

  static CollectChipColors of(ThemeData theme) => CollectChipColors(
        selectedFill: theme.colorScheme.primary,
        selectedBorder: theme.colorScheme.primary,
        selectedLabel: theme.colorScheme.onPrimary,
        unselectedSurface: theme.cardTheme.color ?? theme.colorScheme.surfaceContainerLow,
      );
}

/// বেছে নেওয়ার চিপ — পক্ষের ধরন, পদ্ধতি, ছাড়/রাউন্ডিং; বাছা অবস্থা [CollectChipColors]-এ
class _Segment extends StatelessWidget {
  const _Segment({super.key, required this.label, required this.selected, required this.onSelected});

  final Widget label;
  final bool selected;
  final ValueChanged<bool>? onSelected;

  @override
  Widget build(BuildContext context) {
    final c = CollectChipColors.of(Theme.of(context));
    return ChoiceChip(
      label: label,
      selected: selected,
      onSelected: onSelected,
      selectedColor: c.selectedFill,
      checkmarkColor: c.selectedLabel,
      labelStyle: selected ? TextStyle(color: c.selectedLabel, fontWeight: FontWeight.w600) : null,
      side: selected ? BorderSide(color: c.selectedBorder, width: 2) : null,
    );
  }
}

/// নকশার হালকা-ভরা চিপ — "পুরো বকেয়া", "নির্বাচিত বিল", রসিদের চার পথ
class _TonalChip extends StatelessWidget {
  const _TonalChip({super.key, required this.label, required this.onPressed});

  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) => ActionChip(
        label: Text(label),
        onPressed: onPressed,
        side: BorderSide.none,
        backgroundColor: context.palette.primarySoft,
        labelStyle: TextStyle(color: Theme.of(context).colorScheme.primary),
      );
}

class _BottomBar extends StatelessWidget {
  const _BottomBar({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Material(
        elevation: 3,
        color: Theme.of(context).colorScheme.surface,
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.sm),
            child: Row(children: children),
          ),
        ),
      );
}

class _Label extends StatelessWidget {
  const _Label(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: Text(text, style: TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5)),
      );
}

/// ⭐ তিন অঙ্ক — বকেয়া (লাল) · এই আদায় (সবুজ) · বাকি থাকবে, এক সারিতে (ঋণাত্মক হলে "গ্রাহকের খাতায় জমা থাকবে")
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
      _Figure(key: const ValueKey('collect-figure-this'), label: w.collectFigureThis, value: received, colour: context.palette.success, align: 1),
      _Figure(
        key: const ValueKey('collect-figure-left'),
        label: left != null && left < 0 ? w.collectFigureCredit : w.collectFigureLeft,
        value: left?.abs(),
        colour: Theme.of(context).colorScheme.onSurface,
        align: 2,
      ),
    ];
    return LayoutBuilder(builder: (context, c) {
      final scale = MediaQuery.textScalerOf(context).scale(1);
      final wide = c.maxWidth / 3 >= 104 * scale;
      return Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm, vertical: AppSpacing.xs),
          child: wide
              ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [for (final f in figures) Expanded(child: f)])
              : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: figures),
        ),
      );
    });
  }
}

class _Figure extends StatelessWidget {
  const _Figure({super.key, required this.label, required this.value, required this.colour, this.align = 0});

  final String label;
  final int? value;
  final Color colour;

  /// 0 বাঁয়ে, 1 মাঝে, 2 ডানে — নকশার এক সারি
  final int align;

  @override
  Widget build(BuildContext context) {
    final text = value == null ? '—' : Money.taka(value! / 100);
    final cross = switch (align) { 1 => CrossAxisAlignment.center, 2 => CrossAxisAlignment.end, _ => CrossAxisAlignment.start };
    return Semantics(
      container: true,
      label: '$label, ${value == null ? '—' : Money.speakable(text)}',
      excludeSemantics: true,
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xs),
        child: Column(crossAxisAlignment: cross, children: [
          Text(label, style: TextStyle(fontSize: 12.5, color: context.palette.onSurfaceMuted)),
          Text(text, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: colour)),
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

enum _Tone { info, warn, stop }

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
    };
    return Container(
      margin: const EdgeInsets.only(top: AppSpacing.xs),
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
          color: bg, borderRadius: BorderRadius.circular(6), border: BorderDirectional(start: BorderSide(color: fg, width: 3))),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        ExcludeSemantics(child: Icon(icon, size: 18, color: fg)),
        const SizedBox(width: AppSpacing.sm),
        Expanded(child: Text(text, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: p.onSurface))),
      ]),
    );
  }
}

/// ⭐ সবুজ পট্টি — "মিলেছে · কথায় টাকা", নিচে কোন হিসাব মিলল; টিক আইকনে, লেখার '✓' নয়
class _Banner extends StatelessWidget {
  const _Banner({super.key, required this.text, this.detail});

  final String text;
  final String? detail;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    return Container(
      margin: const EdgeInsets.only(top: AppSpacing.sm),
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
          color: p.successSurface,
          borderRadius: BorderRadius.circular(6),
          border: BorderDirectional(start: BorderSide(color: p.success, width: 3))),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        ExcludeSemantics(child: Icon(Symbols.check_circle, size: 18, color: p.success)),
        const SizedBox(width: AppSpacing.sm),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(text, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: p.onSurface)),
            if (detail != null) Text(detail!, style: Theme.of(context).textTheme.labelMedium?.copyWith(color: p.onSurfaceMuted)),
          ]),
        ),
      ]),
    );
  }
}

/// ড্যাশ দেওয়া ঘর — স্লিপের ছবি
class _DashedBox extends StatelessWidget {
  const _DashedBox({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => CustomPaint(
        painter: _DashPainter(context.palette.border),
        child: Padding(padding: const EdgeInsets.all(AppSpacing.xs), child: Center(child: child)),
      );
}

class _DashPainter extends CustomPainter {
  _DashPainter(this.colour);

  final Color colour;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = colour
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.2;
    final path = Path()..addRRect(RRect.fromRectAndRadius(Offset.zero & size, const Radius.circular(8)));
    for (final metric in path.computeMetrics()) {
      var d = 0.0;
      while (d < metric.length) {
        canvas.drawPath(metric.extractPath(d, d + 6), paint);
        d += 10;
      }
    }
  }

  @override
  bool shouldRepaint(_DashPainter old) => old.colour != colour;
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

/// খোলা টেবিলের এক ছোট সারি — টিক, নম্বর, দেরি (লাল), ডানে বসানো টাকা; চাপলে নিচে খোলে: তারিখ, শেষ দিন, মূল টাকা, বাকি আর
/// "এখন প্রয়োগ"-এর ঘর (নকশার পরিষ্কার তালিকা — ঘর কেবল চাইলে)
class _ItemRow extends StatelessWidget {
  const _ItemRow({
    super.key,
    required this.row,
    required this.editing,
    required this.controller,
    required this.error,
    required this.enabled,
    required this.onTick,
    required this.onTap,
    required this.onChanged,
  });

  final PlanRow row;
  final bool editing;
  final TextEditingController controller;
  final String? error;
  final bool enabled;
  final ValueChanged<bool> onTick;
  final VoidCallback onTap;
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
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      InkWell(
        onTap: enabled ? onTap : null,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 48),
          child: Row(children: [
            Checkbox(
              key: ValueKey('collect-tick-${item.no}'),
              value: row.selected,
              semanticLabel: w.collectItemTick(item.no),
              onChanged: enabled ? (v) => onTick(v ?? false) : null,
            ),
            Expanded(
              flex: 3,
              child: Wrap(spacing: AppSpacing.xs, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Text(item.no),
                if (tag != null) Text(tag, style: muted),
                if ((item.overdueDays ?? 0) > 0)
                  Text(w.collectItemLate(item.overdueDays!),
                      key: ValueKey('collect-late-${item.no}'), style: TextStyle(color: context.palette.danger, fontSize: 13)),
              ]),
            ),
            Flexible(
              flex: 2,
              fit: FlexFit.tight,
              child: Text(Money.amount(sign * row.applied / 100),
                  key: ValueKey('collect-row-amount-${item.no}'), textAlign: TextAlign.end),
            ),
            ExcludeSemantics(
              child: Icon(editing ? Symbols.expand_less : Symbols.expand_more, size: 18, color: context.palette.onSurfaceMuted),
            ),
          ]),
        ),
      ),
      if (editing)
        Padding(
          padding: const EdgeInsetsDirectional.only(start: 48, bottom: AppSpacing.sm),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(
              [
                if (item.date != null) _isoDay(item.date!),
                if (item.dueDate != null) w.collectItemDueOn(_isoDay(item.dueDate!)),
                w.collectItemOriginal(Money.taka(sign * item.amount / 100)),
                w.collectItemOpen(Money.taka(sign * item.open / 100)),
              ].join(' · '),
              style: muted,
            ),
            const SizedBox(height: AppSpacing.sm),
            TextField(
              key: ValueKey('collect-apply-${item.no}'),
              controller: controller,
              enabled: enabled,
              textAlign: TextAlign.end,
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
          ]),
        ),
    ]);
  }
}

String _isoDay(String iso) {
  final d = DateTime.tryParse(iso);
  return d == null ? iso : _dayText(d);
}

/// ⭐ খাতায় যা যাবে — "Dr 1102 ডাচ্-বাংলা ব্যাংক · ময়মনসিংহ  45,000.00"; নিচে মোট মেলে কি না (বিষয় ১০)
class JournalLines extends StatelessWidget {
  const JournalLines(
      {super.key, required this.lines, required this.totalDr, required this.totalCr, this.serverBalanced, this.expected});

  final List<JournalLine> lines;
  final int totalDr;
  final int totalCr;

  /// সার্ভারের `summary.balanced`; না পাঠালে null
  final bool? serverBalanced;

  /// পর্দার হিসাব — বিলে প্রয়োগ + অপ্রয়োগকৃত, পয়সায়; পুরনো সার্ভারে null
  final int? expected;

  /// ⭐ "মোট মিলেছে" কেবল যখন সত্যিই মেলে: Dr = Cr, লাইনগুলোর যোগ মোটের সমান, সার্ভার "মেলেনি" বলেনি, আর Cr = পর্দার
  /// বিলে প্রয়োগ + অপ্রয়োগকৃত (fe, ২২ অক্টোবর ২০২৬) — পয়সায়, পূর্ণসংখ্যায়
  static bool isBalanced(List<JournalLine> lines, int totalDr, int totalCr, {bool? serverBalanced, int? expected}) {
    final dr = lines.fold(0, (s, l) => s + l.dr);
    final cr = lines.fold(0, (s, l) => s + l.cr);
    return totalDr == totalCr && dr == totalDr && cr == totalCr && serverBalanced != false && (expected == null || totalCr == expected);
  }

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    final balanced = isBalanced(lines, totalDr, totalCr, serverBalanced: serverBalanced, expected: expected);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (final (i, l) in lines.indexed) ...[
        if (i > 0) const Divider(height: 1),
        Padding(
          key: ValueKey('collect-journal-$i'),
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
          child: Wrap(alignment: WrapAlignment.spaceBetween, spacing: AppSpacing.sm, children: [
            // ⓘ Dr/Cr — হিসাবের আন্তর্জাতিক চিহ্ন, ওয়েবের মতো, সব ভাষায় একই
            Text('${l.dr > 0 ? 'Dr' : 'Cr'} ${l.account}${l.branch.isEmpty ? '' : ' · ${l.branch}'}'),
            Text(Money.amount((l.dr > 0 ? l.dr : l.cr) / 100)),
          ]),
        ),
      ],
      const Divider(height: AppSpacing.md),
      Wrap(alignment: WrapAlignment.spaceBetween, spacing: AppSpacing.sm, children: [
        Text(w.collectJournalTotal, style: const TextStyle(fontWeight: FontWeight.w700)),
        Text('Dr ${Money.amount(totalDr / 100)} · Cr ${Money.amount(totalCr / 100)}', style: const TextStyle(fontWeight: FontWeight.w700)),
      ]),
      Padding(
        padding: const EdgeInsets.only(top: AppSpacing.xs),
        child: Row(key: const ValueKey('collect-journal-balanced'), children: [
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
                decoration: InputDecoration(labelText: context.l10n.booksCollectSearchLabel, prefixIcon: const Icon(Symbols.search)),
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
