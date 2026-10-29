import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../services/local_store.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/product_rail.dart';

class ProductScreen extends ConsumerWidget {
  const ProductScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final product = ref.watch(productProvider(id));
    return product.when(
      loading: () => const Scaffold(body: LoadingBox()),
      error: (e, _) => Scaffold(
        appBar: AppBar(
            leading: Padding(
                padding: const EdgeInsets.all(5),
                child: RoundIconButton(icon: Icons.arrow_back_rounded, label: context.tr('رجوع', 'Back')))),
        body: ErrorBox(e, onRetry: () => ref.invalidate(productProvider(id))),
      ),
      data: (p) => _ProductView(product: p),
    );
  }
}

class _ProductView extends ConsumerStatefulWidget {
  const _ProductView({required this.product});
  final Product product;

  @override
  ConsumerState<_ProductView> createState() => _ProductViewState();
}

class _ProductViewState extends ConsumerState<_ProductView> {
  int _qty = 1;
  int _image = 0;
  ProductVariation? _variation;
  Product? _variationProduct;
  bool _adding = false;
  bool _added = false;

  Product get p => widget.product;
  Product get shown => _variationProduct ?? p;

  @override
  void initState() {
    super.initState();
    if (p.isVariable) _pick(p.variations.first);
    // Remember it for "Recently viewed" (after the first frame: providers can't change during build).
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) ref.read(recentlyViewedProvider.notifier).add(p.id);
    });
  }

  Future<void> _pick(ProductVariation v) async {
    setState(() {
      _variation = v;
      _added = false;
    });
    try {
      final vp = await ref.read(productProvider(v.id).future);
      if (mounted && _variation?.id == v.id) setState(() => _variationProduct = vp);
    } catch (_) {}
  }

  Future<void> _add() async {
    setState(() => _adding = true);
    try {
      final v = _variation;
      final cart = ref.read(cartProvider.notifier);
      try {
        await cart.add(v?.id ?? p.id, _qty);
      } catch (_) {
        // Variations with "any" attributes need the attribute values too.
        if (v == null) rethrow;
        await cart.add(v.id, _qty, variation: v.attributes);
      }
      if (!mounted) return;
      setState(() => _added = true);
      showMessage(context, context.tr('انضاف للسلة', 'Added to cart'));
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _adding = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final pet = ref.watch(selectedPetProvider);
    final config = ref.watch(configProvider).valueOrNull;
    final images = shown.images.isNotEmpty ? shown.images : p.images;
    final top = MediaQuery.paddingOf(context).top;

    return Scaffold(
      body: Stack(
        children: [
          ListView(
            padding: const EdgeInsets.only(bottom: 140),
            children: [
              Container(
                height: 360 + top,
                decoration: const BoxDecoration(
                  color: C.surface,
                  borderRadius: BorderRadius.vertical(bottom: Radius.circular(36)),
                ),
                padding: EdgeInsets.only(top: top + 60, bottom: 24),
                child: Column(
                  children: [
                    Expanded(
                      child: PageView.builder(
                        itemCount: images.isEmpty ? 1 : images.length,
                        onPageChanged: (i) => setState(() => _image = i),
                        itemBuilder: (_, i) => Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 40),
                          child: NetImage(images.isEmpty ? null : images[i], tint: C.peach, padding: 0),
                        ),
                      ),
                    ),
                    if (images.length > 1)
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          for (var i = 0; i < images.length; i++)
                            AnimatedContainer(
                              duration: const Duration(milliseconds: 200),
                              margin: const EdgeInsets.symmetric(horizontal: 3),
                              width: i == _image ? 18 : 6,
                              height: 6,
                              decoration: BoxDecoration(
                                color: i == _image ? C.ink : C.ink.withValues(alpha: 0.25),
                                borderRadius: BorderRadius.circular(3),
                              ),
                            ),
                        ],
                      ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (pet != null) _PetBadge(pet: pet, product: p),
                    if (p.categories.isNotEmpty)
                      Text(p.categories.first, style: const TextStyle(fontSize: 13, color: C.muted)),
                    const SizedBox(height: 4),
                    Text(p.name, style: display(22)),
                    const SizedBox(height: 16),
                    if (p.isVariable) ...[
                      Text(context.tr('الخيارات', 'Options'), style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          for (final v in p.variations)
                            ChoiceChip(
                              label: Text(v.label.isEmpty ? '#${v.id}' : v.label),
                              selected: _variation?.id == v.id,
                              onSelected: (_) => _pick(v),
                              showCheckmark: false,
                              selectedColor: C.ink,
                              backgroundColor: C.surface,
                              labelStyle: TextStyle(
                                color: _variation?.id == v.id ? Colors.white : C.ink,
                                fontWeight: FontWeight.w700,
                              ),
                              side: BorderSide(color: _variation?.id == v.id ? C.ink : const Color(0xFFE2E1DA), width: 1.5),
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                            ),
                        ],
                      ),
                      const SizedBox(height: 16),
                    ],
                    Row(
                      children: [
                        const Icon(Icons.local_shipping_outlined, color: C.primary, size: 20),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            config?.deliveryRiyadh ??
                                context.tr('داخل الرياض: يوصلك اليوم إذا طلبت قبل ٣ العصر', 'Riyadh: same-day delivery before 3 PM'),
                            style: const TextStyle(fontSize: 13),
                          ),
                        ),
                      ],
                    ),
                    if (!shown.inStock) ...[
                      const SizedBox(height: 12),
                      Text(context.tr('نفد من المخزون حالياً', 'Out of stock right now'),
                          style: const TextStyle(color: C.accentText, fontWeight: FontWeight.w700)),
                    ],
                    if (p.shortDescription.isNotEmpty || p.description.isNotEmpty) ...[
                      const SizedBox(height: 20),
                      Text(context.tr('عن المنتج', 'About this product'), style: display(16)),
                      const SizedBox(height: 6),
                      Text(
                        p.shortDescription.isNotEmpty ? p.shortDescription : p.description,
                        style: const TextStyle(fontSize: 14, height: 1.7, color: Color(0xFF3D4846)),
                      ),
                    ],
                  ],
                ),
              ),
              if (p.categoryIds.isNotEmpty) _Similar(product: p),
            ],
          ),
          PositionedDirectional(
            top: top + 8,
            start: 20,
            end: 20,
            child: Row(
              children: [
                RoundIconButton(icon: Icons.arrow_back_rounded, label: context.tr('رجوع', 'Back')),
                const Spacer(),
                RoundIconButton(
                  icon: Icons.shopping_bag_outlined,
                  label: context.tr('السلة', 'Cart'),
                  onTap: () => context.go('/cart'),
                ),
              ],
            ),
          ),
          Positioned(left: 0, right: 0, bottom: 0, child: _bottomBar()),
        ],
      ),
    );
  }

  Widget _bottomBar() {
    return Container(
      padding: EdgeInsets.fromLTRB(20, 14, 20, 14 + MediaQuery.paddingOf(context).bottom),
      decoration: const BoxDecoration(
        color: C.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        boxShadow: [BoxShadow(color: Color(0x1013201F), blurRadius: 24, offset: Offset(0, -6))],
      ),
      child: Row(
        children: [
          PriceText(shown, size: 22),
          const SizedBox(width: 12),
          Container(
            decoration: BoxDecoration(color: C.ground, borderRadius: BorderRadius.circular(14)),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  tooltip: context.tr('أنقص الكمية', 'Decrease quantity'),
                  onPressed: _qty > 1 ? () => setState(() => _qty--) : null,
                  icon: const Icon(Icons.remove_rounded),
                ),
                Text('$_qty', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                IconButton(
                  tooltip: context.tr('زِد الكمية', 'Increase quantity'),
                  onPressed: () => setState(() => _qty++),
                  icon: const Icon(Icons.add_rounded),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: FilledButton(
              style: FilledButton.styleFrom(backgroundColor: _added ? C.primarySoft : C.primary),
              onPressed: _adding || !shown.inStock ? null : (_added ? () => context.go('/cart') : _add),
              child: _adding
                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                  : Text(_added ? context.tr('روح للسلة', 'Go to cart') : context.tr('أضف للسلة', 'Add to cart')),
            ),
          ),
        ],
      ),
    );
  }
}

