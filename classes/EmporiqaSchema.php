<?php
/**
 * The module's database schema, and the one routine that brings any
 * database up to it.
 *
 * Install and every upgrade script call ensure(). It creates each missing
 * table, then adds each missing column and secondary index, so a table left
 * behind by an older version (an uninstall that kept it, a failed upgrade)
 * ends up with the columns this version reads and writes. Uninstall drops
 * every table listed here (dropAll).
 *
 * Loaded on PHP 7 too (uninstall of a module left behind by a PHP
 * downgrade), so this file keeps to PHP 7.2 syntax.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaSchema
{
    /**
     * Table name (no prefix) => columns in order, primary key, secondary indexes.
     *
     * @return array<string, array{columns: array<string, string>, primary: string[], indexes: array<string, string[]>}>
     */
    public static function tables()
    {
        return [
            'emporiqa_order_session' => [
                'columns' => [
                    'id_order' => 'INT(10) UNSIGNED NOT NULL',
                    'emporiqa_sid' => 'VARCHAR(128) NOT NULL',
                    'date_add' => 'DATETIME NOT NULL',
                ],
                'primary' => ['id_order'],
                'indexes' => [],
            ],
            'emporiqa_order_tracked' => [
                'columns' => [
                    'id_order' => 'INT(10) UNSIGNED NOT NULL',
                    'date_add' => 'DATETIME NOT NULL',
                ],
                'primary' => ['id_order'],
                'indexes' => [],
            ],
            // One-click connect: PKCE verifier keyed by sha256(state).
            'emporiqa_connect_nonce' => [
                'columns' => [
                    'state_hash' => 'CHAR(64) NOT NULL',
                    'verifier' => 'VARCHAR(128) NOT NULL',
                    'created_at' => 'INT(10) UNSIGNED NOT NULL',
                    'exchanging_at' => 'INT(10) UNSIGNED NULL DEFAULT NULL',
                ],
                'primary' => ['state_hash'],
                'indexes' => ['idx_created_at' => ['created_at']],
            ],
            // order_status request_id dedupe (EmporiqaOrderStatus::REQUEST_TABLE).
            'emporiqa_action_request' => [
                'columns' => [
                    'request_hash' => 'CHAR(64) NOT NULL',
                    'http_code' => 'SMALLINT(5) UNSIGNED NOT NULL',
                    'response' => 'MEDIUMTEXT NOT NULL',
                    'created_at' => 'INT(10) UNSIGNED NOT NULL',
                ],
                'primary' => ['request_hash'],
                'indexes' => ['idx_created_at' => ['created_at']],
            ],
            // order_status rate-limit counters, one row per value and window
            // (EmporiqaOrderStatus::RATE_TABLE).
            'emporiqa_action_rate' => [
                'columns' => [
                    'bucket_hash' => 'CHAR(64) NOT NULL',
                    'hits' => 'INT(10) UNSIGNED NOT NULL',
                    'expires_at' => 'INT(10) UNSIGNED NOT NULL',
                ],
                'primary' => ['bucket_hash'],
                'indexes' => ['idx_expires_at' => ['expires_at']],
            ],
        ];
    }

    /**
     * Create missing tables, then add missing columns and indexes.
     *
     * @param Db|null $db
     *
     * @return bool
     */
    public static function ensure($db = null)
    {
        $db = $db ?: Db::getInstance();

        foreach (self::tables() as $name => $definition) {
            $table = _DB_PREFIX_ . $name;

            if (!$db->execute(self::createTableSql($table, $definition))) {
                return false;
            }

            // Columns are added in definition order, each AFTER its
            // predecessor, so a repaired table matches a fresh one.
            $previous = null;
            foreach ($definition['columns'] as $column => $type) {
                if (!self::hasColumn($db, $table, $column)) {
                    $sql = 'ALTER TABLE `' . bqSQL($table) . '` ADD `' . bqSQL($column) . '` ' . $type
                        . ($previous === null ? ' FIRST' : ' AFTER `' . bqSQL($previous) . '`');
                    if (!$db->execute($sql)) {
                        return false;
                    }
                }
                $previous = $column;
            }

            foreach ($definition['indexes'] as $index => $columns) {
                if (!self::hasIndex($db, $table, $index)
                    && !$db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD KEY `' . bqSQL($index) . '` ('
                        . self::columnList($columns) . ')')
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param Db $db
     * @param string $table prefixed table name
     * @param string $column
     *
     * @return bool
     */
    public static function hasColumn($db, $table, $column)
    {
        // Uncached: ensure() asks again right after adding the column.
        $rows = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . "` LIKE '" . pSQL($column) . "'", true, false);

        return !empty($rows);
    }

    /**
     * @param Db $db
     * @param string $table prefixed table name
     * @param string $index
     *
     * @return bool
     */
    public static function hasIndex($db, $table, $index)
    {
        $rows = $db->executeS('SHOW INDEX FROM `' . bqSQL($table) . "` WHERE `Key_name` = '" . pSQL($index) . "'", true, false);

        return !empty($rows);
    }

    /**
     * @param Db|null $db
     *
     * @return bool
     */
    public static function dropAll($db = null)
    {
        $db = $db ?: Db::getInstance();
        $ok = true;
        foreach (array_keys(self::tables()) as $name) {
            $ok = $db->execute('DROP TABLE IF EXISTS `' . bqSQL(_DB_PREFIX_ . $name) . '`') && $ok;
        }

        return $ok;
    }

    private static function createTableSql($table, array $definition)
    {
        $lines = [];
        foreach ($definition['columns'] as $column => $type) {
            $lines[] = '`' . bqSQL($column) . '` ' . $type;
        }
        $lines[] = 'PRIMARY KEY (' . self::columnList($definition['primary']) . ')';
        foreach ($definition['indexes'] as $index => $columns) {
            $lines[] = 'KEY `' . bqSQL($index) . '` (' . self::columnList($columns) . ')';
        }

        return 'CREATE TABLE IF NOT EXISTS `' . bqSQL($table) . '` (' . implode(', ', $lines) . ') ENGINE='
            . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private static function columnList(array $columns)
    {
        return implode(', ', array_map(function ($column) {
            return '`' . bqSQL($column) . '`';
        }, $columns));
    }
}
