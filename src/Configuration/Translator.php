<?php
namespace phpbu\Laravel\Configuration;

use phpbu\App\Configuration;
use phpbu\App\Configuration\Backup\Target;

/**
 * Class Translator
 *
 * @package    phpbu\Laravel
 * @author     Sebastian Feldmann <sebastian@phpbu.de>
 * @copyright  Sebastian Feldmann <sebastian@phpbu.de>
 * @license    http://www.opensource.org/licenses/MIT The MIT License (MIT)
 * @link       http://phpbu.de/
 */
class Translator
{
    /**
     * Configuration Proxy
     *
     * @var \phpbu\Laravel\Configuration\Proxy
     */
    private $proxy;

    /**
     * Configurable laravel backup types.
     *
     * @var array
     */
    private $types = [
        'directories' => 'directoryConfigToBackup',
        'databases'   => 'databaseConfigToBackup',
    ];

    /**
     * Translates the laravel configuration to a phpbu configuration.
     *
     * @param  \phpbu\Laravel\Configuration\Proxy $proxy
     * @return \phpbu\App\Configuration
     * @throws \phpbu\Laravel\Configuration\Exception
     */
    public function translate(Proxy $proxy)
    {
        $this->proxy   = $proxy;
        $laravelPhpbu  = $this->proxy->get('phpbu');
        $configuration = new Configuration();
        $configuration->setFilename($this->proxy->get('phpbu.config'));

        $this->addBackups($configuration, $laravelPhpbu);

        return $configuration;
    }

    /**
     * Translate and add all configured backups.
     *
     * @param \phpbu\App\Configuration $configuration
     * @param array                    $laravelPhpbu
     */
    protected function addBackups(Configuration $configuration, array $laravelPhpbu)
    {
        // walk the the configured backups
        foreach (array_keys($this->types) as $type) {
            foreach ($laravelPhpbu[$type] as $conf) {
                // create and add a phpbu backup config
                $configuration->addBackup($this->translateBackup($type, $conf));
            }
        }
    }

    /**
     * Translates a given laravel config type to a phpbu backup configuration.
     *
     * @param  string $type
     * @param  array  $conf
     * @throws \phpbu\Laravel\Configuration\Exception
     * @return \phpbu\App\Configuration\Backup
     */
    public function translateBackup($type, array $conf)
    {
        /** @var \phpbu\App\Configuration\Backup $backup */
        $backup = $this->{$this->types[$type]}($conf);
        $backup->setTarget($this->translateTarget($conf['target']));

        $this->addChecksIfConfigured($backup, $conf);
        $this->addSyncIfConfigured($backup, $conf);
        $this->addCleanupIfConfigured($backup, $conf);
        $this->addCryptIfConfigured($backup, $conf);

        return $backup;
    }

    /**
     * Translate a laravel directory config to phpbu backup configuration.
     *
     * @param  array $dir
     * @return \phpbu\App\Configuration\Backup
     * @throws \phpbu\Laravel\Configuration\Exception
     */
    protected function directoryConfigToBackup(array $dir)
    {
        $backup = new Configuration\Backup($dir['source']['path'], false);
        // build source config
        $options = [
            'path' => $dir['source']['path'],
        ];

        // check for configuration options
        if (isset($dir['source']['options'])) {
            $options = array_merge($options, $dir['source']['options']);
        }
        $backup->setSource(new Configuration\Backup\Source('tar', $options));
        return $backup;
    }

    /**
     * Translate a laravel db config to phpbu backup configuration.
     *
     * @param  array $db
     * @throws \phpbu\Laravel\Configuration\Exception
     * @return \phpbu\App\Configuration\Backup
     */
    protected function databaseConfigToBackup(array $db)
    {
        $connection = $this->getDatabaseConnectionConfig($db['source']['connection']);

        // translate laravel settings to source options
        $options = [
            'host'      => $connection['host'],
            'user'      => $connection['username'],
            'password'  => $connection['password'],
            'databases' => $connection['database'],
        ];

        // check for configuration options
        if (isset($db['source']['options'])) {
            $options = array_merge($options, $db['source']['options']);
        }

        $backup = new Configuration\Backup('db-' . $db['source']['connection'], false);
        $type   = $this->getDatabaseSourceType($connection['driver']);
        $backup->setSource(new Configuration\Backup\Source($type, $options));

        return $backup;
    }

