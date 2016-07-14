<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 01/07/16
 * Time: 10:18
 */

namespace Keboola\ConfigMigrationTool\Test;

use Keboola\ConfigMigrationTool\Helper\TableHelper;
use Keboola\ConfigMigrationTool\Service\StorageApiService;
use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Options\Components\Configuration;

class ExGoogleAnalyticsTest extends \PHPUnit_Framework_TestCase
{
    /** @var StorageApiService */
    protected $sapiService;

    public function setUp()
    {
        $this->sapiService = new StorageApiService();

        // cleanup
        $tables = $this->sapiService->getClient()->listTables('sys.c-ex-google-analytics');
        foreach ($tables as $table) {
            $attributes = TableHelper::formatAttributes($table['attributes']);
            if (false !== strstr($table['id'], 'migrationtest')) {
                $this->sapiService->getClient()->dropTable($table['id']);

                try {
                    $this->sapiService->deleteConfiguration('ex-google-analytics', $attributes['id']);
                } catch (\Exception $e) {
                }
            }
        }
    }

    protected function createOldConfig()
    {
        $id = uniqid('migrationtest');

        $sapiClient = $this->sapiService->getClient();
        $tableId = $sapiClient->createTable(
            'sys.c-ex-google-analytics',
            $id,
            new CsvFile(ROOT_PATH . 'tests/data/ex-google-analytics/migration-test.csv')
        );

        $sapiClient->setTableAttribute($tableId, 'id', $id);
        $sapiClient->setTableAttribute($tableId, 'accountName', $id);
        $sapiClient->setTableAttribute($tableId, 'description', 'Migrate this account');
        $sapiClient->setTableAttribute($tableId, 'outputBucket', 'migrationtest');
        $sapiClient->setTableAttribute($tableId, 'googleId', getenv('GOOGLE_ACCOUNT_ID'));
        $sapiClient->setTableAttribute($tableId, 'googleName', 'Some User Name');
        $sapiClient->setTableAttribute($tableId, 'email', getenv('GOOGLE_ACCOUNT_EMAIL'));
        $sapiClient->setTableAttribute($tableId, 'owner', getenv('GOOGLE_ACCOUNT_EMAIL'));
        $sapiClient->setTableAttribute($tableId, 'accessToken', getenv('GOOGLE_ACCESS_TOKEN'));
        $sapiClient->setTableAttribute($tableId, 'refreshToken', getenv('GOOGLE_REFRESH_TOKEN'));

        $queries = $this->createQueriesConfig();
        if (rand(0,9) >= 5) {
            $queriesArr = json_decode($queries, true);
            $queriesArr['Users']['profile'] = 69127714;
            $queries = json_encode($queriesArr);
        }

        $sapiClient->setTableAttribute($tableId, 'configuration', $queries);

        // create config in SAPI
        $configuration = new Configuration();
        $configuration->setComponentId('ex-google-analytics');
        $configuration->setConfigurationId($id);
        $configuration->setName($id);
        $configuration->setDescription('Migrate this account');
        $this->sapiService->createConfiguration($configuration);

        return $id;
    }

    protected function createOldConfigs()
    {
        $testTables = [];
        for ($i=0;$i<5;$i++) {
            $testTables[] = $this->createOldConfig();
        }

        return $testTables;
    }

    protected function createQueriesConfig()
    {
        return '{"Users":{"metrics":["users"],"dimensions":["sourceMedium"]},"OrganicTraffic":{"name":"OrganicTraffic","metrics":["users","sessions"],"dimensions":["date","segment"],"filters":["ga:timeOnPage>10"],"segment":"gaid::-5"}}';
    }
}
