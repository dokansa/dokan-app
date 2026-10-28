import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../services/local_store.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Search that understands what the customer means (pet, food type, age, brand, price…).
class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key, this.initial = ''});
  final String initial;

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  late final _ctrl = TextEditingController(text: widget.initial);
  final _focus = FocusNode();
  Timer? _debounce;
  SearchArgs? _args;

  @override
  void initState() {
    super.initState();
    if (widget.initial.trim().length >= 2) _submit(widget.initial);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _ctrl.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _onChanged(String v) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      if (!mounted) return;
      final q = v.trim();
      setState(() => _args = q.length < 2 ? null : SearchArgs(q));
    });
  }

  void _submit(String v) {
    final q = v.trim();
    if (q.length < 2) return;
    _debounce?.cancel();
    if (_ctrl.text != v) _ctrl.text = v;
    _focus.unfocus();
    ref.read(recentSearchesProvider.notifier).add(q);
    setState(() => _args = SearchArgs(q, ai: true, log: true));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(icon: Icons.arrow_back_rounded, label: context.tr('رجوع', 'Back')),
        ),
        titleSpacing: 0,
        title: Padding(
          padding: const EdgeInsetsDirectional.only(end: 16),
          child: TextField(
            controller: _ctrl,
            focusNode: _focus,
            autofocus: widget.initial.isEmpty,
            textInputAction: TextInputAction.search,
            onChanged: _onChanged,
            onSubmitted: _submit,
            decoration: InputDecoration(
              hintText: context.tr('مثلاً: أكل رطب لقطة صغيرة', 'e.g. wet food for a kitten'),
              prefixIcon: const Icon(Icons.search_rounded),
              suffixIcon: _ctrl.text.isEmpty
                  ? null
                  : IconButton(
                      tooltip: context.tr('مسح', 'Clear'),
                      icon: const Icon(Icons.close_rounded),
                      onPressed: () {
                        _ctrl.clear();
                        setState(() => _args = null);
                        _focus.requestFocus();
                      },
                    ),
            ),
          ),
        ),
      ),
      body: _args == null ? _Idle(onPick: _submit) : _Results(args: _args!, onPick: _submit),
    );
  }
}

/// Before typing: recent searches, ideas for the pet, popular searches, assistant.
class _Idle extends ConsumerWidget {
  const _Idle({required this.onPick});
  final ValueChanged<String> onPick;

  List<String> _ideas(BuildContext context, Pet? pet) {
    final kind = pet?.kind ?? 'cat';
    final forKind = petKindPlural(kind, context.isAr);
    final young = pet?.isYoung ?? false;
    if (kind == 'cat') {
      return [
        context.tr(young ? 'أكل جاف لقطط صغيرة' : 'أكل جاف $forKind', young ? 'dry food for kittens' : 'dry cat food'),
        context.tr('أكل رطب $forKind', 'wet cat food'),
        context.tr('رمل قطط', 'cat litter'),
        context.tr('ألعاب $forKind', 'cat toys'),
        context.tr('مكافآت $forKind', 'cat treats'),
      ];
    }
    if (kind == 'dog') {
      return [
        context.tr(young ? 'أكل جراء' : 'أكل كلاب جاف', young ? 'puppy food' : 'dry dog food'),
        context.tr('مكافآت $forKind', 'dog treats'),
        context.tr('أطواق ورسن', 'collars and leashes'),
        context.tr('شامبو كلاب', 'dog shampoo'),
      ];
    }
    return [
      context.tr('أكل $forKind', 'food $forKind'),
      context.tr('أقفاص وإكسسوارات $forKind', 'cages and accessories $forKind'),
    ];
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final recent = ref.watch(recentSearchesProvider);
    final popular = ref.watch(configProvider).valueOrNull?.popularSearches ?? const <String>[];
    final pet = ref.watch(selectedPetProvider);
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
      children: [
        _AssistantCard(query: ''),
        if (recent.isNotEmpty) ...[
          const SizedBox(height: 20),
          Row(children: [
            Expanded(child: Text(context.tr('بحثت عنها قبل', 'Your recent searches'), style: display(16))),
            TextButton(
              onPressed: () => ref.read(recentSearchesProvider.notifier).clear(),
              child: Text(context.tr('مسح الكل', 'Clear all')),
            ),
          ]),
          for (final q in recent)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.history_rounded, color: C.muted),
              title: Text(q),
              trailing: IconButton(
                tooltip: context.tr('حذف', 'Remove'),
                icon: const Icon(Icons.close_rounded, size: 18),
                onPressed: () => ref.read(recentSearchesProvider.notifier).remove(q),
              ),
              onTap: () => onPick(q),
            ),
        ],
        const SizedBox(height: 20),
        Text(pet != null ? context.tr('أفكار لـ${pet.name}', 'Ideas for ${pet.name}') : context.tr('أفكار', 'Ideas'),
            style: display(16)),
        const SizedBox(height: 10),
        _ChipWrap(items: _ideas(context, pet), icon: Icons.lightbulb_outline_rounded, onTap: onPick),
        if (popular.isNotEmpty) ...[
          const SizedBox(height: 20),
          Text(context.tr('الأكثر بحثاً', 'Popular searches'), style: display(16)),
          const SizedBox(height: 10),
          _ChipWrap(items: popular, icon: Icons.trending_up_rounded, onTap: onPick),
        ],
      ],
    );
  }
}

