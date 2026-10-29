import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../router.dart';
import '../services/local_store.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/product_rail.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  String _greeting(BuildContext context) {
    final h = DateTime.now().hour;
    if (h < 12) return context.tr('صباح الخير', 'Good morning');
    return context.tr('مساء الخير', 'Good evening');
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(configProvider);
    final pets = ref.watch(petsProvider).valueOrNull ?? const <Pet>[];
    final pet = ref.watch(selectedPetProvider);
    final user = ref.watch(sessionProvider);
    final petCat = pet == null ? null : config.valueOrNull?.petCategories[pet.kind];
    final forKind = pet == null || petCat == null ? '' : ' ${petKindPlural(pet.kind, context.isAr)}';
    final popular = config.valueOrNull?.popularSearches ?? const <String>[];

    return Scaffold(
      body: RefreshIndicator(
        color: C.primary,
        onRefresh: () async {
          ref.invalidate(configProvider);
          ref.invalidate(petsProvider);
          ref.invalidate(forPetProvider);
          ref.invalidate(bestSellersProvider);
          ref.invalidate(newArrivalsProvider);
          ref.invalidate(offersProvider);
          ref.invalidate(purchasedProductsProvider);
          ref.invalidate(recentlyViewedProductsProvider);
          await ref.read(cartProvider.notifier).refresh();
        },
        child: ListView(
          padding: EdgeInsets.only(top: MediaQuery.paddingOf(context).top + 8, bottom: kTabBarClearance),
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          user != null && user.firstName.isNotEmpty
                              ? '${_greeting(context)} ${context.tr('يا', '')} ${user.firstName}'.replaceAll('  ', ' ')
                              : _greeting(context),
                          style: const TextStyle(fontSize: 14, color: C.muted),
                        ),
                        Text(context.tr('دكان', 'Dokan'), style: display(26, weight: FontWeight.w700)),
                      ],
                    ),
                  ),
                  RoundIconButton(
                    icon: Icons.support_agent_rounded,
                    label: context.tr('خدمة العملاء', 'Customer care'),
                    onTap: () => context.push('/support'),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 20),
              child: _SearchBar(),
            ),
            const SizedBox(height: 16),
            _PetSwitcher(pets: pets, selected: pet),
            if (pet != null) _ReorderCard(pet: pet),
            if (pet == null) const _AddPetCard(),
            const SizedBox(height: 20),
            SectionHeader(context.tr('الأقسام', 'Categories'),
                action: context.tr('الكل', 'All'), onAction: () => context.go('/categories')),
            const SizedBox(height: 4),
            config.when(
              data: (c) => _CategoryRow(categories: c.categories),
              loading: () => const LoadingBox(height: 90),
              error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(configProvider)),
            ),
            if ((config.valueOrNull?.banners ?? const []).isNotEmpty) ...[
              const SizedBox(height: 16),
              _Banners(banners: config.value!.banners),
            ],
            ProductRail(
              title: pet != null ? context.tr('مختار لـ${pet.name}', 'Picked for ${pet.name}') : context.tr('عروض اليوم', "Today's offers"),
              icon: pet != null ? Icons.pets_rounded : Icons.local_offer_outlined,
              products: ref.watch(forPetProvider),
              action: context.tr('عرض الكل', 'See all'),
              onAction: () => context.push('/offers'),
              hideWhenEmpty: false,
              onRetry: () => ref.invalidate(forPetProvider),
            ),
            ProductRail(
              title: context.tr('اشتريته قبل', 'Buy again'),
              icon: Icons.autorenew_rounded,
              products: ref.watch(purchasedProductsProvider),
              action: context.tr('طلباتي', 'My orders'),
              onAction: () => context.go('/orders'),
            ),
            ProductRail(
              title: context.tr('الأكثر مبيعاً$forKind', 'Best sellers$forKind'),
              icon: Icons.local_fire_department_outlined,
              products: ref.watch(bestSellersProvider),
              action: context.tr('عرض الكل', 'See all'),
              onAction: () => context.push('/best-sellers${petCat == null ? '' : '?cat=$petCat'}'),
              hideWhenEmpty: false,
              onRetry: () => ref.invalidate(bestSellersProvider),
            ),
            const _RecentlyViewed(),
            if (popular.isNotEmpty) _PopularSearches(items: popular),
            ProductRail(
              title: context.tr('وصل حديثاً$forKind', 'New arrivals$forKind'),
              icon: Icons.fiber_new_outlined,
              products: ref.watch(newArrivalsProvider),
              action: context.tr('عرض الكل', 'See all'),
              onAction: () => context.push('/new${petCat == null ? '' : '?cat=$petCat'}'),
            ),
            if (pet != null)
              ProductRail(
                title: context.tr('العروض', 'Offers'),
                icon: Icons.local_offer_outlined,
                products: ref.watch(offersProvider),
                action: context.tr('عرض الكل', 'See all'),
                onAction: () => context.push('/offers'),
              ),
          ],
        ),
      ),
    );
  }
}

