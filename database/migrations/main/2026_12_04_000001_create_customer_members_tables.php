<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'main';

    /**
     * customer_users: who is in which customer (their roles stay in that customer's user_roles);
     * theme_meta_id is the theme they picked there, a meta of that customer.
     * customer_invites: codes a customer's admin hands out, with role ids of that customer.
     * customer_domains: a website's own domain → the customer it belongs to.
     * personal_access_tokens.customer_id: the customer a login works in.
     * users.is_platform_owner: sees every customer.
     */
    public function up(): void
    {
        $schema = Schema::connection('main');

        $schema->create('customer_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('invite_id')->nullable();
            $table->unsignedBigInteger('theme_meta_id')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->unique(['customer_id', 'user_id']);
        });

        $schema->create('customer_invites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('code', 8)->unique();
            $table->json('role_ids');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        $schema->create('customer_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->unsignedBigInteger('customer_id')->index();
        });

        $schema->table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->index();
        });
        $schema->table('users', function (Blueprint $table) {
            $table->boolean('is_platform_owner')->default(false);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('main');
        $schema->table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_owner'));
        $schema->table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropColumn('customer_id');
        });
        $schema->dropIfExists('customer_domains');
        $schema->dropIfExists('customer_invites');
        $schema->dropIfExists('customer_users');
    }
};
