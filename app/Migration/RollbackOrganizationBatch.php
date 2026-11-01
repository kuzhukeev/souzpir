<?php

namespace App\Migration;

use Illuminate\Support\Facades\DB;
use LogicException;

final class RollbackOrganizationBatch
{
    public function rollback(string $batchId): void
    {
        (new ImportPreparedOrganization)->assertTestDatabase();
        DB::transaction(function () use ($batchId): void {
            $batch = DB::table('migration_organization_batches')->where('id', $batchId)->lockForUpdate()->first();
            if (! $batch) {
                throw new LogicException('Batch не найден.');
            }
            $snapshot = json_decode($batch->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $organization = DB::table('organizations')->where('id', $batch->organization_id)->first();
            $brands = DB::table('brands')->where('organization_id', $batch->organization_id)->orderBy('id')->get();
            if (json_decode(json_encode(['organization' => $organization, 'brands' => $brands], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) != $snapshot) {
                throw new LogicException('Конфликт: импортированные записи изменены.');
            }
            $this->assertUnused('organizations', (array) $organization, true);
            foreach ($brands as $brand) {
                $this->assertUnused('brands', (array) $brand, false);
            }
            DB::table('brands')->where('organization_id', $batch->organization_id)->delete();
            DB::table('organizations')->where('id', $batch->organization_id)->delete();
            DB::table('migration_organization_batches')->where('id', $batchId)->delete();
        });
    }

    private function assertUnused(string $target, array $row, bool $skipBrands): void
    {
        $references = DB::select(<<<'SQL'
            SELECT c.oid AS constraint_id, ns.nspname AS source_schema, src.relname AS source_table,
                   sa.attname AS source_column, ta.attname AS target_column, k.ordinality AS position
            FROM pg_constraint c
            JOIN pg_class src ON src.oid = c.conrelid
            JOIN pg_namespace ns ON ns.oid = src.relnamespace
            JOIN pg_class target ON target.oid = c.confrelid
            JOIN pg_namespace tns ON tns.oid = target.relnamespace
            JOIN LATERAL unnest(c.conkey, c.confkey) WITH ORDINALITY AS k(source_att, target_att, ordinality) ON true
            JOIN pg_attribute sa ON sa.attrelid = src.oid AND sa.attnum = k.source_att
            JOIN pg_attribute ta ON ta.attrelid = target.oid AND ta.attnum = k.target_att
            WHERE c.contype = 'f' AND tns.nspname = current_schema() AND target.relname = ?
            ORDER BY c.oid, k.ordinality
        SQL, [$target]);
        foreach (collect($references)->groupBy('constraint_id') as $columns) {
            $first = $columns->first();
            if ($skipBrands && $first->source_schema === 'public' && $first->source_table === 'brands') {
                continue;
            }
            $query = DB::table($first->source_schema.'.'.$first->source_table);
            foreach ($columns as $column) {
                $query->where($column->source_column, $row[$column->target_column]);
            }
            if ($query->exists()) {
                throw new LogicException('Конфликт: импортированные записи используются.');
            }
        }
    }
}
