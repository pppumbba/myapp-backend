<?php

namespace App\Support;

use App\Models\Customer;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The customer a request (or job, or command) works in. use() points the "customer" connection at
 * that customer's schema, gives the cache a per-customer prefix and files a per-customer folder,
 * so nothing of one customer is read under another. On SQLite (tests) both connections are one database and only the rest
 * switches.
 */
class CustomerSchema
{
    private static ?Customer $current = null;

    private static ?string $baseCachePrefix = null;

    public static function use(Customer $customer): void
    {
        self::$baseCachePrefix ??= (string) config('cache.prefix');

        if (config('database.connections.customer.driver') !== 'sqlite'
            && config('database.connections.customer.database') !== $customer->schema_name) {
            config(['database.connections.customer.database' => $customer->schema_name]);
            DB::purge('customer');
        }

        config(['cache.prefix' => self::$baseCachePrefix.'c'.$customer->id.'_']);
        Cache::forgetDriver(array_keys(config('cache.stores')));

        // Files: the first customer keeps the root it always had, the others a folder of their own.
        $root = storage_path('app/private');
        if ($customer->code !== config('customers.first_code')) {
            $root .= DIRECTORY_SEPARATOR.($customer->files_folder ?? 'customers'.DIRECTORY_SEPARATOR.$customer->id);
        }
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');

        self::$current = $customer;
    }

    public static function current(): ?Customer
    {
        return self::$current;
    }

    /** Runs $work inside $customer, then goes back to the customer before (also when it throws). */
    public static function run(Customer $customer, Closure $work): mixed
    {
        $before = self::$current;
        self::use($customer);
        try {
            return $work();
        } finally {
            $before ? self::use($before) : self::forget();
        }
    }

    /** No customer: the cache goes back to the plain prefix. */
    public static function forget(): void
    {
        if (self::$baseCachePrefix !== null) {
            config(['cache.prefix' => self::$baseCachePrefix]);
            Cache::forgetDriver(array_keys(config('cache.stores')));
        }
        config(['filesystems.disks.local.root' => storage_path('app/private')]);
        Storage::forgetDisk('local');
        self::$current = null;
    }
}
