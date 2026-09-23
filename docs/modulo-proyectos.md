# Módulo de Gestión de Proyectos TED

Módulo de administración y seguimiento de proyectos del área de Tecnología y Estrategias Digitales.

## Puesta en marcha

```bash
composer install
cp .env.example .env          # ajustar DB_PASSWORD
php artisan key:generate
php artisan migrate --seed    # catálogos, roles y usuario admin@example.com / password
npm install && npm run build
php artisan test              # usa la base gestproy_testing (PostgreSQL)
```

El registro público está deshabilitado. El primer usuario lo crea el seeder y se debe cambiar su contraseña.

## Decisiones de la fase 1

**Calendario.** Todas las métricas de tiempo se cuentan en días hábiles de Colombia (`App\Support\Calendar`). Los festivos se calculan (Ley Emiliani más los festivos que dependen de la Pascua), así que no hay tabla que mantener cada año. Los días no laborables extra se agregan en `config/business_calendar.php`. Zona horaria: `America/Bogota`.

**Estados configurables.** `project_statuses` y `project_task_statuses` son tablas separadas: así una llave foránea impide que un proyecto quede con un estado de tarea. Cada estado tiene un `kind` fijo en código (`ProjectStatusKind`, `TaskStatusKind`) que se mapea a un `Lifecycle` genérico. La lógica solo mira el `kind`, así que se pueden crear estados nuevos desde el catálogo sin tocar código.

**Salud del cronograma.** `ScheduleCalculator` es puro, no consulta la base. Recibe fechas, avance y lifecycle, y devuelve un `ScheduleSnapshot`: días totales, transcurridos, restantes y de retraso, % de tiempo consumido, avance esperado vs real y un `ScheduleHealth` con etiqueta e icono (no depende solo del color). No se guarda en la base, se calcula al leer.

- El día de hoy cuenta como restante, no como transcurrido.
- Si la fecha de inicio ya pasó y el avance está más de 15 puntos por debajo del esperado, queda como "Avance bajo".
- Si faltan 5 días hábiles o menos para el vencimiento, queda como "Próximo a vencer".
- Los dos umbrales se configuran en `config/business_calendar.php`.
- Sin fecha de vencimiento las métricas quedan en `null` (no en 0).

**"En riesgo".** Hay dos niveles. El estado "En riesgo" lo declara una persona; la salud se calcula. Los dashboards van a usar las dos cosas.

**Avance.** `projects.progress_mode` define una sola fuente de verdad:

- `tasks`: promedio ponderado por `weight` de las tareas de primer nivel. Las finalizadas cuentan como 100 % y las canceladas no cuentan.
- `manual`: lo escribe el responsable o el líder.

`ProjectProgressCalculator::sync()` nunca pisa un avance manual.

**Responsable y equipo.** El responsable está en `projects.owner_id` y el equipo en `project_members` (rol `member` u `observer`). No se duplica.

**Subtareas y dependencias.** `project_tasks.parent_id` soporta subtareas desde ya. `project_task_dependencies` guarda predecesoras con tipo (FS por defecto).

**Historial y auditoría.** Una sola tabla `project_activity_logs`, append-only, con evento, usuario, sujeto polimórfico y `old_values` / `new_values` en jsonb. Sirve para la bitácora del proyecto y para la auditoría.

**Integridad.** Constraints de PostgreSQL para avance 0 a 100, fechas coherentes, peso positivo, dependencia consigo misma, roles y un único valor por defecto por catálogo. Los catálogos en uso no se pueden borrar (`restrictOnDelete`), se desactivan.

**Permisos.** spatie/laravel-permission. Los enums `Role` y `Permission` son la fuente de verdad y `RolesAndPermissionsSeeder` los sincroniza en cada despliegue. Las reglas por registro (el responsable edita su proyecto, el integrante actualiza sus tareas) irán en policies.

| Rol | Alcance |
|---|---|
| admin | Todo |
| leader | Ve y edita todos los proyectos, catálogos, dashboard y auditoría |
| project_manager | Crea proyectos y gestiona los suyos |
| member | Ve sus proyectos y actualiza sus tareas |
| viewer | Consulta todos los proyectos, sin editar |

## Pendiente para fases siguientes

- Deshabilitar o controlar la eliminación de cuenta del starter kit. Un usuario responsable de proyectos no se puede borrar porque `owner_id` está en `restrictOnDelete`.
- Índices trigram (`pg_trgm`) para la búsqueda si el volumen lo pide (fase 9).
