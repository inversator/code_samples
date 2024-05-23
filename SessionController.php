<?php

namespace App\Http\Controllers\WEB\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\{Response, JsonResponse};
use DB;

/**
 * Class SessionController
 * @package App\Http\Controllers\WEB\Auth
 */
class SessionController extends Controller
{
    /**
     * @return JsonResponse
     */
    function index(): JsonResponse
    {
        try {
            // respond with 200 OK and request data
            return jsonResponse(
                Response::HTTP_OK,
                "Success",
                "Success message",
                [
                    'session' => request()->get('session'),
                    'session_user' => currentUser()
                ]
            );
        } catch (Exception $e) {
            // Log errors
            report($e);

            // Respond with error message
            return jsonResponse(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                "Error",
                "Server Error",
                false,
                $e
            );
        }
    }
}
