<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerInvite;
use App\Models\CustomerUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Customers a person is in, and what happens when one is made, joined or left. */
class Customers
{
    /**
     * The person's customers as the app lists them; is_admin is looked up in each one's schema.
     *
     * @return list<array{id: int, code: string, name: string, is_admin: bool}>
     */
    public static function listFor(User $user): array
    {
        return $user->customers()->get()->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'is_admin' => CustomerSchema::run($customer, fn () => $user->isAdmin()),
        ])->all();
    }

    /**
     * A new customer with its own schema, built from the core structure (no metas, no data), and
     * $owner as its admin. Anything that fails takes the schema and the rows with it.
     */
    public static function create(User $owner, string $name): Customer
    {
        $real = self::realSchemas();
        $customer = null;
        $sql = '';
        $schema = config('customers.schema_prefix').Str::lower(Str::random(10));
        try {
            if ($real) {
                $sql = (string) file_get_contents(config('customers.core_file'));
                DB::connection('main')->statement("CREATE DATABASE `{$schema}`");
            }
            $customer = Customer::create(['code' => Customer::makeCode($name), 'name' => $name, 'schema_name' => $schema, 'created_by' => $owner->id]);
            CustomerUser::create(['customer_id' => $customer->id, 'user_id' => $owner->id, 'joined_at' => now()]);

            CustomerSchema::run($customer, function () use ($real, $sql, $customer, $owner) {
                if ($real) {
                    CoreStructure::load($sql);
                    self::createUsersView($customer);
                }
                $admin = Role::firstOrCreate(['code' => Role::ADMIN], ['name' => 'Administrator']);
                $owner->roles()->syncWithoutDetaching([$admin->id]);
                $owner->forgetRoles();
            });

            return $customer;
        } catch (Throwable $e) {
            if ($customer) {
                CustomerUser::where('customer_id', $customer->id)->delete();
                $customer->delete();
            }
            if ($real) {
                DB::connection('main')->statement("DROP DATABASE IF EXISTS `{$schema}`");
            }
            throw $e;
        }
    }

    /** A new login token for $user that works in $customer. */
    public static function bindToken(User $user, Customer $customer): string
    {
        $token = $user->createToken('api');
        $token->accessToken->forceFill(['customer_id' => $customer->id])->save();

        return $token->plainTextToken;
    }

    /**
     * users inside a customer's schema: a view of the main users who are in that customer, without
     * passwords or tokens, so the customer's SQL can keep joining users.
     */
    public static function createUsersView(Customer $customer): void
    {
        $main = config('database.connections.main.database');
        DB::connection('customer')->statement("CREATE OR REPLACE VIEW `users` AS
            SELECT u.`id`, u.`name`, u.`email`, u.`theme_mode`, u.`ui_size`, u.`locale`, u.`created_at`, u.`updated_at`
            FROM `{$main}`.`users` u
            JOIN `{$main}`.`customer_users` cu ON cu.`user_id` = u.`id` AND cu.`customer_id` = ".(int) $customer->id);
    }

    /** Separate schemas exist on MySQL and MariaDB; SQLite (tests) keeps one database. */
    private static function realSchemas(): bool
    {
        return in_array(config('database.connections.customer.driver'), ['mysql', 'mariadb'], true);
    }

    /** Letters and digits of invite codes: none that look alike (0/O, 1/I/L). */
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** How a typed code is looked up: upper case, without spaces or dashes. */
    public static function normalizeCode(string $typed): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $typed));
    }

    public static function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (CustomerInvite::where('code', $code)->exists());

        return $code;
    }

    /**
     * $user joins the customer of the invite $typed, with the invite's roles (those that still
     * exist). Already in it: nothing changes and no use is counted.
     */
    public static function join(User $user, string $typed): Customer
    {
        return DB::connection('main')->transaction(function () use ($user, $typed) {
            $invite = CustomerInvite::where('code', self::normalizeCode($typed))->lockForUpdate()->first();
            if (! $invite || ! $invite->isUsable()) {
                throw ValidationException::withMessages(['code' => __('This code is not valid.')])->status(422);
            }
            $customer = Customer::findOrFail($invite->customer_id);
            if ($user->isIn($customer)) {
                return $customer;
            }

            CustomerUser::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'invite_id' => $invite->id, 'joined_at' => now()]);
            CustomerSchema::run($customer, function () use ($user, $invite) {
                $roleIds = Role::whereIn('id', $invite->role_ids ?? [])->pluck('id')->all();
                $user->roles()->syncWithoutDetaching($roleIds);
                $user->forgetRoles();
            });
            $invite->increment('uses');

            return $customer;
        });
    }
}
