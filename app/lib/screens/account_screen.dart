import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../router.dart';
import '../services/push_service.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/permission_prompts.dart';

class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  Future<void> _editName(BuildContext context, WidgetRef ref, AppUser user) async {
    final first = TextEditingController(text: user.firstName);
    final last = TextEditingController(text: user.lastName);
    final email = TextEditingController(text: user.email);
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.tr('بياناتي', 'My details')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: first, decoration: InputDecoration(labelText: ctx.tr('الاسم', 'First name'))),
          const SizedBox(height: 8),
          TextField(controller: last, decoration: InputDecoration(labelText: ctx.tr('اسم العائلة', 'Last name'))),
          const SizedBox(height: 8),
          TextField(
            controller: email,
            keyboardType: TextInputType.emailAddress,
            textDirection: TextDirection.ltr,
            decoration: InputDecoration(labelText: ctx.tr('الإيميل (اختياري)', 'Email (optional)')),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(ctx.tr('إلغاء', 'Cancel'))),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: Text(ctx.tr('احفظ', 'Save'))),
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
        title: Text(ctx.tr('حذف الحساب', 'Delete account')),
        content: Text(ctx.tr(
          'بنحذف حسابك وبياناتك بعد مراجعة الطلب. الطلبات القديمة تبقى في سجلات المتجر حسب الأنظمة.',
          'We will delete your account and data after reviewing the request. Past orders stay in the store records as required by law.',
        )),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(ctx.tr('رجوع', 'Back'))),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: TextButton.styleFrom(foregroundColor: C.accentText),
            child: Text(ctx.tr('احذف حسابي', 'Delete my account')),
          ),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await PushService.unregister(ProviderScope.containerOf(context, listen: false));
      await ref.read(sessionProvider.notifier).deleteAccount();
      if (context.mounted) showMessage(context, context.tr('وصلنا طلب حذف الحساب', 'Account deletion requested'));
    } catch (e) {
      if (context.mounted) showMessage(context, e, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider);
    final pets = ref.watch(petsProvider).valueOrNull ?? const <Pet>[];
    final lang = ref.watch(langProvider);

    return Scaffold(
      appBar: AppBar(title: Text(context.tr('حسابي', 'Account'))),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, kTabBarClearance),
        children: [
          if (user == null)
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(color: C.primary, borderRadius: BorderRadius.circular(24)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.tr('سجّل دخولك', 'Sign in'), style: display(20, color: Colors.white)),
                const SizedBox(height: 4),
                Text(
                  context.tr('تابع طلباتك وأضف حيواناتك ووصلك تذكير قبل ما يخلص الأكل',
                      'Track your orders, add your pets and get reminded before food runs out'),
                  style: const TextStyle(color: Color(0xFFCFE3DD)),
                ),
                const SizedBox(height: 14),
                FilledButton(
                  style: FilledButton.styleFrom(backgroundColor: C.accent, foregroundColor: C.ink),
                  onPressed: () => context.push('/login'),
                  child: Text(context.tr('دخول برقم الجوال', 'Sign in with mobile number')),
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
                title: Text(user.name.isEmpty ? context.tr('عميل دكان', 'Dokan customer') : user.name,
                    style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(user.phone,
                    textDirection: TextDirection.ltr, textAlign: context.isAr ? TextAlign.right : TextAlign.left),
                trailing: const Icon(Icons.edit_outlined),
                onTap: () => _editName(context, ref, user),
              ),
            ),
            const SizedBox(height: 18),
            SectionHeader(context.tr('حيواناتي', 'My pets')),
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
            _Tile(icon: Icons.add_rounded, title: context.tr('أضف حيوان', 'Add a pet'), onTap: () => context.push('/pet-edit')),
          ],
          const SizedBox(height: 18),
          // Language
          Material(
            color: C.surface,
            borderRadius: BorderRadius.circular(R.card),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 10, 12, 10),
              child: Row(
                children: [
                  const Icon(Icons.translate_rounded, color: C.primary),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Text(context.tr('اللغة', 'Language'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  ),
                  SegmentedButton<String>(
                    segments: const [
                      ButtonSegment(value: 'ar', label: Text('العربية')),
                      ButtonSegment(value: 'en', label: Text('English')),
                    ],
                    selected: {lang},
                    showSelectedIcon: false,
                    onSelectionChanged: (s) => ref.read(langProvider.notifier).set(s.first),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 8),
          if (user != null)
            _Tile(
              icon: Icons.notifications_active_outlined,
              title: context.tr('إشعارات الطلبات', 'Order notifications'),
              onTap: () async {
                final allowed = await PushService.isAllowed();
                if (!context.mounted) return;
                if (allowed == true) {
                  showMessage(context, context.tr('الإشعارات مفعّلة ✓', 'Notifications are on ✓'));
                } else {
                  await askNotifications(context, force: true);
                }
              },
            ),
          _Tile(
            icon: Icons.auto_awesome_rounded,
            title: context.tr('مساعد دكان', 'Dokan assistant'),
            onTap: () => context.push('/assistant'),
          ),
          _Tile(
            icon: Icons.support_agent_rounded,
            title: context.tr('خدمة العملاء', 'Customer care'),
            onTap: () => context.push('/support'),
          ),
          _Tile(
            icon: Icons.report_gmailerrorred_rounded,
            title: context.tr('الشكاوى والإلغاء', 'Complaints & cancellation'),
            onTap: () => context.push('/complaints'),
          ),
          _Tile(
            icon: Icons.policy_outlined,
            title: context.tr('السياسات والشروط', 'Policies & terms'),
            onTap: () => context.push('/policies'),
          ),
          if (user != null) ...[
            _Tile(icon: Icons.logout_rounded, title: context.tr('تسجيل الخروج', 'Sign out'), onTap: () => _logout(context, ref)),
            _Tile(
              icon: Icons.person_remove_outlined,
              title: context.tr('حذف الحساب', 'Delete account'),
              danger: true,
              onTap: () => _delete(context, ref),
            ),
          ],
          const SizedBox(height: 12),
          Center(
            child: Text(
              context.tr('دكان · نسخة تجريبية · تحديث ٣', 'Dokan · test version · update 3'),
              style: const TextStyle(fontSize: 12, color: C.faint),
            ),
          ),
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
