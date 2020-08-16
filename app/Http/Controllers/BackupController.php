<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function index()
    {
        $maxExecutionTime = ini_get("max_execution_time");
        return view('index', [
            'refresh_time' => $maxExecutionTime / 60
        ]);
    }
}
