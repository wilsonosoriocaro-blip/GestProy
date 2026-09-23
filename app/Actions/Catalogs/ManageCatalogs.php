<?php

namespace App\Actions\Catalogs;

use App\Services\AuditLogger;
use App\Services\Catalogs\CatalogRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create, edit and delete catalog entries keeping the rules the rest of
 * the module relies on: stable slugs, one default per catalog, a status
 * kind cannot change once used, used entries are deactivated instead of
 * deleted, and the default entry stays active.
 */
class ManageCatalogs
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated fields.
     */
    public function save(string $type, ?int $id, array $data): Model
    {
        $catalog = CatalogRegistry::get($type);
        /** @var class-string<Model> $class */
        $class = $catalog['model'];

        return DB::transaction(function () use ($type, $id, $data, $catalog, $class): Model {
            $entry = $id === null ? new $class : $class::query()->findOrFail($id);
            $isNew = ! $entry->exists;

            if (! $isNew && $catalog['kinds'] !== null && ($data['kind'] ?? null) !== $entry->getAttribute('kind')?->value
                && CatalogRegistry::usage($type, (int) $entry->getKey()) > 0) {
                throw ValidationException::withMessages(['form.kind' => 'No se puede cambiar el comportamiento de un estado que ya está en uso. Crea uno nuevo.']);
            }

            $makeDefault = (bool) ($data['is_default'] ?? false);
            $isDefault = (bool) $entry->getAttribute('is_default');

            if ($catalog['default'] && $isDefault && ! $makeDefault) {
                throw ValidationException::withMessages(['form.is_default' => 'Para cambiar el valor por defecto, márcalo en otro elemento.']);
            }

            if (($isDefault || $makeDefault) && ! ($data['is_active'] ?? true)) {
                throw ValidationException::withMessages(['form.is_active' => 'El valor por defecto no se puede desactivar.']);
            }

            if ($catalog['default'] && $makeDefault && ! $isDefault) {
                // One default per catalog (partial unique index): clear the old one first.
                $class::query()->where('is_default', true)->update(['is_default' => false]);
            }

            if ($isNew) {
                $entry->setAttribute('slug', $this->uniqueSlug($class, (string) $data['name']));
            }

            $entry->fill($data);
            $old = $isNew ? null : array_intersect_key($entry->getOriginal(), $entry->getDirty());
            $new = $entry->getDirty();
            $entry->save();

            if ($isNew || $new !== []) {
                $this->audit->record($isNew ? 'catalog.created' : 'catalog.updated', $entry,
                    "{$catalog['label']}: {$entry->getAttribute('name')}", $old, $this->plain($new));
            }

            return $entry;
        });
    }

    public function delete(string $type, int $id): void
    {
        $catalog = CatalogRegistry::get($type);
        $entry = $catalog['model']::query()->findOrFail($id);

        if ($entry->getAttribute('is_default')) {
            throw ValidationException::withMessages(['catalog' => 'El valor por defecto no se puede eliminar.']);
        }

        if (CatalogRegistry::usage($type, $id) > 0) {
            throw ValidationException::withMessages(['catalog' => 'Está en uso: desactívalo en lugar de eliminarlo.']);
        }

        DB::transaction(function () use ($catalog, $entry): void {
            $this->audit->record('catalog.deleted', $entry, "{$catalog['label']}: {$entry->getAttribute('name')}", $this->plain($entry->attributesToArray()));
            $entry->delete();
        });
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function uniqueSlug(string $class, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;

        for ($i = 2; $class::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function plain(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : $value, $values);
    }
}