class _SearchBar extends StatelessWidget {
  const _SearchBar();

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Material(
            color: C.surface,
            borderRadius: BorderRadius.circular(16),
            child: InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: () => context.push('/search'),
              child: SizedBox(
                height: 50,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Row(
                    children: [
                      const Icon(Icons.search_rounded, color: C.muted),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(context.tr('وش تدور؟ أكل، رمل، ألعاب…', 'What are you looking for?'),
                            maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: C.muted, fontSize: 15)),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(width: 10),
        Tooltip(
          message: context.tr('مساعد دكان', 'Dokan assistant'),
          child: Material(
            color: C.primary,
            borderRadius: BorderRadius.circular(16),
            child: InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: () => context.push('/assistant'),
              child: const SizedBox(width: 50, height: 50, child: Icon(Icons.auto_awesome_rounded, color: C.accent)),
            ),
          ),
        ),
      ],
    );
  }
}

class _PetSwitcher extends ConsumerWidget {
  const _PetSwitcher({required this.pets, required this.selected});
  final List<Pet> pets;
  final Pet? selected;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (pets.isEmpty) return const SizedBox.shrink();
    return SizedBox(
      height: 90,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 20),
        children: [
          for (final p in pets)
            Padding(
              padding: const EdgeInsetsDirectional.only(end: 14),
              child: Semantics(
                button: true,
                selected: p.id == selected?.id,
                label: p.name,
                child: GestureDetector(
                  onTap: () {
                    if (p.id == selected?.id) {
                      context.push('/pet/${p.id}');
                    } else {
                      ref.read(selectedPetIdProvider.notifier).state = p.id;
                    }
                  },
                  child: Column(
                    children: [
                      PetAvatar(pet: p, selected: p.id == selected?.id),
                      const SizedBox(height: 6),
                      Text(p.name, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                    ],
                  ),
                ),
              ),
            ),
          Semantics(
            button: true,
            label: context.tr('أضف حيوان', 'Add a pet'),
            child: GestureDetector(
              onTap: () async {
                if (await requireLogin(context, ref) && context.mounted) context.push('/pet-edit');
              },
              child: Column(
                children: [
                  Container(
                    width: 58,
                    height: 58,
                    decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: C.faint, width: 1.5)),
                    child: const Icon(Icons.add_rounded, color: C.muted),
                  ),
                  const SizedBox(height: 6),
                  Text(context.tr('أضف', 'Add'),
                      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: C.muted)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ReorderCard extends ConsumerWidget {
  const _ReorderCard({required this.pet});
  final Pet pet;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final stock = ref.watch(petStockProvider(pet.id)).valueOrNull ?? const <StockItem>[];
    if (stock.isEmpty) return const SizedBox.shrink();
    final item = (stock.toList()..sort((a, b) => a.daysLeft.compareTo(b.daysLeft))).first;
    final title = item.low
        ? context.tr('أكل ${pet.name} قرب يخلص', "${pet.name}'s food is running low")
        : context.tr('${item.name} يكفي ${pet.name} للحين', '${pet.name} still has ${item.name}');
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 0),
      child: Container(
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(color: C.primary, borderRadius: BorderRadius.circular(24)),
        child: Stack(
          children: [
            const PositionedDirectional(
              end: -30,
              bottom: -40,
              child: CircleAvatar(radius: 70, backgroundColor: C.primarySoft),
            ),
            Padding(
              padding: const EdgeInsets.all(18),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [
                          const Icon(Icons.autorenew_rounded, size: 14, color: Color(0xFFFFC8A1)),
                          const SizedBox(width: 6),
                          Text(context.tr('تذكير إعادة الطلب', 'Reorder reminder'),
                              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFFFFC8A1))),
                        ]),
                        const SizedBox(height: 6),
                        Text(title, style: display(18, color: Colors.white)),
                        const SizedBox(height: 4),
                        Text(
                          item.daysLeft <= 0
                              ? context.tr('المفروض خلص، اطلبه الحين', 'Probably finished — order now')
                              : context.tr('باقي تقريباً ${item.daysLeft} أيام', 'About ${item.daysLeft} days left'),
                          style: const TextStyle(fontSize: 13, color: Color(0xFFCFE3DD)),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 10),
                  FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: C.accent,
                      foregroundColor: C.ink,
                      minimumSize: const Size(0, 46),
                      padding: const EdgeInsets.symmetric(horizontal: 14),
                    ),
                    onPressed: () => context.push('/product/${item.productId}'),
                    child: Text(context.tr('اطلبه', 'Order')),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AddPetCard extends ConsumerWidget {
  const _AddPetCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 2, 20, 0),
      child: Material(
        color: C.primary,
        borderRadius: BorderRadius.circular(24),
        child: InkWell(
          borderRadius: BorderRadius.circular(24),
          onTap: () async {
            if (await requireLogin(context, ref) && context.mounted) context.push('/pet-edit');
          },
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(context.tr('عرّفنا على حيوانك', 'Tell us about your pet'), style: display(18, color: Colors.white)),
                      const SizedBox(height: 4),
                      Text(
                        context.tr('نقترح لك اللي يناسبه ونذكرك قبل ما يخلص أكله',
                            "We'll suggest what suits them and remind you before their food runs out"),
                        style: const TextStyle(fontSize: 13, color: Color(0xFFCFE3DD)),
                      ),
                    ],
                  ),
                ),
                const CircleAvatar(radius: 24, backgroundColor: C.accent, child: Icon(Icons.pets_rounded, color: C.ink)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _CategoryRow extends StatelessWidget {
  const _CategoryRow({required this.categories});
  final List<Category> categories;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 98,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 20),
        itemCount: categories.length,
        separatorBuilder: (_, __) => const SizedBox(width: 10),
        itemBuilder: (context, i) {
          final c = categories[i];
          return InkWell(
            borderRadius: BorderRadius.circular(18),
            onTap: () => context.push('/category/${c.id}?name=${Uri.encodeComponent(c.name)}'),
            child: SizedBox(
              width: 66,
              child: Column(
                children: [
                  SizedBox(width: 66, height: 66, child: NetImage(c.image, tint: C.tints[i % C.tints.length], radius: 18)),
                  const SizedBox(height: 6),
                  Text(c.name,
                      maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _Banners extends StatefulWidget {
  const _Banners({required this.banners});
  final List<PromoBanner> banners;

  @override
  State<_Banners> createState() => _BannersState();
}

class _BannersState extends State<_Banners> {
  final _controller = PageController(viewportFraction: 0.9);

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 150,
      child: PageView.builder(
        controller: _controller,
        itemCount: widget.banners.length,
        itemBuilder: (context, i) {
          final b = widget.banners[i];
          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: 6),
            child: Semantics(
              button: b.link.isNotEmpty,
              label: b.title,
              child: GestureDetector(
                onTap: () {
                  if (b.link.startsWith('/')) context.push(b.link);
                },
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(22),
                  child: CachedNetworkImage(imageUrl: b.image, fit: BoxFit.cover),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _RecentlyViewed extends ConsumerWidget {
  const _RecentlyViewed();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (ref.watch(recentlyViewedProvider).isEmpty) return const SizedBox.shrink();
    return ProductRail(
      title: context.tr('شاهدتها مؤخراً', 'Recently viewed'),
      icon: Icons.history_rounded,
      products: ref.watch(recentlyViewedProductsProvider),
      action: context.tr('مسح', 'Clear'),
      onAction: () => ref.read(recentlyViewedProvider.notifier).clear(),
    );
  }
}

class _PopularSearches extends StatelessWidget {
  const _PopularSearches({required this.items});
  final List<String> items;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(context.tr('الناس تدوّر على', 'People are searching for'), style: display(17)),
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final q in items.take(10))
                ActionChip(
                  label: Text(q),
                  avatar: const Icon(Icons.trending_up_rounded, size: 16, color: C.primary),
                  onPressed: () => context.push('/search?q=${Uri.encodeComponent(q)}'),
                  backgroundColor: C.surface,
                  side: BorderSide.none,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.chip)),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
