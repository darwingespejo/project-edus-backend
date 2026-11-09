<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminController extends MY_Controller
{
    private $users;
    private $admin;

    public function __construct()
    {
        parent::__construct();
        $this->users = $this->call->model('User_model');
        $this->admin = $this->call->model('Admin_model');
    }

    public function analytics()
    {
        require_role($this->users, ['admin']);
        respond($this->admin->analytics());
    }

    public function users()
    {
        require_role($this->users, ['admin']);
        respond($this->users->adminLists());
    }

    public function update_user()
    {
        $admin = require_role($this->users, ['admin']);
        $body = json_body();
        if (empty($body['id'])) fail_api('Missing user id.', 422);
        if ($body['id'] === $admin['id'] && empty($body['active'])) {
            fail_api("You can't deactivate your own account.", 409);
        }

        $active = !empty($body['active']);
        $this->users->setActive($body['id'], $active);
        respond(null, $active ? 'Account reactivated.' : 'Account deactivated.');
    }
}