class _PetBadge extends StatelessWidget {
  const _PetBadge({required this.pet, required this.product});
  final Pet pet;
  final Product product;

  static const _kindWords = {
    'cat': ['قط', 'قطط', 'cat', 'kitten'],
    'dog': ['كلب', 'كلاب', 'جرو', 'dog', 'puppy'],
    'bird': ['طير', 'طيور', 'عصافير', 'ببغاء', 'bird'],
    'fish': ['سمك', 'أسماك', 'حوض', 'fish', 'aquarium'],
    'small': ['أرانب', 'أرنب', 'هامستر', 'سلاحف', 'صغيرة', 'rabbit', 'hamster', 'turtle', 'small'],
  };

  @override
  Widget build(BuildContext context) {
    final words = _kindWords[pet.kind] ?? const [];
    final hay = '${product.name} ${product.categories.join(' ')}'.toLowerCase();
    if (!words.any(hay.contains)) return const SizedBox.shrink();
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(R.chip)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.check_rounded, size: 15, color: C.primary),
          const SizedBox(width: 6),
          Text(context.tr('مناسب لـ${pet.name}', 'Good for ${pet.name}'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: C.primary)),
        ],
      ),
    );
  }
}


/// "You may also like": best sellers from the same category.
class _Similar extends ConsumerWidget {
  const _Similar({required this.product});
  final Product product;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final res = ref.watch(productsProvider(ProductQuery(category: product.categoryIds.last, orderBy: 'popularity')));
    return ProductRail(
      title: context.tr('ممكن يعجبك كمان', 'You may also like'),
      icon: Icons.thumb_up_alt_outlined,
      products: res.whenData((l) => l.where((x) => x.id != product.id).toList()),
    );
  }
}
