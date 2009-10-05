<?php
/**
 * @package sapphire
 * @subpackage tests
 */
class SiteTreeTest extends SapphireTest {
	static $fixture_file = 'sapphire/tests/SiteTreeTest.yml';
	
	/**
	 * @todo Necessary because of monolithic Translatable design
	 */
	static protected $origTranslatableSettings = array();
	
	static function set_up_once() {
		// needs to recreate the database schema with language properties
		self::kill_temp_db();

		// store old defaults	
		self::$origTranslatableSettings['has_extension'] = singleton('SiteTree')->hasExtension('Translatable');
		self::$origTranslatableSettings['default_locale'] = Translatable::default_locale();

		// overwrite locale
		Translatable::set_default_locale("en_US");

		// refresh the decorated statics - different fields in $db with Translatable enabled
		if(self::$origTranslatableSettings['has_extension']) 
			Object::remove_extension('SiteTree', 'Translatable');

		// clear singletons, they're caching old extension info which is used in DatabaseAdmin->doBuild()
		global $_SINGLETONS;
		$_SINGLETONS = array();

		// recreate database with new settings
		$dbname = self::create_temp_db();
		DB::set_alternative_database_name($dbname);

		parent::set_up_once();
	}
	
	static function tear_down_once() {
		if(self::$origTranslatableSettings['has_extension']) 
			Object::add_extension('SiteTree', 'Translatable');

		Translatable::set_default_locale(self::$origTranslatableSettings['default_locale']);
		Translatable::set_current_locale(self::$origTranslatableSettings['default_locale']);
		
		self::kill_temp_db();
		self::create_temp_db();
		
		parent::tear_down_once();
	}

	/**
	 * Test generation of the URLSegment values.
	 *  - Turns things into lowercase-hyphen-format
	 *  - Generates from Title by default, unless URLSegment is explicitly set
	 *  - Resolves duplicates by appending a number
	 *  - renames classes with a class name conflict
	 */
	function testURLGeneration() {
		$expectedURLs = array(
			'home' => 'home',
			'staff' => 'my-staff',
			'about' => 'about-us',
			'staffduplicate' => 'my-staff-2',
			'product1' => '1-1-test-product',
			'product2' => 'another-product',
			'product3' => 'another-product-2',
			'product4' => 'another-product-3',
			'object'   => 'object',
			'controller' => 'controller-2',
			'numericonly' => '1930',
		);
		
		foreach($expectedURLs as $fixture => $urlSegment) {
			$obj = $this->objFromFixture('Page', $fixture);
			$this->assertEquals($urlSegment, $obj->URLSegment);
		}
	}
	
	/**
	 * Test that publication copies data to SiteTree_Live
	 */
	function testPublishCopiesToLiveTable() {
		$obj = $this->objFromFixture('Page','about');
		$obj->publish('Stage', 'Live');
		
		$createdID = DB::query("SELECT \"ID\" FROM \"SiteTree_Live\" WHERE \"URLSegment\" = '$obj->URLSegment'")->value();
		$this->assertEquals($obj->ID, $createdID);
	}
	
	/**
	 * Test that field which are set and then cleared are also transferred to the published site.
	 */
	function testPublishDeletedFields() {
		$obj = $this->objFromFixture('Page', 'about');
		$obj->MetaTitle = "asdfasdf";
		$obj->write();
		$obj->doPublish();
		
		$this->assertEquals('asdfasdf', DB::query("SELECT \"MetaTitle\" FROM \"SiteTree_Live\" WHERE \"ID\" = '$obj->ID'")->value());

		$obj->MetaTitle = null;
		$obj->write();
		$obj->doPublish();

		$this->assertNull(DB::query("SELECT \"MetaTitle\" FROM \"SiteTree_Live\" WHERE \"ID\" = '$obj->ID'")->value());
		
	}
	
	function testParentNodeCachedInMemory() {
		$parent = new SiteTree();
     	$parent->Title = 'Section Title';
     	$child = new SiteTree();
     	$child->Title = 'Page Title';
		$child->setParent($parent);
		
		$this->assertType("SiteTree", $child->Parent);
		$this->assertEquals("Section Title", $child->Parent->Title);
	}
	
	function testParentModelReturnType() {
		$parent = new SiteTreeTest_PageNode();
		$child = new SiteTreeTest_PageNode();

		$child->setParent($parent);
		$this->assertType('SiteTreeTest_PageNode', $child->Parent);
	}
	
	/**
	 * Confirm that DataObject::get_one() gets records from SiteTree_Live
	 */
	function testGetOneFromLive() {
		$s = new SiteTree();
		$s->Title = "V1";
		$s->URLSegment = "get-one-test-page";
		$s->write();
		$s->publish("Stage", "Live");
		$s->Title = "V2";
		$s->write();
		
		$oldStage = Versioned::current_stage();
		Versioned::reading_stage('Live');
		
		$checkSiteTree = DataObject::get_one("SiteTree", "\"URLSegment\" = 'get-one-test-page'");
		$this->assertEquals("V1", $checkSiteTree->Title);

		Versioned::reading_stage($oldStage);
	}
	
