import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../models/models.dart';
import '../router.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  String _greeting() {
    final h = DateTime.now().hour;
    if (h < 12) return 'صباح الخير';
    return 'مساء الخير';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(configProvider);
    final pets = ref.watch(petsProvider).valueOrNull ?? const <Pet>[];
    final pet = ref.watch(selectedPetProvider);
    final user = ref.watch(sessionProvider);

    return Scaffold(
      body: RefreshIndicator(
        color: C.primary,
        onRefresh: () async {
          ref.invalidate(configProvider);
          ref.invalidate(petsProvider);
          ref.invalidate(forPetProvider);
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
                          user != null && user.firstName.isNotEmpty ? '${_greeting()} يا ${user.firstName}' : _greeting(),
                          style: const TextStyle(fontSize: 14, color: C.muted),
                        ),
                        Text('دكان', style: display(26, weight: FontWeight.w700)),
                      ],
                    ),
                  ),
                  RoundIconButton(
                    icon: Icons.support_agent_rounded,
                    label: 'خدمة العملاء',
                    onTap: () => context.push('/support'),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: _SearchField(),
            ),
            const SizedBox(height: 16),
            _PetSwitcher(pets: pets, selected: pet),
            if (pet != null) _ReorderCard(pet: pet),
            if (pet == null) const _AddPetCard(),
            const SizedBox(height: 20),
            SectionHeader('الأقسام', action: 'الكل', onAction: () => context.go('/categories')),
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
            const SizedBox(height: 16),
            SectionHeader(pet != null ? 'مختار لـ${pet.name}' : 'عروض اليوم',
                action: 'عرض الكل', onAction: () => context.push('/offers')),
            const SizedBox(height: 4),
            const _ForPetRow(),
            const SizedBox(height: 8),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: _DeliveryNote(text: config.valueOrNull?.deliveryRiyadh),
            ),
          ],
        ),
      ),
    );
  }
}

class _SearchField extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Material(
      color: C.surface,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => context.push('/search'),
        child: const SizedBox(
          height: 50,
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              children: [
                Icon(Icons.search_rounded, color: C.muted),
                SizedBox(width: 10),
                Text('دوّر أكل، رمل، ألعاب…', style: TextStyle(color: C.muted, fontSize: 15)),
              ],
            ),
          ),
        ),
      ),
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
            label: 'أضف حيوان',
            child: GestureDetector(
              onTap: () async {
                if (await requireLogin(context, ref) && context.mounted) context.push('/pet-edit');
              },
              child: Column(
                children: [
                  Container(
                    width: 58,
                    height: 58,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(color: C.faint, width: 1.5),
                    ),
                    child: const Icon(Icons.add_rounded, color: C.muted),
                  ),
                  const SizedBox(height: 6),
                  const Text('أضف', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: C.muted)),
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
    final title = item.low ? 'أكل ${pet.name} قرب يخلص' : '${item.name} يكفي ${pet.name} للحين';
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
                        const Row(children: [
                          Icon(Icons.autorenew_rounded, size: 14, color: Color(0xFFFFC8A1)),
                          SizedBox(width: 6),
                          Text('تذكير إعادة الطلب',
                              style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFFFFC8A1))),
                        ]),
                        const SizedBox(height: 6),
                        Text(title, style: display(18, color: Colors.white)),
                        const SizedBox(height: 4),
                        Text(
                          item.daysLeft <= 0 ? 'المفروض خلص، اطلبه الحين' : 'باقي تقريباً ${item.daysLeft} أيام',
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
                    child: const Text('اطلبه'),
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
                      Text('عرّفنا على حيوانك', style: display(18, color: Colors.white)),
                      const SizedBox(height: 4),
                      const Text('نقترح لك اللي يناسبه ونذكرك قبل ما يخلص أكله',
                          style: TextStyle(fontSize: 13, color: Color(0xFFCFE3DD))),
                    ],
                  ),
                ),
                const CircleAvatar(
                  radius: 24,
                  backgroundColor: C.accent,
                  child: Icon(Icons.pets_rounded, color: C.ink),
                ),
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
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
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
  final _page = PageController(viewportFraction: 0.9);

  @override
  void dispose() {
    _page.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final banners = widget.banners;
    return SizedBox(
      height: 150,
      child: PageView.builder(
        controller: _page,
        itemCount: banners.length,
        itemBuilder: (context, i) {
          final b = banners[i];
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

class _ForPetRow extends ConsumerWidget {
  const _ForPetRow();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final products = ref.watch(forPetProvider);
    return products.when(
      loading: () => const LoadingBox(height: 250),
      error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(forPetProvider)),
      data: (list) => SizedBox(
        height: 262,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 20),
          itemCount: list.length,
          separatorBuilder: (_, __) => const SizedBox(width: 12),
          itemBuilder: (_, i) => ProductCard(list[i], index: i),
        ),
      ),
    );
  }
}

class _DeliveryNote extends StatelessWidget {
  const _DeliveryNote({this.text});
  final String? text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(18)),
      child: Row(
        children: [
          const Icon(Icons.local_shipping_outlined, color: C.primary),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text ?? 'داخل الرياض: يوصلك اليوم إذا طلبت قبل ٣ العصر',
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: C.primaryDeep)),
          ),
        ],
      ),
    );
  }
}
