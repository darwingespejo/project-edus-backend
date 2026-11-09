<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

// LavaLust discovers MY_* classes in app/kernel; keep the implementation
// alongside the application controllers and load it from here.
require_once APP_DIR . 'controllers/MY_Controller.php';