	function testChidrenOfRootAreTopLevelPages() {
		$pages = DataObject::get("SiteTree");
		foreach($pages as $page) $page->publish('Stage', 'Live');
		unset($pages);
		
		/* If we create a new SiteTree object with ID = 0 */
		$obj = new SiteTree();
		/* Then its children should be the top-level pages */
		$stageChildren = $obj->stageChildren()->toDropDownMap('ID','Title');
		$liveChildren = $obj->liveChildren()->toDropDownMap('ID','Title');
		$allChildren = $obj->AllChildrenIncludingDeleted()->toDropDownMap('ID','Title');
		
		$this->assertContains('Home', $stageChildren);
		$this->assertContains('Products', $stageChildren);
		$this->assertNotContains('Staff', $stageChildren);

		$this->assertContains('Home', $liveChildren);
		$this->assertContains('Products', $liveChildren);
		$this->assertNotContains('Staff', $liveChildren);

		$this->assertContains('Home', $allChildren);
		$this->assertContains('Products', $allChildren);
		$this->assertNotContains('Staff', $allChildren);
	}

	function testCanSaveBlankToHasOneRelations() {
		/* DataObject::write() should save to a has_one relationship if you set a field called (relname)ID */
		$page = new SiteTree();
		$parentID = $this->idFromFixture('Page', 'home');
		$page->ParentID = $parentID;
		$page->write();
		$this->assertEquals($parentID, DB::query("SELECT \"ParentID\" FROM \"SiteTree\" WHERE \"ID\" = $page->ID")->value());

		/* You should then be able to save a null/0/'' value to the relation */
		$page->ParentID = null;
		$page->write();
		$this->assertEquals(0, DB::query("SELECT \"ParentID\" FROM \"SiteTree\" WHERE \"ID\" = $page->ID")->value());
	}
	
	function testStageStates() {
		// newly created page
		$createdPage = new SiteTree();
		$createdPage->write();
		$this->assertFalse($createdPage->IsDeletedFromStage);
		$this->assertTrue($createdPage->IsAddedToStage);
		$this->assertTrue($createdPage->IsModifiedOnStage);
		
		// published page 
		$publishedPage = new SiteTree();
		$publishedPage->write();
		$publishedPage->publish('Stage','Live');
		$this->assertFalse($publishedPage->IsDeletedFromStage);
		$this->assertFalse($publishedPage->IsAddedToStage);
		$this->assertFalse($publishedPage->IsModifiedOnStage); 
		
		// published page, deleted from stage
		$deletedFromDraftPage = new SiteTree();
		$deletedFromDraftPage->write();
		$deletedFromDraftPageID = $deletedFromDraftPage->ID;
		$deletedFromDraftPage->publish('Stage','Live');
		$deletedFromDraftPage->deleteFromStage('Stage');
		$this->assertTrue($deletedFromDraftPage->IsDeletedFromStage);
		$this->assertFalse($deletedFromDraftPage->IsAddedToStage);
		$this->assertFalse($deletedFromDraftPage->IsModifiedOnStage);
		
		// published page, deleted from live
		$deletedFromLivePage = new SiteTree();
		$deletedFromLivePage->write();
		$deletedFromLivePage->publish('Stage','Live');
		$deletedFromLivePage->deleteFromStage('Stage');
		$deletedFromLivePage->deleteFromStage('Live');
		$this->assertTrue($deletedFromLivePage->IsDeletedFromStage);
		$this->assertFalse($deletedFromLivePage->IsAddedToStage);
		$this->assertFalse($deletedFromLivePage->IsModifiedOnStage);
		
		// published page, modified
		$modifiedOnDraftPage = new SiteTree();
		$modifiedOnDraftPage->write();
		$modifiedOnDraftPage->publish('Stage','Live');
		$modifiedOnDraftPage->Content = 'modified';
		$modifiedOnDraftPage->write();
		$this->assertFalse($modifiedOnDraftPage->IsDeletedFromStage);
		$this->assertFalse($modifiedOnDraftPage->IsAddedToStage);
		$this->assertTrue($modifiedOnDraftPage->IsModifiedOnStage);
	}
	
	/**
	 * Test that a page can be completely deleted and restored to the stage site
	 */
	function testRestoreToStage() {
		$page = $this->objFromFixture('Page', 'about');
		$pageID = $page->ID;
		$page->delete();
		$this->assertTrue(!DataObject::get_by_id("Page", $pageID));
		
		$deletedPage = Versioned::get_latest_version('SiteTree', $pageID);
		$resultPage = $deletedPage->doRestoreToStage();
		
		$requeriedPage = DataObject::get_by_id("Page", $pageID);
		
		$this->assertEquals($pageID, $resultPage->ID);
		$this->assertEquals($pageID, $requeriedPage->ID);
		$this->assertEquals('About Us', $requeriedPage->Title);
		$this->assertEquals('Page', $requeriedPage->class);


		$page2 = $this->objFromFixture('Page', 'products');
		$page2ID = $page2->ID;
		$page2->doUnpublish();
		$page2->delete();
		
		// Check that if we restore while on the live site that the content still gets pushed to
		// stage
		Versioned::reading_stage('Live');
		$deletedPage = Versioned::get_latest_version('SiteTree', $page2ID);
		$deletedPage->doRestoreToStage();
		$this->assertTrue(!Versioned::get_one_by_stage("Page", "Live", "\"SiteTree\".\"ID\" = " . $page2ID));

		Versioned::reading_stage('Stage');
		$requeriedPage = DataObject::get_by_id("Page", $page2ID);
		$this->assertEquals('Products', $requeriedPage->Title);
		$this->assertEquals('Page', $requeriedPage->class);
		
	}

