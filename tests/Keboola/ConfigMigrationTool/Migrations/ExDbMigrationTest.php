<?php

/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 17/05/16
 * Time: 11:10
 */

namespace Keboola\ConfigMigrationTool\Test;

use Keboola\ConfigMigrationTool\Helper\TableHelper;
use Keboola\ConfigMigrationTool\Migration\ExDbMigration;
use Keboola\ConfigMigrationTool\Service\OrchestratorService;
use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Monolog\Logger;

class ExDbMigrationTest extends \PHPUnit_Framework_TestCase
{
    public function testExecute()
    {
        $sapiClient = new Client(['token' => getenv('KBC_TOKEN')]);
        $components = new Components($sapiClient);

        $tables = $sapiClient->listTables('sys.c-ex-db');
        foreach ($tables as $table) {
            $sapiClient->dropTable($table['id']);
        }

        $testConfigs = [];
        $testConfigs[] = $this->createOldConfig($sapiClient, 'mysql');
        $testConfigs[] = $this->createOldConfig($sapiClient, 'pgsql');
        $testConfigs[] = $this->createOldConfig($sapiClient, 'oracle');
        $emptyConfig = $this->createOldConfigEmpty($sapiClient);
        $testConfigs[] = $emptyConfig;

        $oldConfigs = [];
        foreach ($testConfigs as $tableId) {
            $table = $sapiClient->getTable($tableId);
            $attributes = TableHelper::formatAttributes($table['attributes']);
            $driver = isset($attributes['db.driver'])?$attributes['db.driver']:'mysql';
            //cleanup
            try {
                $components->deleteConfiguration('keboola.ex-db-' . $driver, $attributes['accountId']);
                $sapiClient->deleteTableAttribute($table['id'], 'migrationStatus');
            } catch (\Exception $e) {
                // do nothing
            }
            $oldConfigs[] = [
                'accountId' => $attributes['accountId'],
                'name' => $attributes['name'],
                'driver' => $driver
            ];
        }

        $migration = new ExDbMigration(new Logger(APP_NAME));
        $createdConfigurations = $migration->execute();
        $this->assertNotEmpty($createdConfigurations);
        $this->assertCount(4, $createdConfigurations);

        $atLeastOneConfigurationHasTables = false;
        foreach ($oldConfigs as $oldCfg) {
            $newConfiguration = $this->findConfigurationByName($createdConfigurations, $oldCfg['name']);
            $this->assertNotFalse($newConfiguration);
            $this->assertEquals($oldCfg['name'], $newConfiguration->getName());
            if ($oldCfg['accountId'] == 'testConfigEmpty') {
                // empty config
                continue;
            }
            $this->assertArrayHasKey('parameters', $newConfiguration->getConfiguration());
            $parameters = $newConfiguration->getConfiguration()['parameters'];
            $this->assertArrayHasKey('db', $parameters);
            $this->assertArrayHasKey('host', $parameters['db']);
            $this->assertArrayHasKey('port', $parameters['db']);
            $this->assertArrayHasKey('user', $parameters['db']);
            $this->assertArrayHasKey('#password', $parameters['db']);

            if ($oldCfg['driver'] == 'mysql') {
                $this->assertArrayHasKey('key', $parameters['db']['ssl']);
                $this->assertEquals('sslkey', $parameters['db']['ssl']['key']);
                $this->assertArrayHasKey('cert', $parameters['db']['ssl']);
                $this->assertEquals('sslcert', $parameters['db']['ssl']['cert']);
                $this->assertArrayHasKey('ca', $parameters['db']['ssl']);
                $this->assertEquals('sslca', $parameters['db']['ssl']['ca']);
            }

            if (!empty($parameters['tables'])) {
                $atLeastOneConfigurationHasTables = true;
                $this->assertArrayHasKey('id', $parameters['tables'][0]);
                $this->assertArrayHasKey('name', $parameters['tables'][0]);
                $this->assertArrayHasKey('query', $parameters['tables'][0]);
                $this->assertArrayHasKey('outputTable', $parameters['tables'][0]);
                $this->assertArrayHasKey('incremental', $parameters['tables'][0]);
                $this->assertInternalType('boolean', $parameters['tables'][0]['incremental']);
                $this->assertArrayHasKey('enabled', $parameters['tables'][0]);
                $this->assertInternalType('boolean', $parameters['tables'][0]['enabled']);
            }
        }
        $this->assertTrue($atLeastOneConfigurationHasTables);
    }

