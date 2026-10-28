# Dokan App — API contract

The app never holds secret keys. It talks to two things on dokansa.com:

1. **WooCommerce Store API** (`/wp-json/wc/store/v1/...`) — products, cart, shipping, checkout. Same cart/checkout logic as the website, so weight-based shipping, COD fee, discounts and payment gateways (MyFatoorah, Tabby, Tamara, COD) behave exactly like the site.
2. **Dokan App API plugin** (`/wp-json/dokan-app/v1/...`) — login, pets, push tokens, orders/tracking, config.

## Auth

- Login returns an app token: `{"token": "...", "user": {...}}`.
- The app sends `Authorization: Bearer <token>` on EVERY request (Store API too). The plugin hooks `determine_current_user` so WordPress treats the request as that customer → Store API checkout attaches the order to the customer.
- Token = `base64url(json payload) . base64url(hmac_sha256(payload, secret))`, payload `{uid, iat, exp, v}`; `v` = per-user token version (user meta `_dokan_app_token_v`) so logout-all is possible. Lifetime 180 days. Secret stored in option `dokan_app_secret` (auto-generated on activation).
- Header fallback: some hosts strip `Authorization`; the app also sends `X-Dokan-App-Token: <token>`.

## Store API usage (app side)

- All cart calls send `Cart-Token: <token>` (stored after first response header `Cart-Token`) and `Nonce: <nonce>` (stored from response header `Nonce`). Also header `X-Dokan-App: 1`.
- Products: `GET /wc/store/v1/products?category=<id>&per_page=20&page=n&search=...`, `GET /wc/store/v1/products/<id>`, `GET /wc/store/v1/products/categories?parent=0`.
- Cart: `GET /cart`, `POST /cart/add-item {id, quantity}`, `POST /cart/update-item {key, quantity}`, `POST /cart/remove-item {key}`, `POST /cart/update-customer {billing_address, shipping_address}`, `POST /cart/select-shipping-rate {package_id, rate_id}`.
- Choose payment method (so COD fee etc. recalculates): `POST /cart/extensions {namespace: "dokan-app", data: {payment_method: "<gateway id>"}}`.
- Checkout: `POST /checkout {billing_address, shipping_address, payment_method, customer_note, extensions: {"dokan-app": {pet_id?: string}}}` → `{order_id, status, payment_result: {payment_status, redirect_url}}`.
  - If `redirect_url` is non-empty and not the order-received page → open it in the in-app payment WebView. Success when the WebView reaches a URL containing `/order-received/`. Failure/cancel when it returns to `/cart` or `/checkout` without order-received.
  - COD → no redirect, done.

## Dokan App API (`/wp-json/dokan-app/v1`)

All responses JSON. Errors: `{code, message}` with HTTP 4xx; `message` in Arabic.

| Method | Route | Auth | Body / Query | Response |
|---|---|---|---|---|
| GET | `/config` | no | — | `{store_name, currency:"SAR", riyadh_cutoff:"15:00", delivery_note_riyadh, delivery_note_ksa, whatsapp, chatwoot:{base_url, website_token}, payment_methods:[{id,title,description}], categories:[{id,name,slug,image,count}], banners:[{image,title,link}], min_app_version:{ios,android}}` |
| POST | `/auth/otp/request` | no | `{phone}` (Saudi, any format: 05xxxxxxxx / 9665xxxxxxxx / +966…) | `{ok:true, ttl:300}`. Rate limit: 1 per 60 s per phone, 5 per hour per IP. |
| POST | `/auth/otp/verify` | no | `{phone, code, name?}` | `{token, user}`; creates customer if phone unknown (requires name). |
| POST | `/auth/login` | no | `{username, password}` | `{token, user}` (fallback login for email/password customers) |
| POST | `/auth/logout` | yes | — | `{ok:true}` (bumps token version) |
| GET | `/me` | yes | — | `user` |
| PATCH | `/me` | yes | `{first_name?, last_name?, email?}` | `user` |
| DELETE | `/me` | yes | — | `{ok:true}` (account deletion request — required by Apple; marks user + emails admin, does not hard delete) |
| GET | `/pets` | yes | — | `[pet]` |
| POST | `/pets` | yes | pet fields (no id) | `pet` |
| PATCH | `/pets/<id>` | yes | partial pet | `pet` |
| DELETE | `/pets/<id>` | yes | — | `{ok:true}` |
| GET | `/pets/<id>/stock` | yes | — | `[stock_item]` |
| POST | `/devices` | yes | `{token, platform:"ios"\|"android"}` | `{ok:true}` |
| DELETE | `/devices` | yes | `{token}` | `{ok:true}` |
| GET | `/orders` | yes | `page` | `[order_summary]` |
| GET | `/orders/<id>` | yes | — | `order_detail` (only own orders) |
| POST | `/orders/<id>/reorder` | yes | — | `{added: n}` — adds same items into the Store API cart identified by the request's `Cart-Token` header |
| POST | `/orders/<id>/cancel-request` | yes | `{reason?}` | `{ok:true, status}` — only while `processing`; puts order on-hold with note (store policy) |
| GET | `/recommendations` | yes | `pet_id?` | `[product_id]` |

