import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'models/models.dart';
import 'screens/account_screen.dart';
import 'screens/cart_screen.dart';
import 'screens/catalog_screens.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'screens/order_screens.dart';
import 'screens/payment_screen.dart';
import 'screens/pet_screens.dart';
import 'screens/product_screen.dart';
import 'screens/support_screen.dart';
import 'state/providers.dart';
import 'theme.dart';

final rootNavigatorKey = GlobalKey<NavigatorState>();

final routerProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: '/',
    routes: [
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => _Shell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/', builder: (_, __) => const HomeScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/categories', builder: (_, __) => const CategoriesScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/cart', builder: (_, __) => const CartScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/orders', builder: (_, __) => const OrdersScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/account', builder: (_, __) => const AccountScreen())]),
        ],
      ),
      GoRoute(
        path: '/product/:id',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, s) => ProductScreen(id: int.parse(s.pathParameters['id']!)),
      ),
      GoRoute(
        path: '/category/:id',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, s) => ProductListScreen(
          title: s.uri.queryParameters['name'] ?? '',
          query: ProductQuery(category: int.parse(s.pathParameters['id']!)),
        ),
      ),
      GoRoute(
        path: '/search',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, s) => SearchScreen(initial: s.uri.queryParameters['q'] ?? ''),
      ),
      GoRoute(
        path: '/offers',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, __) => const ProductListScreen(title: 'العروض', query: ProductQuery(onSale: true)),
      ),
      GoRoute(
        path: '/pay',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, s) => PaymentScreen(args: s.extra! as PaymentArgs),
      ),
      GoRoute(
        path: '/order/:id',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, s) => OrderScreen(
          id: int.parse(s.pathParameters['id']!),
          justPlaced: s.uri.queryParameters['new'] == '1',
        ),
      ),
      GoRoute(
        path: '/pet/:id',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, s) => PetScreen(id: s.pathParameters['id']!),
      ),
      GoRoute(
        path: '/pet-edit',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, s) => PetEditScreen(pet: s.extra as Pet?),
      ),
      GoRoute(
        path: '/support',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, s) => SupportScreen(orderId: int.tryParse(s.uri.queryParameters['order'] ?? '')),
      ),
      GoRoute(
        path: '/login',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, __) => const LoginScreen(),
      ),
    ],
  );
});

class _Shell extends ConsumerWidget {
  const _Shell({required this.shell});
  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final count = ref.watch(cartCountProvider);
    const tabs = [
      (Icons.home_rounded, 'الرئيسية'),
      (Icons.grid_view_rounded, 'الأقسام'),
      (Icons.shopping_bag_outlined, 'السلة'),
      (Icons.inventory_2_outlined, 'طلباتي'),
      (Icons.person_outline_rounded, 'حسابي'),
    ];
    return Scaffold(
      extendBody: true,
      body: shell,
      bottomNavigationBar: SafeArea(
        minimum: const EdgeInsets.fromLTRB(12, 0, 12, 12),
        child: Container(
          height: 68,
          decoration: BoxDecoration(color: C.ink, borderRadius: BorderRadius.circular(24)),
          child: Row(
            children: [
              for (var i = 0; i < tabs.length; i++)
                Expanded(
                  child: Semantics(
                    selected: shell.currentIndex == i,
                    button: true,
                    label: tabs[i].$2,
                    child: InkResponse(
                      onTap: () => shell.goBranch(i, initialLocation: i == shell.currentIndex),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Badge(
                            isLabelVisible: i == 2 && count > 0,
                            label: Text('$count', style: const TextStyle(color: C.ink, fontWeight: FontWeight.w700)),
                            backgroundColor: C.accent,
                            child: Icon(tabs[i].$1,
                                size: 22, color: shell.currentIndex == i ? C.accent : const Color(0xFFC9D3D0)),
                          ),
                          const SizedBox(height: 3),
                          ExcludeSemantics(
                            child: Text(
                              tabs[i].$2,
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w600,
                                color: shell.currentIndex == i ? C.accent : const Color(0xFFC9D3D0),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Bottom padding so content clears the floating tab bar.
const double kTabBarClearance = 110;
