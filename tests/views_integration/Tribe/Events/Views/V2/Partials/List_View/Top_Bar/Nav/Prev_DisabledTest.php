<?php

namespace Tribe\Events\Views\V2\Partials\List_View\Top_Bar\Nav;

use Tribe\Test\Products\WPBrowser\Views\V2\HtmlPartialTestCase;

class Prev_DisabledTest extends HtmlPartialTestCase
{

	protected $partial_path = 'list/top-bar/nav/prev-disabled';

	/**
	 * Test render
	 */
	public function test_render() {
		$this->assertMatchesSnapshot( $this->get_partial_html() );
	}
}