    public function testOrchestrationUpdate()
    {
        $oldComponentId = 'ex-db';
        $newComponentId = 'keboola.ex-db-mysql';
        $orchestratorService = new OrchestratorService(new Logger(APP_NAME));
        // create orchestration
        $orchestration = $orchestratorService->request('post', 'orchestrations', [
            'json' => [
                "name" => "Ex DB Migration Test Orchestrator",
                "tasks" => [
                    [
                        "component" => "ex-db",
                        "action" => "run",
                        "actionParameters" => [
                            "config" => "testing"
                        ],
                        "continueOnFailure" => false,
                        "timeoutMinutes" => null,
                        "active" => true
                    ]
                ]
            ]
        ]);

        // test affected orchestrations
        $affectedOrchestrations = $orchestratorService->getOrchestrations($oldComponentId, 'keboola.ex-db-');
        $this->assertNotEmpty($affectedOrchestrations);
        $orchestrationIsBetweenAffected = false;
        foreach ($affectedOrchestrations as $affected) {
            if ($affected['id'] == $orchestration['id']) {
                $this->assertTrue($affected['hasOld']);
                $orchestrationIsBetweenAffected = true;
            }
        }
        $this->assertTrue($orchestrationIsBetweenAffected);

        // test update orchestration
        $configuration = new Configuration();
        $configuration->setComponentId($newComponentId);
        $configuration->setConfigurationId('testing');
        $configuration->setName('Testing');

        $updatedOrchestrations = $orchestratorService->updateOrchestrations($oldComponentId, $configuration);
        $this->assertNotEmpty($updatedOrchestrations);
        $orchestrationIsUpdated = false;
        foreach ($updatedOrchestrations as $updated) {
            // is updated?
            if ($updated['id'] == $orchestration['id']) {
                $orchestrationIsUpdated = true;
            }
            // get tasks
            $tasks = $orchestratorService->getTasks($updated['id']);
            $this->assertNotEmpty($tasks);
            foreach ($tasks as $task) {
                if (isset($task['component'])) {
                    $this->assertEquals($newComponentId, $task['component']);
                }
                $this->assertNotEmpty($task['actionParameters']);
            }
        }
        $this->assertTrue($orchestrationIsUpdated);

        // check affected orchestration after migration
        $affectedOrchestrations = $orchestratorService->getOrchestrations($oldComponentId, 'keboola.ex-db-');
        $this->assertNotEmpty($affectedOrchestrations);
        foreach ($affectedOrchestrations as $affected) {
            if ($affected['id'] == $orchestration['id']) {
                $this->assertTrue($affected['hasNew']);
            }
        }

        // cleanup
        $orchestratorService->request('delete', sprintf('orchestrations/%s', $orchestration['id']));
    }

    public function testStatus()
    {
        $migration = new ExDbMigration(new Logger(APP_NAME));
        $status = $migration->status();

        $this->assertNotEmpty($status);
        $this->assertArrayHasKey('configId', $status['configurations'][0]);
        $this->assertArrayHasKey('configName', $status['configurations'][0]);
        $this->assertArrayHasKey('componentId', $status['configurations'][0]);
        $this->assertArrayHasKey('tableId', $status['configurations'][0]);
        $this->assertArrayHasKey('status', $status['configurations'][0]);
        $this->assertArrayHasKey('orchestrations', $status);
    }

    private function findConfigurationByName($configurations, $name)
    {
        /** @var Configuration $configuration */
        foreach ($configurations as $configuration) {
            if ($configuration->getName() == $name) {
                return $configuration;
            }
        }

        return false;
    }

    private function createOldConfig(Client $sapiClient, $driver)
    {
        $tableId = $sapiClient->createTable(
            'sys.c-ex-db',
            uniqid('test'),
            new CsvFile(ROOT_PATH . 'tests/data/ex-db/test.csv')
        );

        $sapiClient->setTableAttribute($tableId, 'accountId', 'testConfig' . $driver);
        $sapiClient->setTableAttribute($tableId, 'name', 'testConfig' . $driver);
        $sapiClient->setTableAttribute($tableId, 'desc', 'db-ex migration test account ' . $driver);
        $sapiClient->setTableAttribute($tableId, 'db.host', '127.0.0.1');
        $sapiClient->setTableAttribute($tableId, 'db.port', '3306');
        $sapiClient->setTableAttribute($tableId, 'db.user', 'root');
        $sapiClient->setTableAttribute($tableId, 'db.password', '123456');
        $sapiClient->setTableAttribute($tableId, 'db.database', 'test');
        $sapiClient->setTableAttribute($tableId, 'db.driver', $driver);

        if ($driver == 'mysql') {
            $sapiClient->setTableAttribute($tableId, 'db.ssl.key', 'sslkey');
            $sapiClient->setTableAttribute($tableId, 'db.ssl.cert', 'sslcert');
            $sapiClient->setTableAttribute($tableId, 'db.ssl.ca', 'sslca');
        }

        return $tableId;
    }

    private function createOldConfigEmpty(Client $sapiClient)
    {
        $tableId = $sapiClient->createTable(
            'sys.c-ex-db',
            uniqid('test'),
            new CsvFile(ROOT_PATH . 'tests/data/ex-db/test.csv')
        );

        $sapiClient->setTableAttribute($tableId, 'accountId', 'testConfigEmpty');
        $sapiClient->setTableAttribute($tableId, 'name', 'testConfigEmpty');
        $sapiClient->setTableAttribute($tableId, 'desc', 'db-ex migration test account - empty');

        return $tableId;
    }
}