### Shapes

```
user = {id, first_name, last_name, display_name, email, phone}
pet = {id, name, kind:"cat"|"dog"|"bird"|"fish"|"small", breed, birth_date:"YYYY-MM-DD"|null, weight_kg:number|null, food_type:"dry"|"wet"|"mixed"|null, photo:string|null, remind:boolean}
stock_item = {product_id, name, image, last_order_date, days_supply, days_left, percent_left, remind_on}
order_summary = {id, number, status, status_label, date_created, total, currency, item_count, first_image}
order_detail = order_summary + {items:[{product_id, name, quantity, total, image}], shipping_total, fees:[{name,total}], payment_method_title, shipping_address:{...}, stage:1..5, tracking:{carrier, number, url}|null, can_cancel:boolean, timeline:[{stage, label, date|null}]}
```

### Stage mapping (order status → stage)
`pending|on-hold` → 1, `processing` → 2, shipped (`shipped`/`wc-shipped` custom status "تم الشحن") → 3, `out-for-delivery` (if present) → 4, `completed` → 5. `cancelled|refunded|failed` → stage 0 (show status_label).
Tracking read from order meta set by the shipping platform: `_tracking_url`, `_tracking_number`, `_tracking_provider` (filterable `dokan_app_order_tracking`).

## Push notifications (plugin → FCM HTTP v1)
- Service account JSON pasted in plugin settings page (WooCommerce → Dokan App).
- Sent on: order status change (processing/shipped/completed/cancelled), reorder reminders (daily cron 10:00 Riyadh: stock items whose `days_left` ≤ 5 and pet/product reminder on, at most once per order item), admin broadcast from settings page.
- Data payload `{type:"order"|"reminder"|"promo", order_id?, product_id?}` for deep links.

## Order tagging
Orders placed through the app: `created_via = "dokan-app"`, meta `_dokan_app_order = 1`, `_dokan_app_platform`, optional `_dokan_app_pet_id`, and an order note "طلب من التطبيق (iOS/Android)". Visible as a column badge in the orders list.

## Days of supply
Product meta `_dokan_app_days_supply` (int, editable in product edit screen, "مدة الكفاية بالأيام"). If empty and product is in a food or litter category, estimate from weight: `weight_kg * 15` days (filterable `dokan_app_days_supply`). Items without it are not tracked.

---

# v1.1 — Smart search, assistant, home sections

## Config additions (`GET /config`)
```
popular_searches: [string]          // owner-set in settings, else derived from top searches / categories
pet_categories: {cat:int|null, dog:int|null, bird:int|null, fish:int|null, small:int|null}   // top-level Arabic category per pet kind
assistant_enabled: bool             // true when a Claude API key is configured and the assistant switch is on
```

## `GET /search` (auth optional)
Query: `q` (required, ≤120 chars), `pet_id` (optional; uses that pet's kind/stage as soft context), `ai` (`0`|`1`, default 0 — 1 = allow Claude to interpret; the app sends 0 while typing and 1 on submit), `log` (`0`|`1` — 1 on submit, records the query for the owner's report), `per_page` (default 40, max 60).