class _Results extends ConsumerStatefulWidget {
  const _Results({required this.args, required this.onPick});
  final SearchArgs args;
  final ValueChanged<String> onPick;

  @override
  ConsumerState<_Results> createState() => _ResultsState();
}

class _ResultsState extends ConsumerState<_Results> {
  SearchResult? _last;

  SearchArgs get args => widget.args;
  ValueChanged<String> get onPick => widget.onPick;

  @override
  Widget build(BuildContext context) {
    final res = ref.watch(searchProvider(args));
    // Keep showing the last answer while the next one loads (smooth typing, and the
    // quick answer stays on screen while the smarter one is on its way).
    if (res.hasValue) _last = res.value;
    final r = res.valueOrNull ?? _last;
    if (r == null && res.isLoading) return const LoadingBox();
    if (r == null && res.hasError) return _FallbackResults(query: args.q, onPick: onPick);
    if (r == null) return const SizedBox.shrink();
    final products = ref.watch(productsByIdsProvider(idsKey(r.productIds.take(40))));

    return CustomScrollView(
      slivers: [
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (res.isLoading) const LinearProgressIndicator(minHeight: 2, color: C.primary, backgroundColor: C.line),
                if (r.understood.isNotEmpty) ...[
                  Row(children: [
                    Icon(r.ai ? Icons.auto_awesome_rounded : Icons.tune_rounded, size: 16, color: C.accentText),
                    const SizedBox(width: 6),
                    Text(context.tr('فهمنا إنك تدوّر على:', 'We understood:'),
                        style: const TextStyle(fontSize: 13, color: C.muted, fontWeight: FontWeight.w600)),
                  ]),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      for (final u in r.understood)
                        Chip(
                          label: Text(u.label),
                          backgroundColor: u.type == 'keyword' ? C.surface : C.mint,
                          labelStyle: const TextStyle(fontWeight: FontWeight.w700, color: C.primaryDeep, fontSize: 13),
                          side: BorderSide.none,
                          visualDensity: VisualDensity.compact,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.chip)),
                        ),
                    ],
                  ),
                ],
                if (r.reply.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  Text(r.reply, style: const TextStyle(fontSize: 14, height: 1.5)),
                ],
                if (r.categories.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  SizedBox(
                    height: 40,
                    child: ListView(
                      scrollDirection: Axis.horizontal,
                      children: [
                        for (final c in r.categories)
                          Padding(
                            padding: const EdgeInsetsDirectional.only(end: 8),
                            child: ActionChip(
                              avatar: const Icon(Icons.grid_view_rounded, size: 16, color: C.primary),
                              label: Text(c.name),
                              onPressed: () => context.push('/category/${c.id}?name=${Uri.encodeComponent(c.name)}'),
                              backgroundColor: C.surface,
                              side: BorderSide.none,
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.chip)),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
                if (r.suggestions.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Text(
                    r.productIds.isEmpty ? context.tr('جرّب:', 'Try:') : context.tr('بحث مشابه:', 'Related:'),
                    style: const TextStyle(fontSize: 13, color: C.muted, fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 6),
                  _ChipWrap(items: r.suggestions, icon: Icons.search_rounded, onTap: onPick),
                ],
                const SizedBox(height: 12),
              ],
            ),
          ),
        ),
        if (r.productIds.isEmpty)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: _AssistantCard(query: args.q),
            ),
          )
        else
          products.when(
            loading: () => const SliverToBoxAdapter(child: LoadingBox()),
            error: (e, _) => SliverToBoxAdapter(child: ErrorBox(e)),
            data: (list) => SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
              sliver: SliverGrid(
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  mainAxisExtent: 262,
                ),
                delegate: SliverChildBuilderDelegate(
                  (_, i) => ProductCard(list[i], index: i, width: null),
                  childCount: list.length,
                ),
              ),
            ),
          ),
        if (r.productIds.isNotEmpty)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 40),
              child: _AssistantCard(query: args.q, compact: true),
            ),
          ),
      ],
    );
  }
}

