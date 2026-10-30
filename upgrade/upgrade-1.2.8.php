<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Correct only the module-owned, unpaid awaiting/expired order states.
 * Historical orders and sales counters require a separate reconciliation.
 */
function upgrade_module_1_2_8($module)
{
    if (!$module || $module->name !== 'simpay') {
        return false;
    }

    $db = Db::getInstance();
    $transactionStarted = false;

    try {
        if (!$db->execute('START TRANSACTION')) {
            throw new RuntimeException('Cannot start order-state migration');
        }
        $transactionStarted = true;

        // Read global, group and shop overrides, not just the current context.
        $rows = $db->executeS(
            "SELECT name, value FROM `" . _DB_PREFIX_ . "configuration` "
            . "WHERE name IN ('PS_OS_SIMPAY_AWAITING', 'PS_OS_SIMPAY_EXPIRED')",
            true,
            false,
        );
        if (!is_array($rows)) {
            throw new RuntimeException('Cannot read SimPay order-state configuration');
        }

        $seen = [];
        $roles = [];
        foreach ($rows as $row) {
            $value = (string) $row['value'];
            if (!ctype_digit($value) || (int) $value <= 0) {
                throw new RuntimeException('Invalid SimPay order-state ID');
            }
            $id = (int) $value;
            if (isset($roles[$id]) && $roles[$id] !== $row['name']) {
                throw new RuntimeException('Awaiting and expired states share an ID');
            }
            $roles[$id] = $row['name'];
            $seen[$row['name']] = true;
        }
        foreach ([Simpay::CONFIG_OS_AWAITING, Simpay::CONFIG_OS_EXPIRED] as $key) {
            if (!isset($seen[$key])) {
                throw new RuntimeException('Missing SimPay order-state configuration');
            }
        }

        // Validate and lock every target before performing the first write.
        $states = [];
        foreach (array_keys($roles) as $id) {
            $stateRows = $db->executeS(
                'SELECT id_order_state, module_name, paid, deleted, logable FROM `'
                . _DB_PREFIX_ . 'order_state` WHERE id_order_state = ' . (int) $id
                . ' FOR UPDATE',
                true,
                false,
            );
            $state = is_array($stateRows) && count($stateRows) === 1 ? $stateRows[0] : false;
            if (!$state || $state['module_name'] !== 'simpay'
                || (int) $state['paid'] !== 0 || (int) $state['deleted'] !== 0) {
                throw new RuntimeException('SimPay order-state mapping requires manual review');
            }
            $states[$id] = $state;
        }

        foreach ($states as $id => $state) {
            if ((int) $state['logable'] === 0) {
                continue;
            }
            if (!$db->update('order_state', ['logable' => 0], 'id_order_state = ' . (int) $id)) {
                throw new RuntimeException('Cannot update SimPay order-state flag');
            }
        }
        if (!$db->execute('COMMIT')) {
            throw new RuntimeException('Cannot commit SimPay order-state migration');
        }

        return true;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $db->execute('ROLLBACK');
        }
        PrestaShopLogger::addLog('[simpay][upgrade] ' . $e->getMessage(), 3);

        return false;
    }
}
