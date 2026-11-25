<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where an app's picture (its brand) is stored: the file's path under storage/app, e.g.
 * app-images/160-….png (AppImageController). Pictures kept as json_config.app_image move here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->string('image_path', 255)->nullable()->after('table_name');
        });

        DB::table('metas')->whereNotNull('json_config')->orderBy('id')->each(function (object $meta) {
            $config = json_decode((string) $meta->json_config, true);
            if (! is_array($config) || ! array_key_exists('app_image', $config)) {
                return;
            }
            $file = $config['app_image'];
            unset($config['app_image']);
            DB::table('metas')->where('id', $meta->id)->update([
                'image_path' => is_string($file) && $file !== '' ? 'app-images/'.$file : null,
                'json_config' => json_encode($config),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
