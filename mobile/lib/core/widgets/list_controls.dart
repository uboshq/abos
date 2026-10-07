import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';

/// ফিল্টার আর সাজানো — প্রতিটা তালিকার খোঁজ-বাক্সের নিচে একই দুটো বোতাম।
///
/// <p>⭐ মালিক, ২ অক্টোবর ২০২৬: "App e filter nei kothaw, Filter r Sort by
/// bosaw"। একটাই widget, প্রতিটা স্ক্রিন নিজের বিকল্পগুলো পাঠায় — কোন বিকল্প,
/// কোন ক্রমে, সেটা ঠিক করে `list_queries.dart`-এর খাঁটি function, এই widget
/// শুধু দেখায় আর বাছাইটা ফেরত দেয়।
///
/// <p>ⓘ মালিকের নিয়ম: এক লাইনে এক জিনিস, টেবিল নয় — তাই দুটো শিটেই প্রতিটা
/// বিকল্প নিজের লাইনে, পাশাপাশি chip নয়; তাঁর ফোনে ডান দিক কেটে যায়।
///
/// <p>বাছাই মনে থাকে শুধু স্ক্রিন খোলা থাকা পর্যন্ত — স্ক্রিনের State-এ, কোথাও
/// জমা হয় না।
class ListControls extends StatelessWidget {
  const ListControls({
    super.key,
    required this.sortOptions,
    required this.sort,
    required this.onSort,
    this.filterGroups = const [],
    this.filters = const {},
    this.onFilters,
    this.padding = const EdgeInsets.fromLTRB(
        AppSpacing.md, 0, AppSpacing.md, AppSpacing.sm),
  });

  final List<ListSortOption> sortOptions;

  /// এখন যে [ListSortOption.key] বাছা আছে।
  final String sort;
  final ValueChanged<String> onSort;

  /// খালি হলে ফিল্টার বোতামই দেখায় না — কিছু বাছার নেই এমন শিট খোলা অর্থহীন।
  final List<ListFilterGroup> filterGroups;

  /// গ্রুপের key → বাছা choice-এর key। key না থাকা মানে "সব"।
  final ListFilters filters;
  final ValueChanged<ListFilters>? onFilters;

  /// খোঁজ-বাক্সের নিচে স্ক্রিনের কিনারা মেনে; নিজেই padding দেওয়া ListView-এর
  /// ভেতরে বসলে [EdgeInsets.zero]-র মতো কিছু।
  final EdgeInsetsGeometry padding;

  String get _sortLabel => sortOptions
      .firstWhere((option) => option.key == sort,
          orElse: () => sortOptions.first)
      .label;

  /// ব্যাজে কটা ফিল্টার চালু — এমন গ্রুপের বাছাই গোনা হয় না যেটা এখন আর
  /// দেখানো হচ্ছে না (যেমন তালিকা বদলে একটা ধরন হারিয়ে গেছে)।
  int get activeCount => filters.keys
      .where((key) => filterGroups.any((group) => group.key == key))
      .length;

