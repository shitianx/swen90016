<?php

namespace VersionPress\Tests\Workflow;

use PHPUnit_Framework_TestCase;
use VersionPress\Cli\VPCommandUtils;
use VersionPress\Tests\Automation\WpAutomation;
use VersionPress\Tests\Utils\SiteConfig;
use VersionPress\Tests\Utils\TestConfig;
use VersionPress\Utils\FileSystem;

class CloneMergeTest extends PHPUnit_Framework_TestCase
{

    /** @var TestConfig */
    private static $testConfig;
    /** @var SiteConfig */
    private static $siteConfig;
    /** @var SiteConfig */
    private static $cloneSiteConfig;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        self::$testConfig = TestConfig::createDefaultConfig();
        self::$siteConfig = self::$testConfig->testSite;

        self::$cloneSiteConfig = self::getCloneSiteConfig(self::$siteConfig);
    }

    /**
     * @test
     */
    public function cloneLooksExactlySameAsOriginal()
    {
        // Currently, we use an empty site as a basic test.
        $wpAutomation = new WpAutomation(self::$siteConfig, self::$testConfig->wpCliVersion);
        $wpAutomation->setUpSite();
        $wpAutomation->copyVersionPressFiles();
        $wpAutomation->initializeVersionPress();

        FileSystem::mkdir(self::$cloneSiteConfig->path);

        $wpAutomation = new WpAutomation(self::$siteConfig, self::$testConfig->wpCliVersion);
        $wpAutomation->runWpCliCommand('vp', 'clone', [
            'name' => self::$cloneSiteConfig->name,
            'dbprefix' => self::$cloneSiteConfig->dbTablePrefix,
            'yes' => null
        ]);

        $this->assertCloneLooksExactlySameAsOriginal();
    }

    /**
     * @test
     * @depends cloneLooksExactlySameAsOriginal
     */
    public function updatedCloneCanBeMergedBack()
    {
        $cloneWpAutomation = new WpAutomation(self::$cloneSiteConfig, self::$testConfig->wpCliVersion);
        $cloneWpAutomation->editOption('blogname', 'Blogname from clone');

        $wpAutomation = new WpAutomation(self::$siteConfig, self::$testConfig->wpCliVersion);
        $wpAutomation->runWpCliCommand('vp', 'pull', ['from' => self::$cloneSiteConfig->name]);

        $this->assertCloneLooksExactlySameAsOriginal();
    }

    /**
     * @test
     * @depends cloneLooksExactlySameAsOriginal
     */
    public function updatedSiteCanBePushedToClone()
    {
        $wpAutomation = new WpAutomation(self::$siteConfig, self::$testConfig->wpCliVersion);
        $wpAutomation->editOption('blogname', 'Blogname from original');

        $wpAutomation->runWpCliCommand('vp', 'push', ['to' => self::$cloneSiteConfig->name]);

        $this->assertCloneLooksExactlySameAsOriginal();
    }

    /**
     * @test
     *
     * Creates a post and edits it (compatibly) in both environments. This leads to two different date modified's
     * but they should still merge fine if our merge driver works correctly.
     */
    public function dateModifiedMergesAutomatically()
    {
        $internalCommandPath = __DIR__ . '/../../src/Cli/vp-internal.php';

        $wpAutomation = new WpAutomation(self::$siteConfig, self::$testConfig->wpCliVersion);
        $cloneWpAutomation = new WpAutomation(self::$cloneSiteConfig, self::$testConfig->wpCliVersion);

        $wpAutomation->ensureTestSiteIsReady();

        $post = [
            "post_type" => "page",
            "post_status" => "publish",
            "post_title" => "Test page for menu",
            "post_date" => "2011-11-11 11:11:11",
            "post_content" => "Test page",
            "post_author" => 1
        ];
        $postId = $wpAutomation->createPost($post);
        $postVpId = $wpAutomation->runWpCliCommand(
            'vp-internal',
            'get-entity-vpid',
            ['require' => $internalCommandPath, 'id' => $postId, 'name' => 'posts']
        );

        $wpAutomation->runWpCliCommand('vp', 'clone', ['name' => self::$cloneSiteConfig->name, 'yes' => null]);

        $wpAutomation->editPost($postId, ['post_title' => 'Some new title']);

        // We need to sleep for at least a second to get different date modified's;
        // WP-CLI / WordPress don't allow setting `post_modified` so we need to use this.
        sleep(1);

        $clonedPostId = $cloneWpAutomation->runWpCliCommand(
            'vp-internal',
            'get-entity-id',
            ['require' => $internalCommandPath, 'vpid' => $postVpId]
        );
        $cloneWpAutomation->editPost($clonedPostId, ['post_content' => 'Some new content']);

        VPCommandUtils::exec('git config user.name test', self::$siteConfig->path);
        VPCommandUtils::exec('git config user.email test@example.com', self::$siteConfig->path);

        $wpAutomation->runWpCliCommand('vp', 'pull', ['from' => self::$cloneSiteConfig->name]);

        $modifiedDate = $wpAutomation->runWpCliCommand('post get', $postId, ['field' => 'post_modified']);
        $clonedModifiedDate = $cloneWpAutomation->runWpCliCommand(
            'post get',
            $clonedPostId,
            ['field' => 'post_modified']
        );

        $modifiedDateGmt = $wpAutomation->runWpCliCommand('post get', $postId, ['field' => 'post_modified_gmt']);
        $clonedModifiedDateGmt = $cloneWpAutomation->runWpCliCommand(
            'post get',
            $clonedPostId,
            ['field' => 'post_modified_gmt']
        );

        $this->assertEquals($clonedModifiedDate, $modifiedDate);
        $this->assertEquals($clonedModifiedDateGmt, $modifiedDateGmt);
    }


    /**
     * @test
     *
     */
    public function sitesAreNotMergedIfThereIsConflict()
    {
        $cloneWpAutomation = new WpAutomation(self::$cloneSiteConfig, self::$testConfig->wpCliVersion);
        $cloneWpAutomation->editOption('blogname', 'Blogname from clone - conflict');

        $wpAutomation = new WpAutomation(self::$siteConfig, self::$testConfig->wpCliVersion);
        $wpAutomation->editOption('blogname', 'Blogname from original - conflict');

        VPCommandUtils::exec('git config user.name test', self::$siteConfig->path);
        VPCommandUtils::exec('git config user.email test@example.com', self::$siteConfig->path);

        $output = $wpAutomation->runWpCliCommand('vp', 'pull', ['from' => self::$cloneSiteConfig->name]);

        $this->assertContains("Pull aborted", $output);
    }

    /**
     * Returns SiteConfig for the clone site. Uses "clone" suffix.
     *
     * @param SiteConfig $testSite
     * @return SiteConfig
     */
    private static function getCloneSiteConfig(SiteConfig $testSite)
    {

        $siteName = $testSite->name . 'clone';

        $testSite = clone $testSite;
        $testSite->name = $siteName;
        $testSite->path = dirname($testSite->path) . "/$siteName";
        $testSite->url = dirname($testSite->url) . "/$siteName";
        $testSite->dbTablePrefix = "wp_{$siteName}_";

        return $testSite;
    }

    private function getTextContentAtUrl($url)
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML(file_get_contents($url));
        return $dom->textContent;
    }

    private function assertCloneLooksExactlySameAsOriginal()
    {
        $origContent = $this->getTextContentAtUrl(self::$siteConfig->url);

        $cloneContent = str_replace(
            self::$cloneSiteConfig->name,
            self::$siteConfig->name,
            $this->getTextContentAtUrl(self::$cloneSiteConfig->url)
        );

        $this->assertEquals($origContent, $cloneContent);
    }
}