	/**
	 * Test SiteTree::get_by_url()
	 */
	function testGetByURL() {
		// Test basic get by url
		$this->assertEquals($this->idFromFixture('Page', 'home'), SiteTree::get_by_url("home")->ID);

		// Test the extraFilter argument
		// Note: One day, it would be more appropriate to return null instead of false for queries such as these
		$this->assertFalse(SiteTree::get_by_url("home", "1 = 2"));
	}
	

	function testDeleteFromStageOperatesRecursively() {
		$pageAbout = $this->objFromFixture('Page', 'about');
		$pageStaff = $this->objFromFixture('Page', 'staff');
		$pageStaffDuplicate = $this->objFromFixture('Page', 'staffduplicate');
		
		$pageAbout->delete();
		
		$this->assertFalse(DataObject::get_by_id('Page', $pageAbout->ID));
		$this->assertFalse(DataObject::get_by_id('Page', $pageStaff->ID));
		$this->assertFalse(DataObject::get_by_id('Page', $pageStaffDuplicate->ID));
	}

	function testDeleteFromLiveOperatesRecursively() {
		$pageAbout = $this->objFromFixture('Page', 'about');
		$pageAbout->doPublish();
		$pageStaff = $this->objFromFixture('Page', 'staff');
		$pageStaff->doPublish();
		$pageStaffDuplicate = $this->objFromFixture('Page', 'staffduplicate');
		$pageStaffDuplicate->doPublish();
		
		$parentPage = $this->objFromFixture('Page', 'about');
		$parentPage->doDeleteFromLive();
		
		Versioned::reading_stage('Live');
		$this->assertFalse(DataObject::get_by_id('Page', $pageAbout->ID));
		$this->assertFalse(DataObject::get_by_id('Page', $pageStaff->ID));
		$this->assertFalse(DataObject::get_by_id('Page', $pageStaffDuplicate->ID));
		Versioned::reading_stage('Stage');
	}
	
	/**
	 * Simple test to confirm that querying from a particular archive date doesn't throw
	 * an error
	 */
	function testReadArchiveDate() {
		Versioned::reading_archived_date('2009-07-02 14:05:07');
		
		DataObject::get('SiteTree', "\"ParentID\" = 0");
		
		Versioned::reading_archived_date(null);
	}
	
	function testEditPermissions() {
		$editor = $this->objFromFixture("Member", "editor");
		
		$home = $this->objFromFixture("Page", "home");
		$products = $this->objFromFixture("Page", "products");
		$product1 = $this->objFromFixture("Page", "product1");
		$product4 = $this->objFromFixture("Page", "product4");

		// Can't edit a page that is locked to admins
		$this->assertFalse($home->canEdit($editor));
		
		// Can edit a page that is locked to editors
		$this->assertTrue($products->canEdit($editor));
		
		// Can edit a child of that page that inherits
		$this->assertTrue($product1->canEdit($editor));
		
		// Can't edit a child of that page that has its permissions overridden
		$this->assertFalse($product4->canEdit($editor));
	}

	function testAuthorIDAndPublisherIDFilledOutOnPublish() {
		// Ensure that we have a member ID who is doing all this work
		$member = Member::currentUser();
		if($member) {
			$memberID = $member->ID;
		} else {
			$memberID = $this->idFromFixture("Member", "admin");
			Session::set("loggedInAs", $memberID);
		}

		// Write the page
		$about = $this->objFromFixture('Page','about');
		$about->Title = "Another title";
		$about->write();
		
		// Check the version created
		$savedVersion = DB::query("SELECT \"AuthorID\", \"PublisherID\" FROM \"SiteTree_versions\" 
			WHERE \"RecordID\" = $about->ID ORDER BY \"Version\" DESC LIMIT 1")->record();
		$this->assertEquals($memberID, $savedVersion['AuthorID']);
		$this->assertEquals(0, $savedVersion['PublisherID']);
		
		// Publish the page
		$about->doPublish();
		$publishedVersion = DB::query("SELECT \"AuthorID\", \"PublisherID\" FROM \"SiteTree_versions\" 
			WHERE \"RecordID\" = $about->ID ORDER BY \"Version\" DESC LIMIT 1")->record();
			
		// Check the version created
		$this->assertEquals($memberID, $publishedVersion['AuthorID']);
		$this->assertEquals($memberID, $publishedVersion['PublisherID']);
		
	}

}

// We make these extend page since that's what all page types are expected to do
class SiteTreeTest_PageNode extends Page implements TestOnly { }
class SiteTreeTest_PageNode_Controller extends Page_Controller implements TestOnly { 
}

?>