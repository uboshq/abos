import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/orders/lead_api.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

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
  LeadSetup? _setup;
  String? _status;
  final List<Lead> _rows = [];
  int? _next;
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
    super.dispose();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (rows, next) = await widget.api.list(query: _search.text, status: _status, page: more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(rows);
        _next = next;
        _loaded = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'লিড আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে লিডের দরজা এখনো আসেনি — অফিসে জানান।'));
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
    return Scaffold(
      appBar: AppBar(title: const Text('লিড')),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('lead-new'),
        onPressed: () => _open(),
        icon: const Icon(Icons.add),
        label: const Text('নতুন লিড'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            TextField(
              key: const Key('lead-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _load(),
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'নাম, ফোন বা নম্বর'),
            ),
            const SizedBox(height: AppSpacing.sm),
            Wrap(spacing: AppSpacing.xs, children: [
              ChoiceChip(
                label: const Text('সব'),
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
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) EmptyState(icon: Icons.cloud_off_outlined, title: 'আনা গেল না', message: _error),
            if (_loaded && _rows.isEmpty && _error == null)
              const EmptyState(icon: Icons.person_search_outlined, title: 'কোনো লিড নেই'),
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
                child: const Text('আরও লিড'),
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
  late final _notes = TextEditingController(text: widget.lead?.notes);
  late String? _source = widget.lead?.source ?? (widget.setup.sources.isEmpty ? null : widget.setup.sources.first.key);
  late String? _status = widget.lead?.status;
  bool _busy = false;
  String? _error;

  bool get _readOnly => widget.lead?.converted ?? false;

  @override
  void dispose() {
    for (final c in [_name, _person, _phone, _address, _reason, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty || _source == null) {
      setState(() => _error = _name.text.trim().isEmpty ? 'দোকানের নাম লিখুন।' : 'কীভাবে খোঁজ পেলেন, বাছুন।');
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
      final saved = widget.lead == null ? await widget.api.create(form) : await widget.api.update(widget.lead!.id, form);
      if (mounted) Navigator.of(context).pop(saved);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'লিড রাখা গেল না। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final lead = widget.lead;
    final statuses = widget.setup.statuses;
    return Scaffold(
      appBar: AppBar(title: Text(lead?.no ?? 'নতুন লিড')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (lead != null) ...[
            Text('অবস্থা: ${lead.statusLabel}', key: const Key('lead-status-now')),
            if (lead.owner != null) Text('মালিক: ${lead.owner}'),
            if (_readOnly) const Text('গ্রাহক হয়ে গেছেন — আর বদলানো যায় না।'),
            const SizedBox(height: AppSpacing.sm),
          ],
          TextField(key: const Key('lead-name'), controller: _name, enabled: !_readOnly,
              decoration: const InputDecoration(labelText: 'দোকানের নাম')),
          TextField(controller: _person, enabled: !_readOnly, decoration: const InputDecoration(labelText: 'যাঁর সাথে কথা')),
          TextField(key: const Key('lead-phone'), controller: _phone, enabled: !_readOnly,
              keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'ফোন')),
          TextField(controller: _address, enabled: !_readOnly, decoration: const InputDecoration(labelText: 'ঠিকানা')),
          DropdownButtonFormField<String>(
            key: const Key('lead-source'),
            initialValue: _source,
            decoration: const InputDecoration(labelText: 'কীভাবে খোঁজ পেলেন'),
            items: [for (final s in widget.setup.sources) DropdownMenuItem(value: s.key, child: Text(s.label))],
            onChanged: _readOnly ? null : (v) => setState(() => _source = v),
          ),
          if (lead != null && !_readOnly && statuses.any((s) => s.key == _status))
            DropdownButtonFormField<String>(
              key: const Key('lead-status'),
              initialValue: _status,
              decoration: const InputDecoration(labelText: 'অবস্থা'),
              items: [for (final s in statuses) DropdownMenuItem(value: s.key, child: Text(s.label))],
              onChanged: (v) => setState(() => _status = v),
            ),
          if (_status == 'lost')
            TextField(key: const Key('lead-lost-reason'), controller: _reason, enabled: !_readOnly,
                decoration: const InputDecoration(labelText: 'কেন হারালাম')),
          TextField(controller: _notes, enabled: !_readOnly, maxLines: 3, decoration: const InputDecoration(labelText: 'টুকিটাকি')),
          const SizedBox(height: AppSpacing.md),
          if (_error != null)
            Card(
              color: AppColors.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_error!, style: const TextStyle(color: AppColors.danger)),
              ),
            ),
          if (_busy) const LinearProgressIndicator(),
          if (!_readOnly)
            FilledButton(key: const Key('lead-save'), onPressed: _busy ? null : _save, child: const Text('রাখুন')),
        ],
      ),
    );
  }
}
