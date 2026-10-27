<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiHealth extends Controller
{
    private $api;

    public function __construct()
    {
        parent::__construct();
        $this->api = $this->call->library('api');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $this->api->respond([
            'status' => 'ok',
            'service' => 'CoopWise API',
            'time' => gmdate('c')
        ]);
    }
}
