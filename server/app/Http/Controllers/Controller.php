<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasApiResponses;

abstract class Controller
{
    use HasApiResponses;
}