  @override
  Widget build(BuildContext context) {
    final showFilter = filterGroups.isNotEmpty && onFilters != null;
    return Padding(
      padding: padding,
      child: Row(
        children: [
          if (showFilter) ...[
            Expanded(
              child: OutlinedButton(
                key: const Key('list-controls-filter'),
                onPressed: () => _openFilters(context),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Badge(
                      isLabelVisible: activeCount > 0,
                      label: Text('$activeCount'),
                      child: const Icon(Icons.filter_list, size: 20),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    const Flexible(
                      child: Text('ফিল্টার',
                          maxLines: 1, overflow: TextOverflow.ellipsis),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
          ],
          Expanded(
            child: OutlinedButton(
              key: const Key('list-controls-sort'),
              onPressed: () => _openSort(context),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.sort, size: 20),
                  const SizedBox(width: AppSpacing.sm),
                  Flexible(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Text('সাজান', maxLines: 1),
                        Text(
                          _sortLabel,
                          key: const Key('list-controls-sort-label'),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              fontSize: 11, color: AppColors.onSurfaceMuted),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _openSort(BuildContext context) async {
    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (sheet) => SafeArea(
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const _SheetTitle('সাজান'),
              for (final option in sortOptions)
                _ChoiceLine(
                  key: Key('sort-option-${option.key}'),
                  label: option.label,
                  selected: option.key == sort,
                  onTap: () => Navigator.of(sheet).pop(option.key),
                ),
              const SizedBox(height: AppSpacing.sm),
            ],
          ),
        ),
      ),
    );
    if (picked != null && picked != sort) onSort(picked);
  }

  Future<void> _openFilters(BuildContext context) async {
    final draft = Map<String, String>.of(filters);
    final applied = await showModalBottomSheet<ListFilters>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (sheet) => StatefulBuilder(
        builder: (sheet, setSheet) => SafeArea(
          child: ConstrainedBox(
            constraints: BoxConstraints(
                maxHeight: MediaQuery.sizeOf(sheet).height * 0.85),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const _SheetTitle('ফিল্টার'),
                Flexible(
                  child: SingleChildScrollView(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (final group in filterGroups) ...[
                          Padding(
                            padding: const EdgeInsets.fromLTRB(
                                AppSpacing.md, AppSpacing.sm, AppSpacing.md, 0),
                            child: Text(group.title,
                                style: const TextStyle(
                                    fontWeight: FontWeight.w700, fontSize: 13)),
                          ),
                          _ChoiceLine(
                            key: Key('filter-${group.key}-all'),
                            label: 'সব',
                            selected: !draft.containsKey(group.key),
                            onTap: () =>
                                setSheet(() => draft.remove(group.key)),
                          ),
                          for (final choice in group.choices)
                            _ChoiceLine(
                              key: Key('filter-${group.key}-${choice.key}'),
                              label: choice.label,
                              selected: draft[group.key] == choice.key,
                              onTap: () =>
                                  setSheet(() => draft[group.key] = choice.key),
                            ),
                        ],
                      ],
                    ),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          key: const Key('filter-clear'),
                          // মুছে সঙ্গে সঙ্গে প্রয়োগ — আরেকবার চাপতে হয় না।
                          onPressed: () =>
                              Navigator.of(sheet).pop(const <String, String>{}),
                          child: const Text('সব মুছুন'),
                        ),
                      ),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                        child: FilledButton(
                          key: const Key('filter-apply'),
                          onPressed: () => Navigator.of(sheet)
                              .pop(Map<String, String>.unmodifiable(draft)),
                          child: const Text('প্রয়োগ করুন'),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
    if (applied != null) onFilters?.call(applied);
  }
}

/// গ্রুপের key → বাছা choice-এর key। key না থাকা মানে সেই গ্রুপে "সব"।
typedef ListFilters = Map<String, String>;

/// সাজানোর একটা উপায় — key দিয়ে খাঁটি function চেনে, label দেখায় মানুষ।
class ListSortOption {
  const ListSortOption(this.key, this.label);

  final String key;
  final String label;
}

/// ফিল্টারের একটা অংশ ("অবস্থা", "মোবাইল নম্বর") — এর মধ্যে একটাই বাছা যায়।
class ListFilterGroup {
  const ListFilterGroup(this.key, this.title, this.choices);

  final String key;
  final String title;
  final List<ListFilterChoice> choices;
}

class ListFilterChoice {
  const ListFilterChoice(this.key, this.label);

  final String key;
  final String label;
}

class _SheetTitle extends StatelessWidget {
  const _SheetTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
          AppSpacing.md, 0, AppSpacing.md, AppSpacing.sm),
      child: Text(text,
          style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
    );
  }
}

/// এক লাইনে একটা বিকল্প — বাঁ দিকে গোল দাগ, বাছা হলে ভরা।
class _ChoiceLine extends StatelessWidget {
  const _ChoiceLine({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      dense: true,
      selected: selected,
      leading: Icon(
        selected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
        color: selected ? AppColors.primary : AppColors.onSurfaceMuted,
      ),
      title: Text(label),
      onTap: onTap,
    );
  }
}
