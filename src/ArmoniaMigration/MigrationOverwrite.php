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
     * Copied from Phalcon/Migrations
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

        //First time check create table phalcon_migration
        self::connectionSetup($options);

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
            $direction = ModelMigration::DIRECTION_FORWARD;
        }

        if ((ModelMigration::DIRECTION_FORWARD === $direction) && isset($completedVersions[(string)$finalVersion])) {
            print Color::info('Version ' . (string)$finalVersion . ' was already applied');
            exit;
        } elseif ((ModelMigration::DIRECTION_BACK === $direction) && !isset($completedVersions[(string)$finalVersion])) {
            print Color::info('Version ' . (string)$finalVersion . ' was already rolled back');
            exit;
        }

        //Directory depends on Forward or Back Migration
        if (ModelMigration::DIRECTION_BACK === $direction) {
            $directoryIterator = $migrationsDir . DIRECTORY_SEPARATOR.$finalVersion->getVersion();
        } else {
            $directoryIterator = $migrationsDir . DIRECTORY_SEPARATOR.$finalVersion->getVersion();
        }

        ModelMigration::setMigrationPath($migrationsDir);

        if (!is_dir($directoryIterator)) {
            exit;
        }

        $iterator = new DirectoryIterator($directoryIterator);

        $migrationStartTime = date("Y-m-d H:i:s");
        $logs = ['before' => [], 'after' => []];
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
                $tableName = $fileInfo->getBasename('.php');
                $logs['before'][$tableName] = self::getTableDescription($optionStack->getOptions(), $tableName);
                ModelMigration::migrate($initialVersion, $finalVersion, $tableName, $isMorph);
                $logs['after'][$tableName]  = self::getTableDescription($optionStack->getOptions(), $tableName);
            }
        } else {
            if (!empty($prefix)) {
                $optionStack->setOption('tableName', $listTables->listTablesForPrefix($prefix, $iterator));
            }

            $tables = explode(',', $optionStack->getOption('tableName'));
            foreach ($tables as $tableName) {
                $logs['before'][$tableName] = self::getTableDescription($optionStack->getOptions(), $tableName);
                ModelMigration::migrate($initialVersion, $finalVersion, $tableName, $isMorph);
                $logs['after'][$tableName] = self::getTableDescription($optionStack->getOptions(), $tableName);
            }
        }


        if (ModelMigration::DIRECTION_FORWARD == $direction) {
            self::addCurrentVersion($optionStack->getOptions(), (string)$finalVersion, $migrationStartTime);
            print Color::success('Version ' . $finalVersion . ' was successfully migrated');
            self::logMigration($optionStack->getOptions(), (string)$finalVersion, json_encode($logs));
        } else {
            self::removeCurrentVersion($optionStack->getOptions(), (string)$finalVersion, $migrationStartTime, json_encode($logs));
            print Color::success('Version ' . $finalVersion->getVersion() . ' was successfully rolled back');
        }
    }

    /**
     * Generate migrations
     * Copied from Phalcon/Migrations
     *
     * @param array $options
     *
     * @throws \Exception
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public static function generate(array $options)
    {
        $optionStack = new OptionStack();
        $listTables = new ListTablesDb();
        $optionStack->setOptions($options);
        $optionStack->setDefaultOption('version', null);
        $optionStack->setDefaultOption('descr', null);
        $optionStack->setDefaultOption('noAutoIncrement', null);
        $optionStack->setDefaultOption('verbose', false);

        $migrationsDirs = $optionStack->getOption('migrationsDir');
        //select multiple dir
        if (count($migrationsDirs) > 1) {
            $question = 'Which migrations path would you like to use?' . PHP_EOL;
            foreach ($migrationsDirs as $id => $dir) {
                $question .= " [{$id}] $dir" . PHP_EOL;
            }
            fwrite(STDOUT, Color::info($question));
            $handle = fopen("php://stdin", "r");
            $line = (int)fgets($handle);
            if (!isset($migrationsDirs[$line])) {
                echo "ABORTING!\n";
                return false;
            }
            fclose($handle);
            $migrationsDir = $migrationsDirs[$line];
        } else {
            $migrationsDir = $migrationsDirs[0];
        }
        // Migrations directory
        if ($migrationsDir && !file_exists($migrationsDir)) {
            mkdir($migrationsDir, 0755, true);
        }

        $versionItem = $optionStack->getVersionNameGeneratingMigration();

        // Path to migration dir
        $migrationPath = rtrim($migrationsDir, '\\/') .
            DIRECTORY_SEPARATOR . $versionItem->getVersion();

        if (!file_exists($migrationPath)) {
            if (is_writable(dirname($migrationPath)) && !$optionStack->getOption('verbose')) {
                mkdir($migrationPath);
            } elseif (!is_writable(dirname($migrationPath))) {
                throw new \RuntimeException("Unable to write '{$migrationPath}' directory. Permission denied");
            }
        } elseif (!$optionStack->getOption('force')) {
            throw new \LogicException('Version ' . $versionItem->getVersion() . ' already exists');
        }

        // Try to connect to the DB
        if (!isset($optionStack->getOption('config')->database)) {
            throw new \RuntimeException('Cannot load database configuration');
        }

        ModelMigration::setup($optionStack->getOption('config')->database, $optionStack->getOption('verbose'));
        ModelMigration::setSkipAutoIncrement($optionStack->getOption('noAutoIncrement'));
        ModelMigration::setMigrationPath($migrationsDir);

        $wasMigrated = false;
        if ($optionStack->getOption('tableName') === '@') {
            $migrations = ModelMigration::generateAll($versionItem, $optionStack->getOption('exportData'));
            if (!$optionStack->getOption('verbose')) {
                foreach ($migrations as $tableName => $migration) {
                    if ($tableName === self::MIGRATION_LOG_TABLE) {
                        continue;
                    }
                    $tableFile = $migrationPath . DIRECTORY_SEPARATOR . $tableName . '.php';
                    $wasMigrated = file_put_contents(
                        $tableFile,
                        '<?php ' . PHP_EOL . PHP_EOL . $migration
                    ) || $wasMigrated;
                }
            }
        } else {
            if ($optionStack->getOption('tableName') == '') {
                throw new ModelException('Please specify the table name to generate.');
            } else {
                $tableName = $optionStack->getOption('tableName');

                $modelMigration = new ModelMigration();
                $connection = $modelMigration->getConnection();
                $tablesList = $connection->listTables();      

                if (in_array($tableName, $tablesList)) {
                    $prefix = $optionStack->getPrefixOption($optionStack->getOption('tableName'));
                    if (!empty($prefix)) {
                        $optionStack->setOption('tableName', $listTables->listTablesForPrefix($prefix));
                    }

                    if ($optionStack->getOption('tableName') == '') {
                        print Color::info('No one table is created. You should create tables first.') . PHP_EOL;
                        return;
                    }

                    $tables = explode(',', $optionStack->getOption('tableName'));
                    foreach ($tables as $table) {
                        $migration = ModelMigration::generate($versionItem, $table, $optionStack->getOption('exportData'));
                        if (!$optionStack->getOption('verbose')) {
                            $tableFile = $migrationPath . DIRECTORY_SEPARATOR . $table . '.php';
                            $wasMigrated = file_put_contents(
                                $tableFile,
                                '<?php ' . PHP_EOL . PHP_EOL . $migration
                            );
                        }
                    }
                }else{                
                    $migration = ModelMigration::generateBlank($versionItem, $tableName);
                    $tableFile = $migrationPath . DIRECTORY_SEPARATOR . $tableName . '.php';
                    $wasMigrated = file_put_contents(
                        $tableFile,
                        '<?php ' . PHP_EOL . PHP_EOL . $migration
                    );
                }

                
            }
        }
        

        if (self::isConsole() && $wasMigrated) {
            print Color::success('Version ' . $versionItem->getVersion() . ' was successfully generated') . PHP_EOL;
        } elseif (self::isConsole() && !$optionStack->getOption('verbose')) {
            print Color::info('Nothing to generate. You should create tables first.') . PHP_EOL;
        }
    }

    /**
     * Add log on table changes from migrations run
     *
     * @param array $options Applications options
     * @param string $version Migration version
     * @param string $log table changes before and after
     */
    public static function logMigration($options, $version, $log = '')
    {
        self::connectionSetup($options);

        if (isset($options['migrationsInDb']) && (bool)$options['migrationsInDb']) {
            /** @var AdapterInterface $connection */
            $connection = self::$storage;
            $connection->execute('UPDATE ' . self::MIGRATION_LOG_TABLE . ' SET `logs` = \'' . addslashes($log) . '\' WHERE version=\'' . $version . '\' ');
        }
    }

    /**
     * Scan $storage for all completed versions
     * Copied from Phalcon/Migrations
     *
     * @param array $options Applications options
     * @return array
     */
    public static function getCompletedVersions($options)
    {
        self::connectionSetup($options);

        if (isset($options['migrationsInDb']) && (bool)$options['migrationsInDb']) {
            /** @var AdapterInterface $connection */
            $connection = self::$storage;
            $query = 'SELECT version FROM ' . self::MIGRATION_LOG_TABLE . ' WHERE rollback_start_time is null  ORDER BY version DESC';
            $completedVersions = $connection->query($query)->fetchAll();
            $completedVersions = array_map(function ($version) {
                return $version['version'];
            }, $completedVersions);
        } else {
            $completedVersions = file(self::$storage, FILE_IGNORE_NEW_LINES);
        }

        return array_flip($completedVersions);
    }

    /**
     * Remove migration version from log
     * Copied from Phalcon/Migrations
     *
     * @param array $options Applications options
     * @param string $version Migration version to remove
     */
    public static function removeCurrentVersion($options, $version, $startTime = null, $log = '')
    {
        self::connectionSetup($options);

        if ($startTime === null) {
            $startTime = date("Y-m-d H:i:s");
        }
        $endTime = date("Y-m-d H:i:s");

        if (isset($options['migrationsInDb']) && (bool)$options['migrationsInDb']) {
             /** @var AdapterInterface $connection */
            $connection = self::$storage;
            $connection->execute('UPDATE ' . self::MIGRATION_LOG_TABLE . ' SET `version` = \'rollback_' . $version . '\', rollback_start_time = \'' . $startTime . '\', rollback_end_time = \'' . $endTime . '\', `rollback_logs` = \'' . addslashes($log) . '\'   WHERE version=\'' . $version . '\' ');
        } else {
            $currentVersions = self::getCompletedVersions($options);
            unset($currentVersions[$version]);
            $currentVersions = array_keys($currentVersions);
            sort($currentVersions);
            file_put_contents(self::$storage, implode("\n", $currentVersions));
        }
    }

    /**
     * Add log on table changes from migrations run
     *
     * @param array $options Applications options
     * @param string $version Migration version
     * @param string $log table changes before and after
     */
    public static function getTableDescription($options, $tableName)
    {
        $output = '';
        self::connectionSetup($options);

        if (isset($options['migrationsInDb']) && (bool)$options['migrationsInDb'])
        {
            if (self::$storage->tableExists($tableName))
            {
                $connection = self::$storage;
                $query = 'DESCRIBE ' . $tableName;
                $tableDesc = $connection->query($query);
                $output = $tableDesc->fetchAll(\Phalcon\Db::FETCH_ASSOC);
            }            
        }

        return $output;
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
                        ),
                        new Column(
                            'logs',
                            [
                                'type' => Column::TYPE_TEXT,
                                'notNull' => false
                            ]
                        ),
                        new Column(
                            'rollback_start_time',
                            [
                                'type' => Column::TYPE_DATETIME,
                                'notNull' => false
                            ]
                        ),
                        new Column(
                            'rollback_end_time',
                            [
                                'type' => Column::TYPE_DATETIME,
                                'notNull' => false
                            ]
                        ),
                        new Column(
                            'rollback_logs',
                            [
                                'type' => Column::TYPE_TEXT,
                                'notNull' => false
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
