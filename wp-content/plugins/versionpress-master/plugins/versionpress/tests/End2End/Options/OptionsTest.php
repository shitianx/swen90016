<?php

namespace VersionPress\Tests\End2End\Options;

use VersionPress\Tests\End2End\Utils\End2EndTestCase;
use VersionPress\Tests\Utils\DBAsserter;

class OptionsTest extends End2EndTestCase
{

    /** @var IOptionsTestWorker */
    private static $worker;

    /**
     * @test
     * @testdox Changing option creates 'option/update' action
     */
    public function changingOptionCreatesOptionEditAction()
    {
        self::$worker->prepare_changeOption();

        $commitAsserter = $this->newCommitAsserter();

        self::$worker->changeOption();

        $commitAsserter->assertNumCommits(1);
        $commitAsserter->assertCommitAction('option/update');
        $commitAsserter->assertCommitPath('M', '%vpdb%/options/%VPID%.ini');
        $commitAsserter->assertCleanWorkingDirectory();
        DBAsserter::assertFilesEqualDatabase();
    }

    /**
     * @test
     * @testdox Changing more option creates bulk 'option/update' action
     */
    public function changingMoreOptionsCreatesOptionEditAction()
    {
        self::$worker->prepare_changeTwoOptions();

        $commitAsserter = $this->newCommitAsserter();

        self::$worker->changeTwoOptions();

        $commitAsserter->assertNumCommits(1);
        $commitAsserter->assertBulkAction('option/update', 2);
        $commitAsserter->assertCommitPath('M', '%vpdb%/options/%VPID%.ini');
        $commitAsserter->assertCleanWorkingDirectory();
        DBAsserter::assertFilesEqualDatabase();
    }
}
