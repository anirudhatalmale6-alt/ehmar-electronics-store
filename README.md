# Ehmar Electronics — WooCommerce store (ehmar.com)

Online store for phones, security cameras, memory cards and solar generators.

- `site/wp-content/themes/ehmar/` — custom block theme (child of Twenty Twenty-Five): header, footer, home page, shop/category pages with filters, brand styles.
- `provision/setup.php` — one-shot, re-runnable setup: activates the theme, logo, categories, brands, demo products, info pages, payments (bank transfer, pay on delivery) and the Nigeria shipping zone. Run with `wp eval-file provision/setup.php`.
- `demo-ready/` — logo and demo product photos (to be replaced with the real catalogue).

Stack: WordPress + WooCommerce 11 (no paid plugins). Payments: bank transfer, pay on delivery; Paystack / Flutterwave plugins ready for the merchant's keys.

Demo photo credits: Wikimedia Commons, CC0 / public domain, except the Anker SOLIX power station photo (CC BY-SA 4.0, credited on the page).
