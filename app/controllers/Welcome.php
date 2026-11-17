<?php
// This file loads the welcome page.

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Welcome extends Controller {
	// This method loads the main information for this page or API.
	public function index() {
		$this->call->view('welcome_page');
	}
}
?>