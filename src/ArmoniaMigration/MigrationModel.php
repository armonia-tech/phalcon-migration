<?php

/*
  +------------------------------------------------------------------------+
  | Phalcon Developer Tools                                                |
  +------------------------------------------------------------------------+
  | Copyright (c) 2011-present Phalcon Team (https://www.phalconphp.com)   |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file LICENSE.txt.                             |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconphp.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Authors: Andres Gutierrez <andres@phalconphp.com>                      |
  |          Eduar Carvajal <eduar@phalconphp.com>                         |
  +------------------------------------------------------------------------+
*/

namespace ArmoniaMigration;

use Phalcon\Db;
use Phalcon\Text;
use Phalcon\Utils;
use DirectoryIterator;
use Phalcon\Db\Column;
use Phalcon\Migrations;
use Phalcon\Utils\Nullify;
use Phalcon\Generator\Snippet;
use Phalcon\Version\ItemInterface;
use Phalcon\Db\Dialect\DialectMysql;
use Phalcon\Db\Exception as DbException;
use Phalcon\Mvc\Model\Migration\Profiler;
use Phalcon\Listeners\DbProfilerListener;
use Phalcon\Db\Dialect\DialectPostgresql;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Exception\Db\UnknownColumnTypeException;
use Phalcon\Version\ItemCollection as VersionCollection;
use Phalcon\Db\Adapter\Pdo\PdoMysql;
use Phalcon\Db\Adapter\Pdo\PdoPostgresql;
use Phalcon\Mvc\Model\Migration as PhModelMigration;

/**
 * Copied from Phalcon\Mvc\Model\Migration
 *
 * Migrations of DML y DDL over databases
 * @method afterCreateTable()
 * @method morph()
 * @method up()
 * @method afterUp()
 * @method down()
 * @method afterDown()
 *
 * @package Phalcon\Mvc\Model
 */
class MigrationModel extends PhModelMigration
{
    const DIRECTION_FORWARD = 1;
    const DIRECTION_BACK = -1;

    /**
     * Path where to save the migration
     * Copied from Phalcon\Mvc\Model\Migration
     *
     * @var string
     */
    private static $migrationPath = null;

    /**
     * Skip auto increment
     * Copied from Phalcon\Mvc\Model\Migration
     *
     * @var bool
     */
    private static $skipAI = false;

    /**
     * Migrate
     * Copied from Phalcon\Mvc\Model\Migration
     *
     * @param \Phalcon\Version\IncrementalItem|\Phalcon\Version\TimestampedItem $fromVersion
     * @param \Phalcon\Version\IncrementalItem|\Phalcon\Version\TimestampedItem $toVersion
     * @param string  $tableName
     */
    public static function migrate($fromVersion, $toVersion, $tableName, $isMorph = false)
    {
        if (!is_object($fromVersion)) {
            $fromVersion = VersionCollection::createItem($fromVersion);
        }

        if (!is_object($toVersion)) {
            $toVersion = VersionCollection::createItem($toVersion);
        }

        if ($fromVersion->getStamp() == $toVersion->getStamp()) {
            throw new \Exception('Ignored migration with same timestamp.');
            return;
        }

        if ($fromVersion->getStamp() < $toVersion->getStamp()) {
            $toMigration = self::createClass($toVersion, $tableName);

            if (is_object($toMigration)) {
                // morph the table structure
                if ($isMorph == true && method_exists($toMigration, 'morph')) {
                    $toMigration->morph();
                }

                // modify the datasets
                if (method_exists($toMigration, 'up')) {
                    $toMigration->up();
                    if (method_exists($toMigration, 'afterUp')) {
                        $toMigration->afterUp();
                    }
                }
            }
        } else {
            // rollback!

            $toMigration = self::createClass($toVersion, $tableName);

            if (is_object($toMigration)) {
                // modify the datasets
                if (method_exists($toMigration, 'down')) {
                    $toMigration->down();
                    if (method_exists($toMigration, 'afterDown')) {
                        $toMigration->afterDown();
                    }
                }
            }
        }
    }

    /**
     * Generate specified blank migration
     *
     * @param ItemInterface $version
     * @param string        $table
     * @param mixed         $exportData
     *
     * @return string
     * @throws \Phalcon\Db\Exception
     */
    public static function generateBlank(ItemInterface $version, $table)
    {
        $snippet = new Snippet();
        $classVersion = preg_replace('/[^0-9A-Za-z]/', '', $version->getStamp());
        $className = Text::camelize($table).'Migration_'.$classVersion;
        $tableDefinition = [];
        // morph()
        //$classData = $snippet->getMigrationMorph($className, $table, $tableDefinition);
        $template = <<<EOD
use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Mvc\Model\Migration;

/**
 * Class %s
 */
class %s extends Migration
{
    /**
     * Define the table structure
     *
     * @return void
     */
    public function morph()
    {
EOD;
        $classData = sprintf($template, $className, $className);
        $classData .= "\n    }\n";

        // up()
        $classData .= $snippet->getMigrationUp();

        $classData .= "    }\n";

        // down()
        $classData .= $snippet->getMigrationDown();

        $classData .= "    }\n";

        // end of class
        $classData .= "}\n";

        return $classData;
    }

    /**
     * Find the last morph function in the previous migration files
     * Copied from Phalcon\Mvc\Model\Migration
     *
     * @param ItemInterface $toVersion
     * @param string        $tableName
     *
     * @return null|Migration
     * @throws Exception
     * @internal param ItemInterface $version
     */
    private static function createPrevClassWithMorphMethod(ItemInterface $toVersion, $tableName)
    {
        $prevVersions = [];
        $versions = self::scanForVersions(self::$migrationPath);
        foreach ($versions as $prevVersion) {
            if ($prevVersion->getStamp() <= $toVersion->getStamp()) {
                $prevVersions[] = $prevVersion;
            }
        }

        $prevVersions = VersionCollection::sortDesc($prevVersions);
        foreach ($prevVersions as $prevVersion) {
            $migration = self::createClass($prevVersion, $tableName);
            if (is_object($migration) && method_exists($migration, 'morph')) {
                return $migration;
            }
        }

        return null;
    }

    /**
     * Create migration object for specified version
     * Copied from Phalcon\Mvc\Model\Migration
     *
     * @param ItemInterface $version
     * @param string        $tableName
     *
     * @return null|\Phalcon\Mvc\Model\Migration
     *
     * @throws Exception
     */
    private static function createClass(ItemInterface $version, $tableName)
    {
        $fileName = self::$migrationPath.$version->getVersion().DIRECTORY_SEPARATOR.$tableName.'.php';
        if (!file_exists($fileName)) {
            return null;
        }

        $classVersion = preg_replace('/[^0-9A-Za-z]/', '', $version);
        $className = Text::camelize($tableName).'Migration_'.$version->getStamp();

        include_once $fileName;
        if (!class_exists($className)) {
            throw new \Exception('Migration class cannot be found '.$className.' at '.$fileName);
        }

        $migration = new $className($version);
        $migration->version = $version;

        return $migration;
    }

    /**
     * Set the skip auto increment value
     * Copied from Phalcon\Mvc\Model\Migration
     *
     * @param bool $skip
     */
    public static function setSkipAutoIncrement($skip)
    {
        self::$skipAI = $skip;
    }

    /**
     * Set the migration directory path
     * Copied from Phalcon\Mvc\Model\Migration
     *
     * @param string $path
     */
    public static function setMigrationPath($path)
    {
        self::$migrationPath = rtrim($path, '\\/').DIRECTORY_SEPARATOR;
    }

}