/// If the smart search isn't reachable, fall back to the store's normal search.
class _FallbackResults extends ConsumerWidget {
  const _FallbackResults({required this.query, required this.onPick});
  final String query;
  final ValueChanged<String> onPick;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final res = ref.watch(productsProvider(ProductQuery(search: query)));
    return res.when(
      loading: () => const LoadingBox(),
      error: (e, _) => ErrorBox(e),
      data: (list) => list.isEmpty
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: _AssistantCard(query: query),
              ),
            )
          : GridView.builder(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 2,
                mainAxisSpacing: 12,
                crossAxisSpacing: 12,
                mainAxisExtent: 262,
              ),
              itemCount: list.length,
              itemBuilder: (_, i) => ProductCard(list[i], index: i, width: null),
            ),
    );
  }
}

class _AssistantCard extends StatelessWidget {
  const _AssistantCard({required this.query, this.compact = false});
  final String query;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final title = query.isEmpty
        ? context.tr('محتار؟ قول للمساعد وش تحتاج', 'Not sure? Tell the assistant what you need')
        : compact
            ? context.tr('ما لقيت اللي يناسبك؟ اسأل المساعد', "Didn't find the right one? Ask the assistant")
            : context.tr('ما لقينا شي بهالاسم — خلّ المساعد يساعدك', "Nothing matched — let the assistant help");
    return Material(
      color: C.primary,
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: () => context.push('/assistant${query.isEmpty ? '' : '?q=${Uri.encodeComponent(query)}'}'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              const CircleAvatar(radius: 20, backgroundColor: C.accent, child: Icon(Icons.auto_awesome_rounded, color: C.ink)),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: display(15, color: Colors.white)),
                    if (!compact)
                      Text(
                        context.tr('مثلاً: "قطتي عمرها شهرين وش أعطيها؟"', 'e.g. "My kitten is 2 months old, what should I feed her?"'),
                        style: const TextStyle(fontSize: 12, color: Color(0xFFCFE3DD)),
                      ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right_rounded, color: Colors.white),
            ],
          ),
        ),
      ),
    );
  }
}

class _ChipWrap extends StatelessWidget {
  const _ChipWrap({required this.items, required this.icon, required this.onTap});
  final List<String> items;
  final IconData icon;
  final ValueChanged<String> onTap;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final q in items)
          ActionChip(
            avatar: Icon(icon, size: 16, color: C.primary),
            label: Text(q),
            onPressed: () => onTap(q),
            backgroundColor: C.surface,
            side: BorderSide.none,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.chip)),
          ),
      ],
    );
  }
}
