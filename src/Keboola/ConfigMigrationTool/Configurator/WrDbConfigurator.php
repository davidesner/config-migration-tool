<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 09/01/17
 * Time: 16:06
 */

namespace Keboola\ConfigMigrationTool\Configurator;

use Keboola\StorageApi\Options\Components\Configuration;

class WrDbConfigurator
{
    /**
     * @param $attributes
     * @param $prettyName
     * @return Configuration
     */
    public function create($attributes, $prettyName)
    {
        $configuration = new Configuration();
        $configuration->setComponentId($this->getComponentId($attributes));
        $configuration->setConfigurationId($attributes['writerId']);
        $configuration->setName($prettyName);
        $configuration->setDescription(isset($attributes['description'])?$attributes['description']:'');

        return $configuration;
    }

    /**
     * @param $attributes
     * @return string
     */
    public function getComponentId($attributes)
    {
        return sprintf(
            'keboola.%s-%s',
            'wr-db',
            isset($attributes['driver'])?$attributes['driver']:'mysql'
        );
    }

    /**
     * @param $credentials
     * @param $tables
     * @return array
     */
    public function configure($credentials, $tables)
    {
        // configuration can be empty
        if (!isset($credentials['host'])) {
            return [];
        }
        $configuration = [
            'parameters' => [
                'db' => [
                    'host' => $credentials['host'],
                    'port' => $credentials['port'],
                    'database' => $credentials['database'],
                    'user' => $credentials['user'],
                    '#password' => $credentials['password'],
                    'driver' => $credentials['driver']
                ],
            ]
        ];

        foreach ($tables as $table) {
            $newTable = [
                'dbName' => $table['name'],
                'export' => boolval($table['export']),
                'tableId' => $table['id']
            ];
            foreach ($table['columns'] as $column) {
                $newTable['items'][] = [
                    'name' => $column['name'],
                    'dbName' => $column['dbName'],
                    'type' => $column['type'],
                    'size' => $column['size'],
                    'nullable' => boolval($column['null']),
                    'default' => $column['default']
                ];
            }

            $configuration['parameters']['tables'][] = $newTable;
        }

        if ($credentials['driver'] == 'mysql') {
            if (isset($credentials['ssl'])) {
                $configuration['parameters']['db']['ssl'] = $credentials['ssl'];
                $configuration['parameters']['db']['ssl']['enabled'] = true;
            }
        }

        return $configuration;
    }
}