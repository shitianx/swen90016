<?php
namespace VersionPress\Tests\LoadTests;

use VersionPress\Git\MergeDriverInstaller;
use VersionPress\Tests\Utils\MergeDriverTestUtils;

class MergeDriverLoadTest extends \PHPUnit_Framework_TestCase
{

    private static $repositoryDir;

    /**
     * !!! INCREASE THIS to something like 100 or 1000 to get more real-world results.
     * The default is low so that if someone accidentally runs all tests, it doesn't block it.
     */
    const ITERATIONS = 1;

    public static function setUpBeforeClass()
    {
        self::$repositoryDir = sys_get_temp_dir() . '/repository';
    }

    public function setUp()
    {
        MergeDriverTestUtils::initRepository(self::$repositoryDir);
    }

    public function tearDown()
    {
        MergeDriverTestUtils::destroyRepository();
    }

    /**
     * @param string $driver See MergeDriverInstaller::installMergeDriver()'s $driver parameter
     */
    private function installMergeDriver($driver)
    {
        MergeDriverInstaller::installMergeDriver(
            self::$repositoryDir,
            __DIR__ . '/../..',
            self::$repositoryDir,
            $driver
        );
    }


    /**
     * @test
     */
    public function phpDriverLoadTested()
    {

        $this->installMergeDriver(MergeDriverInstaller::DRIVER_PHP);
        $this->prepareTestRepositoryHistory();

        $time_start = microtime(true);
        $mergeCommandExitCode = MergeDriverTestUtils::runGitCommand('git merge test-branch');
        $time_end = microtime(true);

        $execution_time = ($time_end - $time_start);
        echo 'Php Execution Time: ' . $execution_time . " Sec\n";

        $this->assertEquals(0, $mergeCommandExitCode);

    }

    /**
     * @test
     */
    public function bashDriverLoadTested()
    {

        if (DIRECTORY_SEPARATOR == '\\') {
            $this->markTestSkipped('No Bash on Windows.');
            return;
        }

        $this->installMergeDriver(MergeDriverInstaller::DRIVER_BASH);
        $this->prepareTestRepositoryHistory();

        $time_start = microtime(true);
        $mergeCommandExitCode = MergeDriverTestUtils::runGitCommand('git merge test-branch');
        $time_end = microtime(true);

        $execution_time = ($time_end - $time_start);
        echo 'Bash Execution Time: ' . $execution_time . " Sec\n";

        $this->assertEquals(0, $mergeCommandExitCode);
    }

    private function prepareTestRepositoryHistory()
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            MergeDriverTestUtils::writeIniFile('file' . $i . '.ini', '2011-11-11 11:11:11');
        }
        MergeDriverTestUtils::commit('Initial commit to Ancestor');
        MergeDriverTestUtils::runGitCommand('git checkout -b test-branch');
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            MergeDriverTestUtils::writeIniFile('file' . $i . '.ini', '2012-12-12 12:12:12', 'Custom content');
        }
        MergeDriverTestUtils::commit('Commit to branch');
        MergeDriverTestUtils::runGitCommand('git checkout master');
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            MergeDriverTestUtils::writeIniFile('file' . $i . '.ini', '2013-03-03 13:13:13');
        }
        MergeDriverTestUtils::commit('Commit to master');
    }
}
