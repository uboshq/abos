import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/orders/lead_api.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../home/nav_bottom_bar.dart' show ActionBottomBar, BarFab;
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/search_debouncer.dart';
import '../books/books_widgets.dart';
import '../../core/widgets/unsaved_guard.dart';

/// ⭐ লিড — মাঠ থেকে নতুন দোকানের খোঁজ: তালিকা, নতুন লিড, আর একটা লিডের পাতায় অবস্থা বদল
/// (সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)। এক লাইনে এক তথ্য (মালিকের নিয়ম); নিয়ম সব সার্ভারের।
class LeadListScreen extends StatefulWidget {
  const LeadListScreen({super.key, this.api = const ServerLeadApi()});

  final LeadApi api;

  @override
  State<LeadListScreen> createState() => _LeadListScreenState();
}

class _LeadListScreenState extends State<LeadListScreen> {
  final _search = TextEditingController();
  late final _typed = SearchDebouncer(onSearch: (_) => _load());
  LeadSetup? _setup;
  String? _status;
  final List<Lead> _rows = [];
  int? _next;
  int? _count;
  bool _busy = false;
  bool _loaded = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.api.setup().then((s) {
      if (mounted) setState(() => _setup = s);
    }).catchError((_) {});
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
      final (rows, next, count) = await widget.api.list(query: _search.text, status: _status, page: more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(rows);
        _next = next;
        _count = count;
        _loaded = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.leadsFailed,
            whenAbsent: context.l10n.leadsAbsent));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open({Lead? lead}) async {
    final setup = _setup ?? await widget.api.setup();
    if (!mounted) return;
    final saved = await Navigator.of(context).push<Lead>(MaterialPageRoute(
      builder: (_) => LeadFormScreen(api: widget.api, setup: setup, lead: lead),
    ));
    if (saved != null) _load();
  }

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: w.leadsTitle),
      bottomNavigationBar: ActionBottomBar(
          action: BarFab(
        key: const Key('lead-new'),
        onPressed: () => _open(),
        label: w.leadsNew,
      )),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            TextField(
              key: const Key('lead-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
              decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: w.leadsSearchHint),
            ),
            const SizedBox(height: AppSpacing.sm),
            Wrap(spacing: AppSpacing.xs, children: [
              ChoiceChip(
                label: Text(w.leadsAll),
                selected: _status == null,
                onSelected: (_) {
                  setState(() => _status = null);
                  _load();
                },
              ),
              for (final s in _setup?.statuses ?? const <LeadChoice>[])
                ChoiceChip(
                  key: Key('lead-status-${s.key}'),
                  label: Text(s.label),
                  selected: _status == s.key,
                  onSelected: (_) {
                    setState(() => _status = s.key);
                    _load();
                  },
                ),
            ]),
            const SizedBox(height: AppSpacing.sm),
            // ⭐ গোটা ছাঁকনির গোনা — সার্ভারের, সব পাতার, খোঁজ আর অবস্থা মেনে; ⛔ পুরনো সার্ভারে পট্টি নেই (পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬)
            if (_count != null && _rows.isNotEmpty) TotalStrip(label: w.leadsTotal, value: w.leadsCount(_count!)),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) EmptyState(icon: Icons.cloud_off_outlined, title: w.leadsLoadFailed, message: _error),
            if (_loaded && _rows.isEmpty && _error == null)
              EmptyState(icon: Icons.person_search_outlined, title: w.leadsNone),
            for (final lead in _rows)
              Card(
                child: ListTile(
                  key: Key('lead-${lead.id}'),
                  onTap: () => _open(lead: lead),
                  title: Text(lead.name),
                  subtitle: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(lead.no),
                      if (lead.phone != null) Text(lead.phone!),
                      Text(lead.statusLabel, style: const TextStyle(fontWeight: FontWeight.w600)),
                    ],
                  ),
                  trailing: const Icon(Icons.chevron_right),
                ),
              ),
            if (_next != null)
              OutlinedButton(
                key: const Key('lead-more'),
                onPressed: _busy ? null : () => _load(more: true),
                child: Text(w.leadsMore),
              ),
          ],
        ),
      ),
    );
  }
}

/// নতুন লিড, বা একটা লিডের পাতা — একই ঘর; পুরনো লিডে অবস্থা বদলানো যায়, গ্রাহক হয়ে গেলে কেবল দেখা।
class LeadFormScreen extends StatefulWidget {
  const LeadFormScreen({super.key, required this.api, required this.setup, this.lead});

  final LeadApi api;
  final LeadSetup setup;
  final Lead? lead;

  @override
  State<LeadFormScreen> createState() => _LeadFormScreenState();
}

