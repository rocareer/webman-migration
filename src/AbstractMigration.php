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
 * File      AbstractMigration.php
 * Author    albert@rocareer.com
 * Time      2025-04-27 11:22:13
 * Describe  AbstractMigration.php
 */

namespace Rocareer\WebmanMigration;



use Cake\Database\Query;
use Cake\Database\Query\DeleteQuery;
use Cake\Database\Query\InsertQuery;
use Cake\Database\Query\SelectQuery;
use Cake\Database\Query\UpdateQuery;
use Phinx\Db\Adapter\AdapterInterface;
use Rocareer\WebmanMigration\Table;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Abstract Migration Class.
 *
 * It is expected that the migrations you write extend from this class.
 *
 * This abstract class proxies the various database methods to your specified
 * adapter.
 */
abstract class AbstractMigration extends \Phinx\Migration\AbstractMigration
{
    /**
     * @var string
     */
    protected string $environment;

    /**
     * @var int
     */
    protected int $version;

    /**
     * @var \Phinx\Db\Adapter\AdapterInterface|null
     */
    protected ?AdapterInterface $adapter = null;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface|null
     */
    protected ?OutputInterface $output = null;

    /**
     * @var \Symfony\Component\Console\Input\InputInterface|null
     */
    protected ?InputInterface $input = null;

    /**
     * Whether this migration is being applied or reverted
     *
     * @var bool
     */
    protected bool $isMigratingUp = true;

    /**
     * List of all the table objects created by this migration
     *
     * @var array<\Phinx\Db\Table>
     */
    protected array $tables = [];


    public function table(string $tableName, array $options = []): \Phinx\Db\Table
    {
        return parent::table($tableName, $options);
    }

    public function hasTable(string $tableName): bool
    {
        return $this->getAdapter()->hasTable($tableName);
    }


}