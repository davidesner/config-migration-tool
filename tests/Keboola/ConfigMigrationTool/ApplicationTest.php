<?php
/**
 * @copy Keboola
 * @author Jakub Matejka <jakub@keboola.com>
 */

namespace Keboola\ConfigMigrationTool\Test;

use Keboola\ConfigMigrationTool\Application;
use Keboola\ConfigMigrationTool\Exception\UserException;
use Keboola\ConfigMigrationTool\Migration\ExGoogleAnalyticsMigration;
use Keboola\ConfigMigrationTool\Migration\GenericCopyMigration;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class ApplicationTest extends \PHPUnit_Framework_TestCase
{
    /** @var  Application */
    protected $application;

    protected function setUp()
    {
        parent::setUp();
        $this->application = new Application(
            new Logger('test', [new NullHandler()])
        );
    }

    public function testApplicationGetMigration()
    {
        $result = $this->application->getMigration(['parameters' => ['component' => 'ex-google-analytics']]);
        $this->assertInstanceOf(ExGoogleAnalyticsMigration::class, $result);

        try {
            $this->application->getMigration(['parameters' => ['component' => 'ex-google-analyticsx']]);
            $this->fail();
        } catch (UserException $e) {
        }

        $result = $this->application->getMigration(['parameters' => [
            'origin' => 'ex-adwords-v2', 'destination' => 'keboola.ex-adwords-v201705'
        ]]);
        $this->assertInstanceOf(GenericCopyMigration::class, $result);

        try {
            $this->application->getMigration(['parameters' => [
                'origin' => 'ex-adwords-v2x', 'destination' => 'keboola.ex-adwords-v201705'
            ]]);
            $this->fail();
        } catch (UserException $e) {
        }

        try {
            $this->application->getMigration(['parameters' => [
                'origin' => 'ex-adwords-v2', 'destination' => 'keboola.ex-adwords-v201705x'
            ]]);
            $this->fail();
        } catch (UserException $e) {
        }
    }

    public function testApplicationGetSupportedMigrations()
    {
        $res = $this->application->action(['action' => 'supported-migrations']);
        $this->assertArrayHasKey('ex-adwords-v2', $res);
        $this->assertCount(1, $res['ex-adwords-v2']);
        $this->assertContains('keboola.ex-adwords-v201705', $res['ex-adwords-v2']);
    }
}
