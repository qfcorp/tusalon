#!/usr/bin/env bash
# Instalador de TuSalón (Ubuntu con PHP 8.5, PostgreSQL, nginx y túnel de Cloudflare).
# Uso (como el usuario normal, NO como root):
#   curl -fsSL https://raw.githubusercontent.com/qfcorp/tusalon/main/deploy/instalar.sh -o ~/instalar_tusalon.sh && bash ~/instalar_tusalon.sh
# Se puede correr varias veces: si ya hay salones guardados, NO borra la base.
set -u

APP="${APP:-/var/www/tusalon}"
DOMINIO="${DOMINIO:-tusalon.qfradioec.com}"
REPO="${REPO:-https://github.com/qfcorp/tusalon.git}"
PHPV="${PHPV:-8.5}"
YO="$(id -un)"
cd /tmp

echo "== 1. Programas de PHP"
sudo apt-get install -y "php$PHPV-fpm" "php$PHPV-pgsql" "php$PHPV-gd" "php$PHPV-curl" "php$PHPV-mbstring" "php$PHPV-intl" < /dev/null > /dev/null \
  && echo "   listo" || echo "   AVISO: no se pudieron instalar los programas de PHP"

echo "== 2. Bajar o actualizar TuSalón"
if [ -d "$APP/.git" ]; then
  sudo chown -R "$YO":www-data "$APP"
  git -C "$APP" pull -q && echo "   actualizado"
else
  sudo git clone -q "$REPO" "$APP" && echo "   bajado"
fi
sudo chown -R "$YO":www-data "$APP"
sudo mkdir -p "$APP/uploads/fotos" "$APP/config"
sudo chown -R www-data:www-data "$APP/uploads" && sudo chmod 750 "$APP/uploads"
sudo chown "$YO":www-data "$APP/config"

echo "== 3. Base de datos"
CLAVE="$(openssl rand -hex 16)"
sudo -u postgres psql -qc "CREATE ROLE tusalon LOGIN" < /dev/null 2>/dev/null
sudo -u postgres psql -qc "ALTER ROLE tusalon LOGIN PASSWORD '$CLAVE'" < /dev/null
EXISTE="$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='tusalon'" < /dev/null)"
SALONES=0
if [ "$EXISTE" = "1" ]; then
  SALONES="$(sudo -u postgres psql -d tusalon -tAc "SELECT count(*) FROM salones" < /dev/null 2>/dev/null || echo 0)"
  SALONES="${SALONES:-0}"
fi
if [ "$SALONES" -gt 0 ] 2>/dev/null; then
  echo "   ya hay $SALONES salones guardados: la base NO se toca"
else
  sudo -u postgres dropdb --if-exists tusalon < /dev/null
  sudo -u postgres createdb -O tusalon tusalon < /dev/null
  PGPASSWORD="$CLAVE" psql -h 127.0.0.1 -U tusalon -d tusalon -q -v ON_ERROR_STOP=1 -f "$APP/db/schema.sql" < /dev/null \
    && echo "   base creada" || echo "   ERROR al crear las tablas"
fi

echo "== 4. Configuración"
TG=""
if [ -f "$APP/config/.env" ]; then   # conservar los datos de Telegram si ya estaban
  TG="$(grep -E '^TELEGRAM_' "$APP/config/.env" || true)"
fi
{
  echo "TUSALON_DB_HOST=127.0.0.1"
  echo "TUSALON_DB_NAME=tusalon"
  echo "TUSALON_DB_USER=tusalon"
  echo "TUSALON_DB_PASS=$CLAVE"
  echo "TUSALON_URL=https://$DOMINIO"
  [ -n "$TG" ] && echo "$TG"
} > "$APP/config/.env"
chmod 640 "$APP/config/.env" && sudo chgrp www-data "$APP/config/.env" && echo "   listo"

echo "== 5. nginx"
if command -v nginx > /dev/null; then
  sudo tee /etc/nginx/sites-available/tusalon > /dev/null <<EOF
server {
    listen 80;
    server_name $DOMINIO;
    root $APP/public;
    index index.php;
    client_max_body_size 12M;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }
    location /assets/ {
        expires 7d;
        try_files \$uri =404;
    }
    location ~ /\. { deny all; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php$PHPV-fpm.sock;
    }
}
EOF
  sudo ln -sf /etc/nginx/sites-available/tusalon /etc/nginx/sites-enabled/tusalon
  sudo nginx -t -q && sudo systemctl reload nginx && sudo systemctl restart "php$PHPV-fpm" && echo "   listo"
else
  echo "   (nginx no está instalado: se omite)"
fi

echo "== 6. Tareas automáticas (cada hora) y respaldo (cada día 3:15)"
(crontab -l 2>/dev/null | grep -v "tusalon/bin/tareas"; echo "5 * * * * php $APP/bin/tareas.php >> \$HOME/tusalon-tareas.log 2>&1") | crontab -
(sudo crontab -l 2>/dev/null | grep -v "tusalon/deploy/respaldo"; echo "15 3 * * * $APP/deploy/respaldo.sh >> /var/log/tusalon-respaldo.log 2>&1") | sudo crontab -
echo "   listo"

echo
echo "=========== RESULTADO ==========="
[ -f "$APP/config/.env" ] && echo "1) Configuración: OK" || echo "1) Configuración: FALTA"
echo "2) Tablas en la base: $(PGPASSWORD="$CLAVE" psql -h 127.0.0.1 -U tusalon -d tusalon -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'" < /dev/null) (deben ser más de 25)"
echo "3) Extensiones de PHP: $(php -m | grep -E -c '^(pdo_pgsql|gd|curl|mbstring)$') (deben ser 4)"
if command -v nginx > /dev/null; then
  curl -s -o /dev/null -w "4) Prueba de la página: %{http_code} (debe ser 200)\n" -H "Host: $DOMINIO" "http://127.0.0.1/?r=login"
fi
echo "5) Tareas: $(php "$APP/bin/tareas.php" < /dev/null)"
echo "6) Respaldo: $(sudo APP="$APP" "$APP/deploy/respaldo.sh" < /dev/null 2>&1 | tail -1)"
echo "================================="
