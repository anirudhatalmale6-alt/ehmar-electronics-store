#!/bin/sh
# Build the upload package for shared hosting.
# Usage: provision/build-package.sh <admin_user> <admin_email> <admin_pass> <key>
# Output: deploy/ehmar-com.zip  (WordPress core + WooCommerce + payment plugins + themes + one-time installer)
set -e
ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT="$ROOT/deploy/pkg"
rm -rf "$OUT" && mkdir -p "$OUT"

# WordPress core, without anything that belongs to the local SQLite sandbox.
rsync -a --exclude 'wp-config.php' --exclude 'wp-content' "$ROOT/site/" "$OUT/"
mkdir -p "$OUT/wp-content/plugins" "$OUT/wp-content/themes" "$OUT/wp-content/ehmar-provision/provision"
cp "$ROOT/site/wp-content/index.php" "$OUT/wp-content/"
for p in woocommerce woo-paystack rave-woocommerce-payment-gateway; do
	rsync -a "$ROOT/site/wp-content/plugins/$p" "$OUT/wp-content/plugins/"
done
cp "$ROOT/site/wp-content/plugins/index.php" "$OUT/wp-content/plugins/"
for t in twentytwentyfive ehmar; do
	rsync -a "$ROOT/site/wp-content/themes/$t" "$OUT/wp-content/themes/"
done
cp "$ROOT/provision/setup.php" "$OUT/wp-content/ehmar-provision/provision/"
rsync -a "$ROOT/demo-ready" "$OUT/wp-content/ehmar-provision/"

sed -e "s|__EHMAR_ADMIN_USER__|$1|" -e "s|__EHMAR_ADMIN_EMAIL__|$2|" -e "s|__EHMAR_ADMIN_PASS__|$3|" -e "s|__EHMAR_KEY__|$4|" \
	"$ROOT/provision/ehmar-install.php" > "$OUT/ehmar-install.php"

cat > "$OUT/.htaccess" <<'HT'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HT

rm -f "$ROOT/deploy/ehmar-com.zip"
(cd "$OUT" && zip -qr "$ROOT/deploy/ehmar-com.zip" .)
ls -la "$ROOT/deploy/ehmar-com.zip"
