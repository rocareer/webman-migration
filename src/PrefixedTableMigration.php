<?php
/*
 *
 *  * // +----------------------------------------------------------------------
 *  * // | Rocareer [ ROC YOUR CAREER ]
 *  * // +----------------------------------------------------------------------
 *  * // | Copyright (c) 2014~2025 Albert@rocareer.com All rights reserved.
 *  * // +----------------------------------------------------------------------
 *  * // | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  * // +----------------------------------------------------------------------
 *  * // | Author: albert <Albert@rocareer.com>
 *  * // +----------------------------------------------------------------------
 *
 */

/**
 * File      PrefixedTableMigration.php
 * Author    albert@rocareer.com
 * Time      2025-04-27 10:08:51
 * Describe  PrefixedTableMigration.php
 */

namespace Rocareer\WebmanMigration;

use Phinx\Db\Action\RenameTable;
use Phinx\Migration\AbstractMigration;
use Rocareer\WebmanMigration\Table;
use function config;

class PrefixedTableMigration extends AbstractMigration
{
    protected $prefix = 'prefix_'; // 表前缀

    public function prefix()
    {
        return config('plugin.rocareer.webman-migration.migrate.table_prefix', 'prefix_');
    }


    public function table($tableName, $options = []): \Phinx\Db\Table
    {
        return parent::table($this->prefix() . $tableName, $options);
    }
    public function hasTable(string $tableName): bool
    {
        $this->prefix=config('plugin.rocareer.webman-migration.migrate.table_prefix', 'prefix_');
        return $this->getAdapter()->hasTable($this->prefix() .$tableName);
    }


}