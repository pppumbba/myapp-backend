<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the pos tag and tags the meta pos_sales_lookup with it, ahead of 2026_12_03_000024.
 *
 * 000024 keeps that one meta under two keys and tags every meta it made that is not tagged yet,
 * so left alone it would tag this one twice and break the unique index of meta_tag_map. Already
 * tagged, it is skipped. 000024 fills the meta in; matched by code, it keeps this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $type = DB::table('meta_types')->where('code', 'datagrid')->value('id');
        if (! $type) {
            return;
        }

        DB::transaction(function () use ($type) {
            $now = now();
            $tag = DB::table('tags')->where('name', 'pos')->value('id')
                ?? DB::table('tags')->insertGetId(['name' => 'pos', 'color' => 'purple', 'created_at' => $now, 'updated_at' => $now]);
            $meta = DB::table('metas')->where('code', 'pos_sales_lookup')->value('id')
                ?? DB::table('metas')->insertGetId(['code' => 'pos_sales_lookup', 'name' => 'Receipts (pick list)', 'type_id' => $type, 'table_name' => 'pos_sales', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            if (! DB::table('meta_tag_map')->where('meta_id', $meta)->where('tag_id', $tag)->exists()) {
                DB::table('meta_tag_map')->insert(['meta_id' => $meta, 'tag_id' => $tag, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
    }

    /** 2026_12_03_000024 takes the meta and the tag away with the rest. */
    public function down(): void {}
};
