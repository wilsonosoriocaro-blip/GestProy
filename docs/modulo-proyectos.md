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

## Fase 2: CRUD de proyectos

**Pantallas** (Livewire 4, componentes de un solo archivo como el resto del kit):

| Ruta | Componente | Qué hace |
|---|---|---|
| `/projects` | `pages::projects.index` | Listado con búsqueda, filtros, orden y paginación. En móvil se ve como tarjetas |
| `/projects/create` | `pages::projects.form` | Alta de proyecto |
| `/projects/{id}` | `pages::projects.show` | Resumen ejecutivo, datos, equipo y actividad reciente |
| `/projects/{id}/edit` | `pages::projects.form` | Edición (la misma página del alta) |

El equipo se administra desde el detalle con el componente `projects.team`.

**Dónde está la lógica:**

- Componentes Blade reutilizables en `resources/views/components/projects`: badges de estado, prioridad y salud, barra de avance y tarjeta de indicador.
- Las páginas solo orquestan. La lógica está en:
  - `ProjectForm` (Form object de Livewire): validación.
  - Actions en `app/Actions/Projects`: `CreateProject`, `UpdateProject`, `ArchiveProject`, `DeleteProject`, `ManageProjectMembers`.
  - `ProjectIndexQuery`: filtros y orden, resueltos en SQL.
  - `ProjectPolicy`: permisos.

**Reglas de negocio:**

- **Código.** Si se deja vacío se genera como `TED-{año}-{consecutivo}`. Un advisory lock de PostgreSQL evita que dos altas simultáneas saquen el mismo número. El prefijo se cambia en `config/projects.php`.
- **Finalizado.** Al pasar a un estado de tipo "finalizado" se llena la fecha real (hoy, si viene vacía), y si el avance es manual queda en 100 %. Al salir de ese estado la fecha real se borra.
- **Avance por tareas.** Si el proyecto calcula su avance por tareas, lo que se escriba a mano se ignora.
- **Proyectos archivados.** Son de solo lectura hasta que se restauran.
- **Eliminar.** Es soft delete.
- **Historial.** Cada cambio relevante queda en `project_activity_logs` con usuario, IP, valores anteriores y nuevos. Un cambio de estado, de responsable, de fechas o de avance tiene su propio evento. Guardar sin cambios no registra nada.

**Filtros "atrasado" y "en riesgo".** Se resuelven en SQL para poder paginar:

- Atrasado: la fecha de fin ya pasó y el proyecto sigue abierto.
- En riesgo: estado de tipo "en riesgo", o atrasado.

El "avance bajo" requiere contar días hábiles, así que se muestra en la columna Cronograma pero no se filtra (quedará en el dashboard de la fase 4).

**Datos de ejemplo:**

```bash
php artisan db:seed --class=DemoProjectsSeeder   # usuarios lider@example.com, andres@..., contraseña: password
```

## Pendiente para fases siguientes

- Deshabilitar o controlar la eliminación de cuenta del starter kit. Un usuario responsable de proyectos no se puede borrar porque `owner_id` está en `restrictOnDelete`.
- Los enlaces "Repository" y "Documentation" del menú lateral vienen del starter kit; se pueden quitar cuando se defina la navegación final.
- Índices trigram (`pg_trgm`) para la búsqueda si el volumen lo pide (fase 9).
