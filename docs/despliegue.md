# Despliegue en producción (Linux + Nginx + PostgreSQL)

Guía para un servidor Ubuntu 24.04 o Debian 12. Ajusta rutas y nombres a tu infraestructura.

## 1. Requisitos del servidor

| Componente | Versión |
|---|---|
| PHP (FPM) | 8.3 o superior, con `pdo_pgsql`, `intl`, `mbstring`, `xml`, `curl`, `zip`, `bcmath` |
| Composer | 2 |
| Node.js | 22, solo para compilar los assets (puede ser en la máquina de CI) |
| PostgreSQL | 14 o superior |
| Nginx | Cualquier versión estable |
| Supervisor | Para el worker de colas |

## 2. Base de datos

```bash
sudo -u postgres psql -c "CREATE ROLE gestproy LOGIN PASSWORD 'CAMBIAR-POR-UNA-CLAVE-LARGA';"
sudo -u postgres psql -c "CREATE DATABASE gestproy OWNER gestproy;"
```

## 3. Código y dependencias

```bash
sudo mkdir -p /var/www/gestproy && sudo chown $USER:www-data /var/www/gestproy
git clone <repositorio> /var/www/gestproy && cd /var/www/gestproy

composer install --no-dev --optimize-autoloader
npm ci && npm run build          # o copiar public/build desde el CI
```

## 4. Configuración (`.env`)

```bash
cp .env.example .env && php artisan key:generate
```

Valores clave en producción:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://proyectos.tuempresa.com
APP_TIMEZONE=America/Bogota

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=gestproy
DB_USERNAME=gestproy
DB_PASSWORD=...

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true       # solo cookies por HTTPS
SESSION_ENCRYPT=true

QUEUE_CONNECTION=database
CACHE_STORE=database             # o redis si está disponible

MAIL_MAILER=smtp                 # Office 365: smtp.office365.com:587 con STARTTLS
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USERNAME=notificaciones@tuempresa.com
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=notificaciones@tuempresa.com
MAIL_FROM_NAME="Gestión de Proyectos TED"

PROJECTS_NOTIFICATION_CHANNELS=database,mail
PROJECTS_TEAMS_WEBHOOK_URL=      # opcional: webhook entrante de un canal de Teams
PROJECTS_DIGEST_TIME=07:00
```

## 5. Primera instalación

```bash
php artisan migrate --force
php artisan db:seed --force                  # roles, permisos y catálogos (en producción no crea usuarios)
php artisan projects:create-admin            # primer administrador: recibe un enlace para definir su contraseña
php artisan storage:link
php artisan optimize                         # cachea config, rutas, eventos y vistas
```

`projects:create-admin --with-password` pide la contraseña en la consola en vez de enviar el enlace. Sirve cuando el correo todavía no está configurado.

Permisos de escritura para PHP-FPM:

```bash
sudo chown -R $USER:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

## 6. Nginx

`/etc/nginx/sites-available/gestproy`:

```nginx
server {
    listen 80;
    server_name proyectos.tuempresa.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name proyectos.tuempresa.com;
    root /var/www/gestproy/public;
    index index.php;

    ssl_certificate     /etc/ssl/certs/proyectos.crt;
    ssl_certificate_key /etc/ssl/private/proyectos.key;

    client_max_body_size 20m;
    charset utf-8;

    # La aplicación ya envía X-Content-Type-Options, X-Frame-Options,
    # Referrer-Policy, Permissions-Policy y HSTS (middleware SecurityHeaders).
    server_tokens off;

    gzip on;
    gzip_types text/plain text/css application/javascript application/json image/svg+xml;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Assets compilados por Vite: nombres con hash, se pueden cachear un año.
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/gestproy /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 7. Worker de colas (Supervisor)

Las notificaciones se envían en cola. `/etc/supervisor/conf.d/gestproy-worker.conf`:

```ini
[program:gestproy-worker]
command=php /var/www/gestproy/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/gestproy/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start gestproy-worker
```

## 8. Tareas programadas (cron)

El resumen diario de vencimientos corre de lunes a viernes a las 07:00 y omite los festivos. Solo necesita esta línea en el cron de `www-data` (`sudo crontab -u www-data -e`):

```cron
* * * * * cd /var/www/gestproy && php artisan schedule:run >> /dev/null 2>&1
```

## 9. Actualizaciones

```bash
cd /var/www/gestproy
php artisan down --refresh=15
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # sincroniza permisos si cambiaron
php artisan optimize
php artisan queue:restart                                       # el worker toma el código nuevo
php artisan up
```

## 10. Respaldos

```bash
# Diario, con retención de 30 días (cron de postgres)
0 2 * * * pg_dump -Fc gestproy > /var/backups/gestproy/gestproy-$(date +\%F).dump && find /var/backups/gestproy -mtime +30 -delete
```

Restaurar: `pg_restore -d gestproy --clean gestproy-AAAA-MM-DD.dump`.

## 11. Verificación después de desplegar

- `php artisan about`: entorno `production`, debug apagado, caches activas.
- Entrar con el administrador y revisar Tablero, Proyectos y Administración.
- `php artisan projects:send-alerts --force`: debe responder "Resúmenes enviados: N".
- `sudo supervisorctl status gestproy-worker`: debe estar `RUNNING`.
- Revisar `storage/logs/laravel.log`.

## 12. Rendimiento

Medido con 2.000 proyectos, 40.000 tareas y 100.000 entradas de historial. Las consultas más pesadas (tablero, carga de trabajo, resumen diario) corren en menos de 25 ms, y la búsqueda sin coincidencias en unos 7 ms.

Cada pantalla ejecuta un número fijo de consultas, sin importar el tamaño del portafolio. El test `QueryCountTest` lo vigila.

Si el portafolio llegara a decenas de miles de proyectos activos, el siguiente paso sería un índice trigram (`pg_trgm`) sobre una columna de búsqueda combinada. Con el volumen actual no aporta.
