<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use Illuminate\View\View;

class ShowAttendanceController extends Controller
{
    public function __invoke(SchoolClass $schoolClass): View
    {
        return view('attendance.show', compact('schoolClass'));
    }
}
