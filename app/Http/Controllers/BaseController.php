<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BaseController extends Controller
{
    public function sendResponse($result, $message, $status = true)
    {
        $version  = request()->attributes->get('app_version');
        $language = request()->attributes->get('language', 'en');

        if ($version == null) {
            return response()->json([
                'success' => false,
                'message' => "Unauthorised Access",
            ], 200);
        }

        return response()->json([
            'version'  => $version,
            'language' => $language,
            'success'  => $status,
            'message'  => $message,
            'data'     => $result,
        ], 200);
    }

    public function sendError($error, $errorMessages = [], $code = 400)
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['data'] = $errorMessages;
        }

        return response()->json($response, $code);
    }
}