    /**
     * Get a database connection configuration.
     *
     * @param  string $connection
     * @return array
     * @throws \Exception
     */
    protected function getDatabaseConnectionConfig($connection)
    {
        $connections = $this->proxy->get('database.connections');
        if (!isset($connections[$connection])) {
            throw new Exception('Unknown database connection: ' . $connection);
        }
        $config = $connections[$connection];
        if (!in_array($config['driver'], ['mysql', 'pgsql'])) {
            throw new Exception('Currently only MySQL and PostgreSQL databases are supported using the laravel config');
        }
        return $config;
    }

    /**
     * Map database driver to phpbu source type.
     *
     * @param  string $driver
     * @return string
     */
    protected function getDatabaseSourceType($driver)
    {
        $types = ['mysql' => 'mysqldump', 'pgsql' => 'pgdump'];
        return $types[$driver];
    }

    /**
     * Translate the target configuration.
     *
     * @param  array $config
     * @return Target
     * @throws \Exception
     */
    protected function translateTarget(array $config)
    {
        if (empty($config['dirname'])) {
            throw new Exception('invalid target: dirname has to be configured');
        }
        if (empty($config['filename'])) {
            throw new Exception('invalid target: filename has to be configured');
        }
        $dirname     = $config['dirname'];
        $filename    = $config['filename'];
        $compression = !empty($config['compression']) ? $config['compression'] : null;

        return new Target($dirname, $filename, $compression);
    }

    /**
     * Adds a check configuration to the given backup configuration.
     *
     * @param \phpbu\App\Configuration\Backup $backup
     * @param array                           $conf
     */
    protected function addChecksIfConfigured(Configuration\Backup $backup, array $conf)
    {
        if (isset($conf['check'])) {
            $backup->addCheck(
                new Configuration\Backup\Check(
                    $conf['check']['type'],
                    $conf['check']['value']
                )
            );
        }
    }

    /**
     * Adds a sync configuration to the given backup configuration.
     *
     * @param \phpbu\App\Configuration\Backup $backup
     * @param array                           $conf
     */
    protected function addSyncIfConfigured(Configuration\Backup $backup, array $conf)
    {
        if (isset($conf['sync'])) {
            $backup->addSync(
                new Configuration\Backup\Sync(
                    'laravel-storage',
                    false,
                    [
                        'filesystem' => $conf['sync']['filesystem'],
                        'path'       => $conf['sync']['path']
                    ]
                )
            );
        }
    }

    /**
     * Adds a cleanup configuration to the given backup configuration.
     *
     * @param \phpbu\App\Configuration\Backup $backup
     * @param array                           $conf
     */
    protected function addCleanupIfConfigured(Configuration\Backup $backup, array $conf)
    {
        if (isset($conf['cleanup'])) {
            $backup->setCleanup(
                new Configuration\Backup\Cleanup(
                    $conf['cleanup']['type'],
                    false,
                    $conf['cleanup']['options']
                )
            );
        }
    }

    /**
     * Adds a encryption configuration to the given encryption configuration.
     *
     * @param \phpbu\App\Configuration\Backup $backup
     * @param array                           $conf
     */
    protected function addCryptIfConfigured(Configuration\Backup $backup, array $conf)
    {
        if (isset($conf['crypt'])) {
            $backup->setCrypt(
                new Configuration\Backup\Crypt(
                    $conf['crypt']['type'],
                    false,
                    $conf['crypt']['options']
                )
            );
        }
    }
}
