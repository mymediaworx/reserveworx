<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Hosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * The platform host shows the product page. A tenant domain goes to its staff area
     * until the public booking page lands.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        if (Hosts::isPlatform($request)) {
            return view('welcome');
        }

        return redirect('/staff');
    }
}
