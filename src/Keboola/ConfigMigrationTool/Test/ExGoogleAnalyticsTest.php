<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 01/07/16
 * Time: 10:18
 */

namespace Keboola\ConfigMigrationTool\Test;

use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Client;

class ExGoogleAnalyticsTest extends \PHPUnit_Framework_TestCase
{
    /** @var Client */
    protected $sapiClient;

    public function setUp()
    {
        $this->sapiClient = new Client(['token' => getenv('KBC_TOKEN')]);

        // cleanup
        $tables = $this->sapiClient->listTables('sys.c-ex-google-analytics');
        foreach ($tables as $table) {
            if (false !== strstr($table['id'], 'migrationtest')) {
                $this->sapiClient->dropTable($table['id']);
            }
        }
    }

    protected function createOldConfig()
    {
        $id = uniqid('migrationtest');

        $tableId = $this->sapiClient->createTable(
            'sys.c-ex-google-analytics',
            $id,
            new CsvFile(ROOT_PATH . 'tests/data/ex-google-analytics/migration-test.csv')
        );

        $this->sapiClient->setTableAttribute($tableId, 'id', $id);
        $this->sapiClient->setTableAttribute($tableId, 'accountName', $id);
        $this->sapiClient->setTableAttribute($tableId, 'description', 'Migrate this account');
        $this->sapiClient->setTableAttribute($tableId, 'outputBucket', 'migrationtest');
        $this->sapiClient->setTableAttribute($tableId, 'googleId', getenv('GOOGLE_ACCOUNT_ID'));
        $this->sapiClient->setTableAttribute($tableId, 'googleName', 'Some User Name');
        $this->sapiClient->setTableAttribute($tableId, 'email', getenv('GOOGLE_ACCOUNT_EMAIL'));
        $this->sapiClient->setTableAttribute($tableId, 'owner', getenv('GOOGLE_ACCOUNT_EMAIL'));
        $this->sapiClient->setTableAttribute($tableId, 'accessToken', getenv('GOOGLE_ACCESS_TOKEN'));
        $this->sapiClient->setTableAttribute($tableId, 'refreshToken', getenv('GOOGLE_REFRESH_TOKEN'));

        return $id;
    }

    protected function createOldConfigs()
    {
        $testTables = [];
        $testTables[] = $this->createOldConfig();
        $testTables[] = $this->createOldConfig();
        $testTables[] = $this->createOldConfig();

        return $testTables;
    }
}