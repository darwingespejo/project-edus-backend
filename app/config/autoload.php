<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/*
| AUTO-LOADER — edusphere-api
| The database connects automatically (every endpoint needs it) and the
| api helper (envelope, guards, CORS, aliases) loads before every controller,
| so controllers never re-declare it via $this->call->helper('api').
*/

$autoload['libraries'] = array('database');
$autoload['helpers']   = array('api');
$autoload['models']    = array();
$autoload['configs']   = array();
