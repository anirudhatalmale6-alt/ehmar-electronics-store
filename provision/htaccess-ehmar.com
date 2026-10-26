# naijadraftclub.com is an alias domain on this account: its requests are served from /naijadraftclub/
# (that folder has its own rules, so the store's WordPress never answers for it).
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP_HOST} ^(www\.)?naijadraftclub\.com$ [NC]
RewriteCond %{REQUEST_URI} !^/naijadraftclub/
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule ^(.*)$ /naijadraftclub/$1 [L]
</IfModule>

# Force HTTPS (SSL certificate is active)
<IfModule mod_rewrite.c>
RewriteEngine On
# One canonical address: https://ehmar.com (www and http both redirect there)
RewriteCond %{HTTP_HOST} !naijadraftclub\.com$ [NC]
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteCond %{HTTP_HOST} ^www\. [NC]
RewriteRule ^ https://ehmar.com%{REQUEST_URI} [L,R=301]
RewriteCond %{HTTP_HOST} !naijadraftclub\.com$ [NC]
RewriteCond %{HTTPS} !=on
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule ^ https://ehmar.com%{REQUEST_URI} [L,R=301]
</IfModule>

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
