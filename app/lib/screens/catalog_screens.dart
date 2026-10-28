import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../router.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

class CategoriesScreen extends ConsumerWidget {
  const CategoriesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(configProvider);
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('الأقسام', 'Categories'))),
      body: config.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(configProvider)),
        data: (c) => GridView.builder(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, kTabBarClearance),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 2,
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            childAspectRatio: 1.05,
          ),
          itemCount: c.categories.length,
          itemBuilder: (context, i) {
            final cat = c.categories[i];
            return Material(
              color: C.tints[i % C.tints.length],
              borderRadius: BorderRadius.circular(22),
              child: InkWell(
                borderRadius: BorderRadius.circular(22),
                onTap: () => context.push('/category/${cat.id}?name=${Uri.encodeComponent(cat.name)}'),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(child: NetImage(cat.image, tint: Colors.transparent, padding: 0)),
                      const SizedBox(height: 8),
                      Text(cat.name, style: display(16)),
                      Text(context.tr('${cat.count} منتج', '${cat.count} products'), style: const TextStyle(fontSize: 12, color: C.muted)),
                    ],
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

/// Infinite product grid for a category / sale / search, with sub-category chips.
class ProductListScreen extends ConsumerStatefulWidget {
  const ProductListScreen({super.key, required this.title, required this.query});
  final String title;
  final ProductQuery query;

  @override
  ConsumerState<ProductListScreen> createState() => _ProductListScreenState();
}

class _ProductListScreenState extends ConsumerState<ProductListScreen> {
  final _items = <Product>[];
  final _scroll = ScrollController();
  List<Category> _subs = const [];
  int? _subId;
  int _page = 1;
  int _pages = 1;
  bool _loading = false;
  Object? _error;
  late String _order = widget.query.orderBy ?? 'popularity';

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 600) _load();
    });
    _load(reset: true);
    final cat = widget.query.category;
    if (cat != null) {
      ref.read(storeApiProvider).subcategories(cat).then((s) {
        if (mounted) setState(() => _subs = s);
      }).catchError((_) {});
    }
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load({bool reset = false}) async {
    if (_loading) return;
    if (!reset && _page > _pages) return;
    setState(() {
      _loading = true;
      _error = null;
      if (reset) {
        _items.clear();
        _page = 1;
        _pages = 1;
      }
    });
    try {
      final (list, pages) = await ref.read(storeApiProvider).products(
            category: _subId ?? widget.query.category,
            search: widget.query.search,
            onSale: widget.query.onSale,
            orderBy: _order,
            page: _page,
          );
      if (!mounted) return;
      setState(() {
        _items.addAll(list);
        _pages = pages;
        _page++;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final orders = {
      'popularity': context.tr('الأكثر مبيعاً', 'Best selling'),
      'price': context.tr('الأرخص', 'Lowest price'),
      'date': context.tr('الأحدث', 'Newest'),
    };
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(icon: Icons.arrow_back_rounded, label: context.tr('رجوع', 'Back')),
        ),
        actions: [
          PopupMenuButton<String>(
            tooltip: context.tr('الترتيب', 'Sort'),
            icon: const Icon(Icons.sort_rounded),
            initialValue: _order,
            onSelected: (v) {
              _order = v;
              _load(reset: true);
            },
            itemBuilder: (_) => [for (final e in orders.entries) PopupMenuItem(value: e.key, child: Text(e.value))],
          ),
        ],
      ),
      body: Column(
        children: [
          if (_subs.isNotEmpty)
            SizedBox(
              height: 52,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 6),
                children: [
                  _chip(context.tr('الكل', 'All'), _subId == null, () => setState(() => _subId = null)),
                  for (final s in _subs) _chip(s.name, _subId == s.id, () => setState(() => _subId = s.id)),
                ],
              ),
            ),
          Expanded(
            child: _items.isEmpty && _loading
                ? const LoadingBox()
                : _items.isEmpty && _error != null
                    ? ErrorBox(_error!, onRetry: () => _load(reset: true))
                    : _items.isEmpty
                        ? Center(
                            child: Text(context.tr('ما فيه منتجات هنا للحين', 'No products here yet'),
                                style: const TextStyle(color: C.muted)))
                        : GridView.builder(
                            controller: _scroll,
                            padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
                            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: 2,
                              mainAxisSpacing: 12,
                              crossAxisSpacing: 12,
                              mainAxisExtent: 262,
                            ),
                            itemCount: _items.length,
                            itemBuilder: (_, i) => ProductCard(_items[i], index: i, width: null),
                          ),
          ),
        ],
      ),
    );
  }

  Widget _chip(String label, bool on, VoidCallback onTap) => Padding(
        padding: const EdgeInsetsDirectional.only(end: 8),
        child: ChoiceChip(
          label: Text(label),
          selected: on,
          onSelected: (_) {
            onTap();
            _load(reset: true);
          },
          selectedColor: C.ink,
          labelStyle: TextStyle(color: on ? Colors.white : C.ink, fontWeight: FontWeight.w600),
          backgroundColor: C.surface,
          side: BorderSide.none,
          showCheckmark: false,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.chip)),
        ),
      );
}
