=== Dokan App API ===
Contributors: dokansa
Requires at least: 5.8
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.0.0
License: GPLv2 or later

واجهة برمجية لتطبيق دكان للجوال (Flutter) — Backend for the Dokan mobile app.

== الوصف (عربي) ==

تضيف الإضافة المسارات /wp-json/dokan-app/v1/ للتطبيق: تسجيل الدخول برمز التحقق (واتساب أو SMS)،
ملفات الحيوانات الأليفة، الطلبات والتتبع، طلب الإلغاء، الإشعارات (Firebase)، تذكير إعادة الطلب،
وإعدادات التطبيق. السلة والدفع تتم عبر WooCommerce Store API بنفس منطق الموقع. متوافقة مع HPOS.

== التثبيت (عربي) ==

1. ارفع مجلد dokan-app-api إلى wp-content/plugins ثم فعّل الإضافة.
2. من ووكومرس ← تطبيق دكان:
   * اختر طريقة إرسال رمز التحقق: واتساب (Phone Number ID + Access Token + اسم قالب Authentication معتمد بلغة ar) أو بوابة SMS.
   * الصق ملف Service Account JSON من Firebase (Project settings ← Service accounts ← Generate new private key).
   * أدخل رابط Chatwoot و Website Token، رقم واتساب، ملاحظات التوصيل، البنرات، وأقل إصدار للتطبيق.
3. في كل منتج طعام/رمل يمكنك تحديد "مدة الكفاية بالأيام" في تبويب عام (وإلا تُقدّر من الوزن × 15 يومًا).
4. تأكد أن الخادم يمرر ترويسة Authorization إلى PHP (التطبيق يرسل أيضًا X-Dokan-App-Token كبديل).
5. تأكد أن WP-Cron يعمل (أو اضبط cron حقيقيًا على الخادم) لتذكيرات الساعة 10 صباحًا بتوقيت الرياض.

== Installation (English) ==

1. Upload the dokan-app-api folder to wp-content/plugins and activate it. A token secret is generated
   and the daily reminder cron (10:00 Asia/Riyadh) is scheduled on activation.
2. WooCommerce → تطبيق دكان: configure the OTP sender (WhatsApp Cloud API authentication template or a
   generic SMS HTTP gateway), paste the Firebase service-account JSON, Chatwoot, WhatsApp number,
   delivery notes, banners and minimum app versions.
3. Optional per product: "مدة الكفاية بالأيام" (days of supply) in the General tab.
4. Developers: hook `dokan_app_send_otp` (return true when you sent the code) to use your own sender.
   Other filters: dokan_app_days_supply, dokan_app_order_tracking, dokan_app_order_stage,
   dokan_app_config, dokan_app_recommendations, dokan_app_phone_meta_keys.

Uninstalling deletes the plugin options only; customer data (pets, devices) and order meta are kept.
