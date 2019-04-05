<?php
namespace ArmoniaMigration;

use Phalcon\Db\Index;
use DirectoryIterator;
use Phalcon\Db\Column;
use Phalcon\Db\Adapter;
use Phalcon\Script\Color;
use Phalcon\Db\AdapterInterface;
use Phalcon\Version\ItemInterface;
use Phalcon\Script\ScriptException;
use Phalcon\Db\Dialect\DialectMysql;
use Phalcon\Db\Dialect\DialectPostgresql;
use Phalcon\Db\Exception as DbException;
use Phalcon\Mvc\Model\Exception as ModelException;
use ArmoniaMigration\MigrationModel as ModelMigration;
use Phalcon\Version\IncrementalItem as IncrementalVersion;
use Phalcon\Version\ItemCollection as VersionCollection;
use Phalcon\Console\OptionStack;
use Phalcon\Mvc\Model\Migration\TableAware\ListTablesIterator;
use Phalcon\Mvc\Model\Migration\TableAware\ListTablesDb;
use Phalcon\Config;
use Phalcon\Migrations as PhMigrations;

class MigrationOverwrite extends PhMigrations
{
    /**
     * Run migrations
     *
     * @param array $options
     *
     * @throws Exception
     * @throws ModelException
     * @throws ScriptException
     *
     */
    public static function run(array $options)
    {
        $optionStack = new OptionStack();
        $listTables = new ListTablesIterator();
        $optionStack->setOptions($options);
        $optionStack->setDefaultOption('verbose', false);
        $isMorph = false;

        // Define versioning type to be used
        if (isset($options['tsBased']) && $optionStack->getOption('tsBased') === true) {
            VersionCollection::setType(VersionCollection::TYPE_TIMESTAMPED);
        } else {
            VersionCollection::setType(VersionCollection::TYPE_INCREMENTAL);
        }

        // Define to force morph method in migration file
        if (isset($options['morph']) && $optionStack->getOption('morph') === true) {
            $isMorph = true;
        }

        if (!$optionStack->getOption('config') instanceof Config) {
            throw new ModelException('Internal error. Config should be an instance of ' . Config::class);
        }

        // Init ModelMigration
        if (!isset($optionStack->getOption('config')->database)) {
            throw new ScriptException('Cannot load database configuration');
        }

        /** @var \Phalcon\Version\IncrementalItem $initialVersion */
        $initialVersion = self::getCurrentVersion($optionStack->getOptions());
        $completedVersions = self::getCompletedVersions($optionStack->getOptions());
        
        $migrationsDirs = [];
        $versionItems = [];
        $migrationsDirList = $optionStack->getOption('migrationsDir');
        if (is_array($migrationsDirList)) {
            foreach ($migrationsDirList as $migrationsDir) {
                $migrationsDir = rtrim($migrationsDir, '\\/');
                if (!file_exists($migrationsDir)) {
                    throw new ModelException('Migrations directory was not found.');
                }
                $migrationsDirs[] = $migrationsDir;
                foreach (ModelMigration::scanForVersions($migrationsDir) as $items) {
                    $items->setPath($migrationsDir);
                    $versionItems [] = $items;
                }
            }
        }

        $finalVersion = null;
        if (isset($options['version']) && $optionStack->getOption('version') !== null) {
            $finalVersion = VersionCollection::createItem($options['version']);  
        }else{
            throw new ModelException('Please specify the version of migration to run.');
        }

        $isVersionExist = false;
        foreach ($versionItems as $versionItem) 
        {
            if ($versionItem->getStamp() == $finalVersion->getStamp()) {
                $isVersionExist = true;
                $migrationsDir = $versionItem->getPath();
                break;
            }
        }

        if (!$isVersionExist) {
            throw new ModelException($options['version'].' folder was not found in migration directory.');
        }

        $optionStack->setOption('tableName', $options['tableName'], '@');

        if (!isset($versionItems[0])) {
            if (php_sapi_name() == 'cli') {
                fwrite(STDERR, PHP_EOL . 'Migrations were not found at ' .
                    $optionStack->getOption('migrationsDir') . PHP_EOL);
                exit;
            } else {
                throw new ModelException('Migrations were not found at ' . $optionStack->getOption('migrationsDir'));
            }
        }

        // Set default final version
        if ($finalVersion === null) {
            $finalVersion = VersionCollection::maximum($versionItems);
        }

        ModelMigration::setup($optionStack->getOption('config')->database, $optionStack->getOption('verbose'));
        self::connectionSetup($optionStack->getOptions());

        if (isset($options['rollback']) && $optionStack->getOption('rollback') === true) {
            $direction = ModelMigration::DIRECTION_BACK;
        } else {
           // If we migrate up, we should go from the beginning to run some migrations which may have been missed
            $versionItemsTmp = VersionCollection::sortAsc(array_merge($versionItems, [$initialVersion]));
            $initialVersion = $versionItemsTmp[0];
            $direction = ModelMigration::DIRECTION_FORWARD;
        }

        if ($initialVersion->getVersion() == $finalVersion->getVersion()) {
            $initialVersion->setPath($finalVersion->getPath());
        }

        if ((ModelMigration::DIRECTION_FORWARD === $direction) && isset($completedVersions[(string)$finalVersion])) {
            print Color::info('Version ' . (string)$finalVersion . ' was already applied');
            exit;
        } elseif ((ModelMigration::DIRECTION_BACK === $direction) &&
            !isset($completedVersions[(string)$initialVersion])) {
            print Color::info('Version ' . (string)$finalVersion . ' was already rolled back');
            exit;
        }

        //Directory depends on Forward or Back Migration
        if (ModelMigration::DIRECTION_BACK === $direction) {
            $directoryIterator = $migrationsDir . DIRECTORY_SEPARATOR.$initialVersion->getVersion();
        } else {
            $directoryIterator = $migrationsDir . DIRECTORY_SEPARATOR.$finalVersion->getVersion();
        }

        ModelMigration::setMigrationPath($migrationsDir);

        if (!is_dir($directoryIterator)) {
            exit;
        }

        $iterator = new DirectoryIterator($directoryIterator);

        $migrationStartTime = date("Y-m-d H:i:s");

        //Force execute to overwrite timestamp checking in Mvc/Model/Migration
        if (ModelMigration::DIRECTION_FORWARD == $direction) {
            $initialVersion = VersionCollection::createItem('1000000000000000_at'); 
        } else {
            $initialVersion = VersionCollection::createItem('9999999999999999_at'); 
        }

        if ($optionStack->getOption('tableName') === '@') {
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || 0 !== strcasecmp($fileInfo->getExtension(), 'php')) {
                    continue;
                }
                ModelMigration::migrate($initialVersion, $finalVersion, $fileInfo->getBasename('.php'), $isMorph);
            }
        } else {
            if (!empty($prefix)) {
                $optionStack->setOption('tableName', $listTables->listTablesForPrefix($prefix, $iterator));
            }

            $tables = explode(',', $optionStack->getOption('tableName'));
            foreach ($tables as $tableName) {
                ModelMigration::migrate($initialVersion, $finalVersion, $tableName, $isMorph);
            }
        }

        if (ModelMigration::DIRECTION_FORWARD == $direction) {
            self::addCurrentVersion($optionStack->getOptions(), (string)$finalVersion, $migrationStartTime);
            print Color::success('Version ' . $finalVersion . ' was successfully migrated');
        } else {
            self::removeCurrentVersion($optionStack->getOptions(), (string)$finalVersion);
            print Color::success('Version ' . $finalVersion->getVersion() . ' was successfully rolled back');
        }

        $initialVersion = $finalVersion;
    }

    /**
     * Initialize migrations log storage 
     * Copied from Phalcon/Migrations
     *
     * @param array $options Applications options
     * @throws DbException
     */
    private static function connectionSetup($options)
    {
        if (self::$storage) {
            return;
        }

        if (isset($options['migrationsInDb']) && (bool)$options['migrationsInDb']) {
            /** @var Config $database */
            $database = $options['config']['database'];

            if (!isset($database->adapter)) {
                throw new DbException('Unspecified database Adapter in your configuration!');
            }

            $adapter = '\\Phalcon\\Db\\Adapter\\Pdo\\' . $database->adapter;

            if (!class_exists($adapter)) {
                throw new DbException('Invalid database Adapter!');
            }

            $configArray = $database->toArray();
            unset($configArray['adapter']);
            self::$storage = new $adapter($configArray);

            if ($database->adapter === 'Mysql') {
                self::$storage->setDialect(new DialectMysql);
                self::$storage->query('SET FOREIGN_KEY_CHECKS=0');
            }

            if ($database->adapter == 'Postgresql') {
                self::$storage->setDialect(new DialectPostgresql);
            }

            if (!self::$storage->tableExists(self::MIGRATION_LOG_TABLE)) {
                self::$storage->createTable(self::MIGRATION_LOG_TABLE, null, [
                    'columns' => [
                        new Column(
                            'version',
                            [
                                'type' => Column::TYPE_VARCHAR,
                                'size' => 255,
                                'notNull' => true,
                            ]
                        ),
                        new Column(
                            'start_time',
                            [
                                'type' => Column::TYPE_TIMESTAMP,
                                'notNull' => true,
                                'default' => 'CURRENT_TIMESTAMP',
                            ]
                        ),
                        new Column(
                            'end_time',
                            [
                                'type' => Column::TYPE_TIMESTAMP,
                                'notNull' => true,
                                'default' => 'CURRENT_TIMESTAMP',
                            ]
                        )
                    ],
                    'indexes' => [
                        new Index('idx_' . self::MIGRATION_LOG_TABLE . '_version', ['version'])
                    ]
                ]);
            }
        } else {
            if (empty($options['directory'])) {
                $path = defined('BASE_PATH') ? BASE_PATH : defined('APP_PATH') ? dirname(APP_PATH) : '';
                $path = rtrim($path, '\\/') . '/.phalcon';
            } else {
                $path = rtrim($options['directory'], '\\/') . '/.phalcon';
            }
            if (!is_dir($path) && !is_writable(dirname($path))) {
                throw new \RuntimeException("Unable to write '{$path}' directory. Permission denied");
            }
            if (is_file($path)) {
                unlink($path);
                mkdir($path);
                chmod($path, 0775);
            } elseif (!is_dir($path)) {
                mkdir($path);
                chmod($path, 0775);
            }

            self::$storage = $path . '/migration-version';

            if (!file_exists(self::$storage)) {
                if (!is_writable($path)) {
                    throw new \RuntimeException("Unable to write '" . self::$storage . "' file. Permission denied");
                }
                touch(self::$storage);
            }
        }
    }

}
