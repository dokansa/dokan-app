#!/usr/bin/env bash
# Places a test cash-on-delivery order on staging exactly like the app does (customer token,
# Cart-Token session, Store API checkout). Prints only results, never secrets.
set -u
BASE="${STORE_URL:-https://staging.dokansa.com}"
CK="dk_stg=$(printf '%s' "$STAGING_KEY" | sha256sum | cut -d' ' -f1)"
PHONE="0500000123"
J='Content-Type: application/json'
api() { curl -s -H "Cookie: $CK" -H "$J" "$@"; }
note() { echo "$*"; echo "$*" >> "$GITHUB_STEP_SUMMARY"; }

note "otp: $(api -X POST "$BASE/wp-json/dokan-app/v1/auth/otp/request" -d "{\"phone\":\"$PHONE\",\"channel\":\"sms\"}" | head -c 200)"
TOK=$(api -X POST "$BASE/wp-json/dokan-app/v1/auth/otp/verify" -d "{\"phone\":\"$PHONE\",\"code\":\"123456\",\"name\":\"تجربة التطبيق\"}" | jq -r '.token // empty')
[ -n "$TOK" ] || { note "login failed"; exit 1; }
H=$(mktemp)
api -D "$H" -H "Authorization: Bearer $TOK" "$BASE/wp-json/wc/store/v1/cart" > cart.json
CT=$(grep -i '^cart-token:' "$H" | cut -d' ' -f2 | tr -d '\r')
NONCE=$(grep -i '^nonce:' "$H" | cut -d' ' -f2 | tr -d '\r')
S() { api -H "Authorization: Bearer $TOK" -H "Cart-Token: $CT" -H "Nonce: $NONCE" -H "X-Dokan-App: android" "$@"; }

# Empty the cart, then add products like a customer.
for k in $(jq -r '.items[].key' cart.json); do S -X POST "$BASE/wp-json/wc/store/v1/cart/remove-item" -d "{\"key\":\"$k\"}" >/dev/null; done
for p in ${PRODUCTS:-5972:2}; do
  R=$(S -X POST "$BASE/wp-json/wc/store/v1/cart/add-item" -d "{\"id\":${p%%:*},\"quantity\":${p##*:}}")
  note "add $p → $(echo "$R" | jq -r '.code // ("ok, items total " + .totals.total_items)')"
done

try() {
  local label="$1" addr="$2"
  local body="{\"billing_address\":$(echo "$addr" | jq -c '. + {email:"app-test@example.com"}'),\"shipping_address\":$addr,\"payment_method\":\"cod\",\"customer_note\":\"طلب تجربة من التطبيق — تجاهله\"${EXTRA:-}}"
  local R; R=$(S -X POST "$BASE/wp-json/wc/store/v1/checkout" -d "$body")
  note "checkout [$label] → $(echo "$R" | jq -r 'if .order_id then "ORDER #\(.order_id) status \(.status) payment \(.payment_method // "")" else "\(.code): \(.message)" end')"
  echo "$R" | jq -r '.data.params // empty' | head -20
  echo "$R" | jq -e '.order_id' >/dev/null 2>&1
}

BASEADDR='{"first_name":"تجربة","last_name":"التطبيق","address_1":"شارع تجربة 22","city":"الرياض","country":"SA","state":"","postcode":"","phone":"0500000123"}'
CART=$(S "$BASE/wp-json/wc/store/v1/cart")
note "payment methods: $(echo "$CART" | jq -c '.payment_methods') · shipping: $(echo "$CART" | jq -c '[.shipping_rates[].shipping_rates[] | {id:.rate_id,price,selected}]')"

# Which address fields are required? Probe with the fields the app sends, then add the usual extras.
try "app fields" "$BASEADDR" && exit 0
try "+postcode" "$(echo "$BASEADDR" | jq -c '.postcode="12345"')" && exit 0
try "+postcode+state" "$(echo "$BASEADDR" | jq -c '.postcode="12345" | .state="الرياض"')" && exit 0
try "+postcode+state+address_2" "$(echo "$BASEADDR" | jq -c '.postcode="12345" | .state="الرياض" | .address_2="حي النزهة"')" && exit 0
exit 1