Response:
```
{
  query: string,
  understood: [{type: "pet"|"category"|"type"|"stage"|"health"|"brand"|"price"|"sort"|"keyword", label: string (Arabic, shown as a chip), value: string}],
  reply: string,                    // short Arabic line, may be "" (e.g. "لقينا ٢٤ منتج أكل رطب للقطط البالغة")
  product_ids: [int],               // best first
  total: int,
  suggestions: [string],            // did-you-mean / related searches (Arabic), up to 6
  categories: [{id:int, name:string}],  // matching categories to jump to, up to 4
  ai: bool                          // true if Claude was used for this answer
}
```
Must answer in < 400 ms for `ai=0` (in-memory index, see plugin notes). Never errors on "no results": returns empty `product_ids` with `suggestions`.

## `POST /assistant` (auth optional)
Body: `{messages: [{role: "user"|"assistant", content: string}], pet_id?: string}` — at most 10 messages, each ≤ 600 chars, last one is the user's.
Response: `{reply: string, product_ids: [int] (≤ 6), chips: [string] (≤ 4 short follow-up suggestions), ai: bool}`.
If no Claude key / assistant off / Claude fails → still answers using rule-based search with a templated reply (`ai:false`).
Rate limit: 30 assistant calls / hour per user (or IP when guest) → 429 `dokan_app_rate_limited`.

## `GET /me/purchased` (auth)
`[int]` product ids the customer bought before, most frequent / recent first, up to 20, only products still published and in stock.

---

# v1.2 — Language, login channel, order linking, location, policies

## Language (Arabic + English)
- The app sends `lang=ar|en` as a query parameter on EVERY request (Store API and dokan-app), plus `Accept-Language`.
- Store API: Polylang already filters products/categories by `lang` (verified on staging).
- dokan-app routes must honour `lang`: categories / pet_categories / popular_searches / policies in that language; every user-facing string (errors, search `reply`, `understood` labels, `suggestions`, assistant `reply`/`chips`, OTP message text, push titles/bodies for that customer — store the customer's last used lang in user meta `_dokan_app_lang`) in Arabic or English. Search index is built per language. Default `ar`.

## Config additions
```
otp_channels: ["whatsapp","sms"]     // only the channels that are configured; order = preference
policies: [{key: "privacy"|"returns"|"shipping"|"terms"|"complaints", title: string, page_id: int|null, content_html: string|null}]
          // page_id when a WP page is chosen (content via /pages/<id>); for "complaints" content_html comes from a settings textarea (ar + en)
```
Delivery notes get English variants in settings (`delivery_note_riyadh_en`, `delivery_note_ksa_en`); config returns the one for `lang`.

## `POST /auth/otp/request`
Body adds optional `channel: "whatsapp"|"sms"`. If the chosen channel fails or isn't configured and the other one is, fall back to it. Response: `{ok: true, ttl: 300, channel: "whatsapp"|"sms"}` (channel actually used).

## Linking customers to their orders
After a successful `/auth/otp/verify` (and `/auth/login`), attach the customer's past **guest** orders (customer_id = 0) whose billing phone matches the account phone (compare last 9 digits, normalised) to the account. Run it in batches (max 200 per login), idempotent. Response adds `linked_orders: int` (how many were attached this time). Also: when an order is placed by a logged-in app customer, store their normalised phone on the order as billing phone if empty.

## `GET /pages/<id>`
Only for pages selected as policies. `{id, title, content_html}` in the requested lang (Polylang translation of the page when it exists). Content passed through `apply_filters('the_content')` and `wp_kses_post`.

## Checkout extension data (`extensions["dokan-app"]`)
Adds optional `lat`, `lng` (floats), `accuracy` (metres). Saved to order meta `_dokan_app_lat`, `_dokan_app_lng`, `_dokan_app_loc_accuracy`, plus an order note "موقع العميل على الخريطة: https://maps.google.com/?q=LAT,LNG". Meta keys are filterable (`dokan_app_location_meta_keys`) so they can also be written to the keys the website's own map picker uses.
