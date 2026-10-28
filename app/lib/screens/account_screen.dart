import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config.dart';
import '../models/models.dart';
import '../router.dart';
import '../services/push_service.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  Future<void> _editName(BuildContext context, WidgetRef ref, AppUser user) async {
    final first = TextEditingController(text: user.firstName);
    final last = TextEditingController(text: user.lastName);
    final email = TextEditingController(text: user.email);
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('بياناتي'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: first, decoration: const InputDecoration(labelText: 'الاسم')),
          const SizedBox(height: 8),
          TextField(controller: last, decoration: const InputDecoration(labelText: 'اسم العائلة')),
          const SizedBox(height: 8),
          TextField(
            controller: email,
            keyboardType: TextInputType.emailAddress,
            textDirection: TextDirection.ltr,
            decoration: const InputDecoration(labelText: 'الإيميل (اختياري)'),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('احفظ')),
        ],
      ),
    );
    if (ok == true) {
      try {
        await ref.read(sessionProvider.notifier).updateProfile({
          'first_name': first.text.trim(),
          'last_name': last.text.trim(),
          'email': email.text.trim(),
        });
      } catch (e) {
        if (context.mounted) showMessage(context, e, error: true);
      }
    }
    // Controllers are left to GC: disposing here races the dialog's exit animation.
  }

  Future<void> _logout(BuildContext context, WidgetRef ref) async {
    await PushService.unregister(ProviderScope.containerOf(context, listen: false));
    await ref.read(sessionProvider.notifier).logout();
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف الحساب'),
        content: const Text('بنحذف حسابك وبياناتك بعد مراجعة الطلب. الطلبات القديمة تبقى في سجلات المتجر حسب الأنظمة.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('رجوع')),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: TextButton.styleFrom(foregroundColor: C.accentText),
            child: const Text('احذف حسابي'),
          ),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await PushService.unregister(ProviderScope.containerOf(context, listen: false));
      await ref.read(sessionProvider.notifier).deleteAccount();
      if (context.mounted) showMessage(context, 'وصلنا طلب حذف الحساب');
    } catch (e) {
      if (context.mounted) showMessage(context, e, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider);
    final pets = ref.watch(petsProvider).valueOrNull ?? const <Pet>[];

    return Scaffold(
      appBar: AppBar(title: const Text('حسابي')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, kTabBarClearance),
        children: [
          if (user == null)
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(color: C.primary, borderRadius: BorderRadius.circular(24)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('سجّل دخولك', style: display(20, color: Colors.white)),
                const SizedBox(height: 4),
                const Text('تابع طلباتك وأضف حيواناتك ووصلك تذكير قبل ما يخلص الأكل',
                    style: TextStyle(color: Color(0xFFCFE3DD))),
                const SizedBox(height: 14),
                FilledButton(
                  style: FilledButton.styleFrom(backgroundColor: C.accent, foregroundColor: C.ink),
                  onPressed: () => context.push('/login'),
                  child: const Text('دخول برقم الجوال'),
                ),
              ]),
            )
          else ...[
            Material(
              color: C.surface,
              borderRadius: BorderRadius.circular(R.card),
              child: ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                leading: CircleAvatar(
                  radius: 24,
                  backgroundColor: C.peach,
                  child: Text(user.name.isEmpty ? 'د' : user.name.characters.first, style: display(18, weight: FontWeight.w700)),
                ),
                title: Text(user.name.isEmpty ? 'عميل دكان' : user.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(user.phone, textDirection: TextDirection.ltr, textAlign: TextAlign.right),
                trailing: const Icon(Icons.edit_outlined),
                onTap: () => _editName(context, ref, user),
              ),
            ),
            const SizedBox(height: 18),
            const SectionHeader('حيواناتي'),
            const SizedBox(height: 4),
            for (final p in pets)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Material(
                  color: C.surface,
                  borderRadius: BorderRadius.circular(R.card),
                  child: ListTile(
                    leading: PetAvatar(pet: p, size: 48),
                    title: Text(p.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(p.kindLabel),
                    trailing: const Icon(Icons.chevron_right_rounded),
                    onTap: () => context.push('/pet/${p.id}'),
                  ),
                ),
              ),
            _Tile(icon: Icons.add_rounded, title: 'أضف حيوان', onTap: () => context.push('/pet-edit')),
          ],
          const SizedBox(height: 18),
          _Tile(icon: Icons.support_agent_rounded, title: 'خدمة العملاء', onTap: () => context.push('/support')),
          _Tile(
            icon: Icons.policy_outlined,
            title: 'سياسة الخصوصية',
            onTap: () => launchUrl(Uri.parse('${AppConfig.storeUrl}/privacy-policy/')),
          ),
          if (user != null) ...[
            _Tile(icon: Icons.logout_rounded, title: 'تسجيل الخروج', onTap: () => _logout(context, ref)),
            _Tile(icon: Icons.person_remove_outlined, title: 'حذف الحساب', danger: true, onTap: () => _delete(context, ref)),
          ],
        ],
      ),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({required this.icon, required this.title, required this.onTap, this.danger = false});
  final IconData icon;
  final String title;
  final VoidCallback onTap;
  final bool danger;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Material(
          color: C.surface,
          borderRadius: BorderRadius.circular(R.card),
          child: ListTile(
            leading: Icon(icon, color: danger ? C.accentText : C.primary),
            title: Text(title, style: TextStyle(fontWeight: FontWeight.w600, color: danger ? C.accentText : C.ink)),
            trailing: const Icon(Icons.chevron_right_rounded),
            onTap: onTap,
          ),
        ),
      );
}
