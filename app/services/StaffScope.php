<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

/**
 * Data scoping for staff: account managers/assistants only see customers assigned to
 * them unless they hold customers.view_all.
 */
final class StaffScope
{
    public static function seesAll(): bool
    {
        return Auth::can('customers.view_all');
    }

    /** SQL fragment restricting a customer id column to the current staff member's scope. */
    public static function customerFilter(string $column): array
    {
        if (self::seesAll()) {
            return ['1=1', []];
        }
        return ["$column IN (SELECT customer_id FROM account_managers WHERE manager_id = ?)", [Auth::id()]];
    }

    public static function canAccessCustomer(int $customerId): bool
    {
        return self::seesAll() || (bool) Db::value(
            'SELECT 1 FROM account_managers WHERE customer_id = ? AND manager_id = ?',
            [$customerId, Auth::id()]
        );
    }

    public static function canAccessAccount(array $account): bool
    {
        if ($account['is_system']) {
            return self::seesAll();
        }
        return self::canAccessCustomer((int) $account['customer_id']);
    }
}
