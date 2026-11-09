<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Shared parent for application controllers.
 *
 * LavaLust calls before_action() before each controller action.
 */
class MY_Controller extends Controller
{
    public function before_action()
    {
        // Apply the API CORS policy to every application controller.
        handle_cors();
    }
}
