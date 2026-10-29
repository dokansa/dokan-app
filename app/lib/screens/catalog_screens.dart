import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../router.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Main categories in a side rail; the chosen one's subcategories on the other side.
class CategoriesScreen extends ConsumerStatefulWidget {
  const CategoriesScreen({super.key});

  @override
  ConsumerState<CategoriesScreen> createState() => _CategoriesScreenState();
}

class _CategoriesScreenState extends ConsumerState<CategoriesScreen> {
  int? _selected;

  @override
  Widget build(BuildContext context) {
    final config = ref.watch(configProvider);
    return Scaffold(
      appBar: AppBar(
        title: Text(context.tr('الأقسام', 'Categories')),
        actions: [
          IconButton(
            tooltip: context.tr('بحث', 'Search'),
            icon: const Icon(Icons.search_rounded),
            onPressed: () => context.push('/search'),
          ),
        ],
      ),
      body: config.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(configProvider)),
        data: (c) {
          if (c.categories.isEmpty) return const SizedBox.shrink();
          // Default to the selected pet's kind (e.g. cats), else the first category.
          final pet = ref.watch(selectedPetProvider);
          final petCat = pet == null ? null : c.petCategories[pet.kind];
          final ids = c.categories.map((x) => x.id).toList();
          final selId = _selected != null && ids.contains(_selected)
              ? _selected!
              : (petCat != null && ids.contains(petCat) ? petCat : ids.first);
          final selected = c.categories.firstWhere((x) => x.id == selId);
          return Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _Rail(categories: c.categories, selectedId: selId, onPick: (id) => setState(() => _selected = id)),
              Expanded(child: _SubPanel(key: ValueKey(selId), category: selected)),
            ],
          );
        },
      ),
    );
  }
}

class _Rail extends StatelessWidget {
  const _Rail({required this.categories, required this.selectedId, required this.onPick});
  final List<Category> categories;
  final int selectedId;
  final ValueChanged<int> onPick;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 96,
      color: C.surface,
      child: ListView.builder(
        padding: EdgeInsets.only(top: 6, bottom: kTabBarClearance),
        itemCount: categories.length,
        itemBuilder: (context, i) {
          final cat = categories[i];
          final on = cat.id == selectedId;
          return Semantics(
            selected: on,
            button: true,
            label: cat.name,
            child: InkWell(
              onTap: () => onPick(cat.id),
              child: Container(
                padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
                decoration: BoxDecoration(
                  color: on ? C.ground : C.surface,
                  border: BorderDirectional(start: BorderSide(color: on ? C.accentStrong : Colors.transparent, width: 4)),
                ),
                child: Column(
                  children: [
                    SizedBox(
                      width: 52,
                      height: 52,
                      child: NetImage(cat.image, tint: C.tints[i % C.tints.length], radius: 16, padding: 2),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      cat.name,
                      textAlign: TextAlign.center,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 12,
                        height: 1.3,
                        fontWeight: on ? FontWeight.w700 : FontWeight.w500,
                        color: on ? C.ink : C.muted,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _SubPanel extends ConsumerWidget {
  const _SubPanel({super.key, required this.category});
  final Category category;

  void _open(BuildContext context, Category c) =>
      context.push('/category/${c.id}?name=${Uri.encodeComponent(c.name)}');

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final subs = ref.watch(subcategoriesProvider(category.id));
    return CustomScrollView(
      slivers: [
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: Material(
              color: C.primary,
              borderRadius: BorderRadius.circular(18),
              child: InkWell(
                borderRadius: BorderRadius.circular(18),
                onTap: () => _open(context, category),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(14, 12, 10, 12),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(category.name, style: display(17, color: Colors.white)),
                            Text(
                              context.tr('تسوّق الكل · ${category.count} منتج', 'Shop all · ${category.count} products'),
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
            ),
          ),
        ),
        ...subs.when<List<Widget>>(
          loading: () => [const SliverToBoxAdapter(child: LoadingBox())],
          error: (e, _) => [
            SliverToBoxAdapter(child: ErrorBox(e, onRetry: () => ref.invalidate(subcategoriesProvider(category.id)))),
          ],
          data: (list) => list.isEmpty
              ? [_ProductsSliver(categoryId: category.id)]
              : [
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(14, 4, 14, 0),
                    sliver: SliverGrid(
                      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 3,
                        mainAxisSpacing: 14,
                        crossAxisSpacing: 10,
                        mainAxisExtent: 124,
                      ),
                      delegate: SliverChildBuilderDelegate(
                        (context, i) {
                          final sc = list[i];
                          return InkWell(
                            borderRadius: BorderRadius.circular(16),
                            onTap: () => _open(context, sc),
                            child: Column(
                              children: [
                                AspectRatio(
                                  aspectRatio: 1,
                                  child: NetImage(sc.image, tint: C.tints[i % C.tints.length], radius: 16, padding: 6),
                                ),
                                const SizedBox(height: 6),
                                Text(
                                  sc.name,
                                  textAlign: TextAlign.center,
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, height: 1.25),
                                ),
                              ],
                            ),
                          );
                        },
                        childCount: list.length,
                      ),
                    ),
                  ),
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(14, 22, 14, 8),
                      child: Text(context.tr('الأكثر مبيعاً', 'Best sellers'), style: display(16)),
                    ),
                  ),
                  _ProductsSliver(categoryId: category.id),
                ],
        ),
        const SliverToBoxAdapter(child: SizedBox(height: kTabBarClearance)),
      ],
    );
  }
}

/// Best sellers of a category, two per row (used when it has no subcategories, and under them).
class _ProductsSliver extends ConsumerWidget {
  const _ProductsSliver({required this.categoryId});
  final int categoryId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final res = ref.watch(productsProvider(ProductQuery(category: categoryId, orderBy: 'popularity')));
    return res.when(
      loading: () => const SliverToBoxAdapter(child: LoadingBox()),
      error: (e, _) => SliverToBoxAdapter(child: ErrorBox(e)),
      data: (list) => SliverPadding(
        padding: const EdgeInsets.fromLTRB(14, 4, 14, 0),
        sliver: SliverGrid(
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 2,
            mainAxisSpacing: 10,
            crossAxisSpacing: 10,
            mainAxisExtent: 250,
          ),
          delegate: SliverChildBuilderDelegate(
            (_, i) => ProductCard(list[i], index: i, width: null),
            childCount: list.length,
          ),
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
