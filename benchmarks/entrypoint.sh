#!/usr/bin/env bash
set -euo pipefail

ITERATIONS="${1:-20}"
PORT=8321
URL="http://127.0.0.1:$PORT"
DRUSH=/opt/drupal/vendor/bin/drush
DB=/opt/drupal/db.sqlite

cd /opt/drupal

# ── Install backlit ──────────────────────────────────────────────────
echo "==> Installing backlit..."
cp -a /opt/backlit /opt/backlit-rw
composer config repositories.backlit path /opt/backlit-rw
composer require bennypowers/backlit:@dev \
  --no-interaction --no-scripts --quiet 2>/dev/null || true
composer install --no-interaction --no-scripts --quiet 2>/dev/null || true
bash /opt/backlit-rw/scripts/download-binary.sh v0.4.1

# ── Install Drupal ───────────────────────────────────────────────────
echo "==> Installing Drupal..."
$DRUSH site:install standard \
  --db-url="sqlite://localhost/$DB" \
  --site-name="Backlit Benchmark" \
  --account-name=admin --account-pass=admin \
  --yes --quiet

# Append benchmark settings.
cat /opt/bench/benchmark.settings.php >> web/sites/default/settings.php

# Disable page cache so every request hits PHP.
$DRUSH pm:uninstall page_cache --yes --quiet

# Disable HTML filtering so body fields render raw markup.
$DRUSH php:eval '
  $c = \Drupal::configFactory()->getEditable("filter.format.full_html");
  $f = $c->get("filters");
  $f["filter_html"]["status"] = false;
  $f["filter_htmlcorrector"]["status"] = false;
  $c->set("filters", $f)->save();
'

# Enable backlit and configure SSR for the page bundle.
$DRUSH en backlit --yes --quiet
$DRUSH php:eval '
  \Drupal::configFactory()->getEditable("backlit.settings")
    ->set("enabled_bundles", ["page"])
    ->save();
'

# ── Create test content ──────────────────────────────────────────────
echo "==> Creating test nodes..."
$DRUSH scr /opt/bench/create-nodes.php
$DRUSH cr --quiet

# ── Discover nodes ───────────────────────────────────────────────────
NODES=$($DRUSH php:eval '
  $nids = \Drupal::entityTypeManager()->getStorage("node")->getQuery()
    ->accessCheck(FALSE)->condition("type","page")
    ->condition("title","Benchmark:%","LIKE")->sort("nid")->execute();
  foreach (\Drupal::entityTypeManager()->getStorage("node")
    ->loadMultiple($nids) as $n) {
    $c = substr_count($n->get("body")->value, "<rh-");
    echo $n->id()."\t".$n->label()."\t".$c."\n";
  }
')
echo "$NODES" | while IFS=$'\t' read -r nid title cnt; do
  printf "  /node/%-4s %-42s ~%s elements\n" "$nid" "$title" "$cnt"
done

# ── Start PHP-FPM + nginx ────────────────────────────────────────────
# Ensure www-data can write the SQLite DB (+ journal) and Drupal files.
chown -R www-data:www-data web/sites/default 2>/dev/null || true
# SQLite needs the directory writable for journal/WAL files.
chown www-data:www-data db.sqlite /opt/drupal 2>/dev/null || true
chmod 777 /opt/drupal 2>/dev/null || true
chmod 666 db.sqlite 2>/dev/null || true

php-fpm -D
nginx

echo "==> Waiting for server..."
for _ in $(seq 1 30); do
  curl -sf "$URL/node/1" -o /dev/null 2>/dev/null && break
  sleep 0.5
done

# Sanity check.
SIZE=$(curl -sf "$URL/node/1" | wc -c)
echo "  /node/1 response: $SIZE bytes"
if [ "$SIZE" -lt 1000 ]; then
  echo "ERROR: page did not render. First 500 chars:"
  curl -sf "$URL/node/1" | head -c 500
  exit 1
fi

# ── Helpers ──────────────────────────────────────────────────────────
set_ssr() {
  local bundles='[]'
  [ "$1" = "true" ] && bundles='["page"]'
  $DRUSH php:eval "
    \\Drupal::configFactory()->getEditable('backlit.settings')
      ->set('enabled_bundles', $bundles)->save();
  " 2>/dev/null
  $DRUSH cr --quiet 2>/dev/null
}

bench_url() {
  local url=$1 n=$2
  local times=() size=0

  # Warmup.
  curl -sf "$url" -o /dev/null

  for _ in $(seq 1 "$n"); do
    local raw
    raw=$(curl -sf -o /dev/null -w '%{time_total} %{size_download}' "$url")
    local t=$(echo "$raw" | awk '{printf "%.1f", $1 * 1000}')
    size=$(echo "$raw" | awk '{print $2}')
    times+=("$t")
  done

  IFS=$'\n' sorted=($(sort -g <<<"${times[*]}")); unset IFS
  local c=${#sorted[@]}
  local p95=$(( (c * 95) / 100 ))
  [ $p95 -ge $c ] && p95=$((c - 1))

  echo "${sorted[$((c/2))]} ${sorted[$p95]} ${sorted[0]} ${sorted[$((c-1))]} $size"
}

# ── Run benchmarks ───────────────────────────────────────────────────
echo ""
echo "Backlit SSR Benchmark"
echo "====================="
echo "Iterations: $ITERATIONS"
echo "PHP: $(php -r 'echo PHP_VERSION;')"
echo ""

FMT="| %-35s | %5s | %9s | %9s | %9s | %9s | %9s | %9s |"
printf "$FMT\n" "Page" "Elems" "SSR med" "SSR p95" "Plain med" "Plain p95" "SSR KB" "Plain KB"
printf "$FMT\n" "-----------------------------------" "-----" "---------" "---------" "---------" "---------" "---------" "---------"

while IFS=$'\t' read -r nid title elcount; do
  url="$URL/node/$nid"

  set_ssr true
  curl -sf "$url" -o /dev/null; sleep 0.2
  read -r sm sp95 smin smax ssize <<< "$(bench_url "$url" "$ITERATIONS")"

  set_ssr false
  curl -sf "$url" -o /dev/null; sleep 0.2
  read -r pm pp95 pmin pmax psize <<< "$(bench_url "$url" "$ITERATIONS")"

  printf "$FMT\n" \
    "$title" "$elcount" \
    "${sm}ms" "${sp95}ms" "${pm}ms" "${pp95}ms" \
    "$(echo "scale=0; $ssize / 1024" | bc)" \
    "$(echo "scale=0; $psize / 1024" | bc)"
done <<< "$NODES"

echo ""
echo "SSR  = Backlit processes response through lit-ssr binary"
echo "Plain = Backlit installed, bundle not enabled (subscriber skips)"
echo "Page cache disabled; every request hits PHP-FPM"
echo "PHP-FPM + nginx; binary process persists across requests"
echo "Warm renders only (1 warmup excluded per config change)"
