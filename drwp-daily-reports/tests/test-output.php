<?php
/**
 * @covers DRWP_Output
 * @covers DRWP_CPT
 */
class Test_DRWP_Output extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        delete_option(DRWP_Output::OPT_POST_TYPE);
        delete_option(DRWP_Output::OPT_AUTO_THUMBNAIL);
    }

    public function test_post_type_defaults_to_post() {
        $this->assertSame('post', DRWP_Output::post_type());
    }

    public function test_post_type_accepts_drwp_report() {
        DRWP_Output::save_settings(['post_type' => 'drwp_report', 'auto_thumbnail' => true]);
        $this->assertSame('drwp_report', DRWP_Output::post_type());
    }

    public function test_post_type_rejects_unknown_value() {
        DRWP_Output::save_settings(['post_type' => 'page', 'auto_thumbnail' => true]);
        // Invalid input falls back to 'post' (defensive: prevents writing
        // arbitrary post types via settings save).
        $this->assertSame('post', DRWP_Output::post_type());
    }

    public function test_auto_thumbnail_default_on() {
        $this->assertTrue(DRWP_Output::auto_thumbnail());
    }

    public function test_auto_thumbnail_can_be_disabled() {
        DRWP_Output::save_settings(['post_type' => 'post', 'auto_thumbnail' => false]);
        $this->assertFalse(DRWP_Output::auto_thumbnail());
    }

    public function test_cpt_drwp_report_is_registered() {
        // DRWP_CPT::init() registers on the 'init' action which fires
        // during WP_UnitTestCase bootstrap.
        $obj = get_post_type_object(DRWP_CPT::POST_TYPE);
        $this->assertNotNull($obj);
        $this->assertTrue($obj->public);
        $this->assertTrue(post_type_supports(DRWP_CPT::POST_TYPE, 'thumbnail'));
        $this->assertContains('category', get_object_taxonomies(DRWP_CPT::POST_TYPE));
    }
}
