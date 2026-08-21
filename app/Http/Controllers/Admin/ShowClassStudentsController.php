<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use Illuminate\View\View;

class ShowClassStudentsController extends Controller
{
    public function __invoke(SchoolClass $schoolClass): View
    {
        return view('admin.classes.students', compact('schoolClass'));
    }
}