class _LeadFormScreenState extends State<LeadFormScreen> {
  late final _name = TextEditingController(text: widget.lead?.name);
  late final _person = TextEditingController(text: widget.lead?.contactPerson);
  late final _phone = TextEditingController(text: widget.lead?.phone);
  late final _address = TextEditingController(text: widget.lead?.address);
  late final _reason = TextEditingController(text: widget.lead?.lostReason);
  /// ⭐ এই কাজের চাবি — দুবার চাপলে বা উত্তর হারালে একবারই বসে ([[OnceKey]], অডিট ফোন ⚠️১২)
  final _once = OnceKey();
  late final _notes = TextEditingController(text: widget.lead?.notes);
  late String? _source = widget.lead?.source ?? (widget.setup.sources.isEmpty ? null : widget.setup.sources.first.key);
  late String? _status = widget.lead?.status;
  bool _busy = false;
  String? _error;

  bool get _readOnly => widget.lead?.converted ?? false;

  List<TextEditingController> get _texts => [_name, _person, _phone, _address, _reason, _notes];

  /// খোলার সময়ের ঘরগুলো — নতুন লিডে ফাঁকা, পুরনোয় যা ছিল; এর থেকে বদলালে তবেই "লেখা আছে"
  late final String _opened;

  String _snapshot() => [for (final c in _texts) c.text.trim(), _source ?? '', _status ?? ''].join('\u0000');

  bool get _changed => !_readOnly && _snapshot() != _opened;

  @override
  void initState() {
    super.initState();
    _opened = _snapshot();
    for (final c in _texts) {
      c.addListener(_typed);
    }
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    for (final c in [_name, _person, _phone, _address, _reason, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty || _source == null) {
      setState(() => _error = _name.text.trim().isEmpty ? context.l10n.leadsNameNeeded : context.l10n.leadsSourceNeeded);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final form = LeadForm(
      name: _name.text,
      contactPerson: _person.text,
      phone: _phone.text,
      address: _address.text,
      source: _source!,
      status: widget.lead == null ? null : _status,
      lostReason: _reason.text,
      notes: _notes.text,
    );
    try {
      final saved = widget.lead == null ? await _once.send(() => widget.api.create(form)) : await widget.api.update(widget.lead!.id, form);
      if (mounted) Navigator.of(context).pop(saved);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.leadsSaveFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⭐ নতুন বা পুরনো লিডে কিছু বদলালে "পিছনে" জিজ্ঞেস করে — দোকানের খোঁজ এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _changed && !_busy, child: _screen(context));

  Widget _screen(BuildContext context) {
    final lead = widget.lead;
    final statuses = widget.setup.statuses;
    final w = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: lead?.no ?? w.leadsNew),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (lead != null) ...[
            Text(w.leadsStatusNow(lead.statusLabel), key: const Key('lead-status-now')),
            if (lead.owner != null) Text(w.leadsOwner(lead.owner!)),
            if (_readOnly) Text(w.leadsConverted),
            const SizedBox(height: AppSpacing.sm),
          ],
          TextField(key: const Key('lead-name'), controller: _name, enabled: !_readOnly,
              decoration: InputDecoration(labelText: w.leadsShopName)),
          TextField(controller: _person, enabled: !_readOnly, decoration: InputDecoration(labelText: w.leadsContactPerson)),
          TextField(key: const Key('lead-phone'), controller: _phone, enabled: !_readOnly,
              keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: w.leadsPhone)),
          TextField(controller: _address, enabled: !_readOnly, decoration: InputDecoration(labelText: w.leadsAddress)),
          DropdownButtonFormField<String>(
            key: const Key('lead-source'),
            initialValue: _source,
            decoration: InputDecoration(labelText: w.leadsSource),
            items: [for (final s in widget.setup.sources) DropdownMenuItem(value: s.key, child: Text(s.label))],
            onChanged: _readOnly ? null : (v) => setState(() => _source = v),
          ),
          if (lead != null && !_readOnly && statuses.any((s) => s.key == _status))
            DropdownButtonFormField<String>(
              key: const Key('lead-status'),
              initialValue: _status,
              decoration: InputDecoration(labelText: w.leadsStatus),
              items: [for (final s in statuses) DropdownMenuItem(value: s.key, child: Text(s.label))],
              onChanged: (v) => setState(() => _status = v),
            ),
          if (_status == 'lost')
            TextField(key: const Key('lead-lost-reason'), controller: _reason, enabled: !_readOnly,
                decoration: InputDecoration(labelText: w.leadsLostReason)),
          TextField(controller: _notes, enabled: !_readOnly, maxLines: 3, decoration: InputDecoration(labelText: w.leadsNotes)),
          const SizedBox(height: AppSpacing.md),
          if (_error != null)
            Card(
              color: context.palette.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_error!, style: TextStyle(color: context.palette.danger)),
              ),
            ),
          if (_busy) const LinearProgressIndicator(),
          if (!_readOnly)
            FilledButton(key: const Key('lead-save'), onPressed: _busy ? null : _save, child: Text(w.commonSave)),
        ],
      ),
    );
  }
}
