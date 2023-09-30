<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Throwable;

class DropDownController extends BaseController
{
    public function bannerDaysDD(Request $request)
    {
        try {
            $days = config('constants.banner_days');
            return $this->sendResponse($days, 'Days successfully Retrieved...!');
        } catch (Throwable $ex) {
            return $this->sendError($ex->getMessage(), '', 200);       
        }
    }

    public function bannerLevelsDD(Request $request)
    {
        try {
            $banner_levels = config('constants.banner_levels');
            return $this->sendResponse($banner_levels, 'Banner Levels successfully Retrieved...!');
        } catch (Throwable $ex) {
            return $this->sendError($ex->getMessage(), '', 200);       
        }
    }

    public function bannerImageOrientationDD(Request $request)
    {
        try {
            $image_orientation = config('constants.image_orientation');
            return $this->sendResponse($image_orientation, 'Image Orientation successfully Retrieved...!');
        } catch (Throwable $ex) {
            return $this->sendError($ex->getMessage(), '', 200);       
        }
    }
}
