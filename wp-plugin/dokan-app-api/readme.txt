=== Dokan App API ===
Contributors: dokansa
Requires at least: 5.8
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.2.0
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

== Changelog ==

= 1.2.0 =
* Arabic + English: every route honours `lang=ar|en` (or Accept-Language). Errors, search replies/chips/suggestions,
  assistant answers, OTP text and pushes (customer's last language, user meta `_dokan_app_lang`). Search index per language.
* OTP `channel` (whatsapp|sms) with automatic fallback; `otp_channels` in /config; response `channel`.
* Past guest orders with the same phone (last 9 digits) are attached on login (`linked_orders`, max 200 per login).
* Policies in /config + GET /pages/<id>; complaints text (ar/en) in settings.
* Checkout `lat`/`lng`/`accuracy` → order meta (filter `dokan_app_location_meta_keys`) + Google Maps order note.

= 1.1.0 =
* GET /search (Arabic normaliser, concept parser, in-memory index), POST /assistant, GET /me/purchased.
* Optional Claude (server side only) for intent parsing and the assistant; rule-based fallback always works.
* Customer search report (WooCommerce → تطبيق دكان → بحث العملاء), popular searches, pet_categories.
* Filters: dokan_app_search_lexicon, dokan_app_search_stop_words, dokan_app_search_types, dokan_app_search_brand_aliases,
  dokan_app_brand_taxonomies, dokan_app_popular_searches, dokan_app_purchased_ids, dokan_app_policies, dokan_app_otp_channels.
