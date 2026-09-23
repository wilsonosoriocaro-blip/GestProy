# GestProy · Gestión de Proyectos de Tecnología y Estrategias Digitales

Aplicación para administrar, planificar y hacer seguimiento a los proyectos del área de Tecnología y Estrategias Digitales: proyectos, tareas, responsables, fechas en días hábiles de Colombia, avance, riesgos, indicadores, cronograma, bitácora, historial y notificaciones.

**Stack:** Laravel 13 · Livewire 4 · PHP 8.3+ · PostgreSQL · Tailwind CSS 4 · Flux UI · spatie/laravel-permission.

## Puesta en marcha local

```bash
composer install
cp .env.example .env            # ajustar DB_PASSWORD
php artisan key:generate
php artisan migrate --seed      # roles, catálogos y admin@example.com / password (solo fuera de producción)
php artisan db:seed --class=DemoProjectsSeeder   # datos de ejemplo (opcional)
npm install && npm run build
composer dev                    # servidor, worker de colas, logs y Vite
```

Usuarios de la demo (contraseña `password`): `lider@example.com`, `andres@example.com`, `camila@example.com`, `admin@example.com`.

## Pruebas

```bash
composer test    # Pint + PHPStan (nivel 7) + PHPUnit contra PostgreSQL (base gestproy_testing)
```

## Documentación

- [Módulo de proyectos](docs/modulo-proyectos.md): arquitectura, reglas de negocio y decisiones de cada fase.
- [Despliegue en producción](docs/despliegue.md): Nginx, PHP-FPM, PostgreSQL, colas, cron, respaldos y actualizaciones.
