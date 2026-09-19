#!/usr/bin/env bash
# One-shot setup of the wp-env store after `npm run env:start`.
# Idempotent: safe to run again.
set -euo pipefail

cli="npx wp-env run cli"

echo "==> WooCommerce onboarding (skip wizard, set store basics)"
$cli wp option update woocommerce_store_address "Tehran"
$cli wp option update woocommerce_default_country "IR:THR"
$cli wp option update woocommerce_currency "IRT"
$cli wp option update woocommerce_currency_pos "right_space"
$cli wp option update woocommerce_price_num_decimals "0"
$cli wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
$cli wp option update woocommerce_task_list_hidden "yes"
$cli wp option update woocommerce_coming_soon "no"

echo "==> Persian locale + RTL"
$cli wp language core install fa_IR --activate || true
$cli wp language plugin install woocommerce fa_IR || true
$cli wp option update timezone_string "Asia/Tehran"

echo "==> Permalinks (needed for REST + product pages)"
$cli wp rewrite structure '/%postname%/' --hard

echo "==> Make sure plugin is active"
$cli wp plugin activate woocommerce fast-woo-sell

echo "==> Seed demo data"
$cli wp fws seed --products=200 --orders=1500

echo "==> Build affinity now (instead of waiting for the scheduled job)"
$cli wp fws affinity rebuild

echo
echo "Done. Store: http://localhost:8888  Admin: http://localhost:8888/wp-admin (admin / password)"
