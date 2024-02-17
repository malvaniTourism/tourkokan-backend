<?php

use Illuminate\Support\Facades\DB;
use App\Models\City;
use App\Models\User;
use App\Models\Place;
use App\Models\Projects;
use App\Models\Product;
use App\Models\Photos;
use App\Models\Blog;
use App\Models\Food;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

function currentDate()
{
    $date = date('YmdHis');
    return $date;
}

function getDbColumns($tableName)
{
    return DB::getSchemaBuilder()->getColumnListing($tableName);
}

function getData($id, $model)
{
    switch ($model) {
        case 'City':
            $data = City::find($id);
            break;

        case 'User':
            $data = User::find($id);
            break;

        case 'Projects':
            $data = Projects::find($id);
            break;

        case 'Products':
            $data = Product::find($id);
            break;

        case 'Place':
            $data = Place::find($id);
            break;

        case 'Photos':
            $data = Photos::find($id);
            break;

        case 'Blog':
            $data = Blog::find($id);
            break;

        case 'Food':
            $data = Food::find($id);
            break;

        case 'Sites':
            $data = Site::find($id);
            break;

        default:
            # code...
            break;
    }

    return $data;
}

function getModels($path)
{
    $out = [];
    $results = scandir($path);
    foreach ($results as $result) {
        if ($result === '.' or $result === '..') continue;
        $filename = $path . '/' . $result;
        logger($filename);
        if (is_dir($filename)) {
            $out = array_merge($out, getModels($filename));
        } else {
            $out[] = substr($filename, 0, -4);
        }
    }
    return $out;
}



function isValidReturn($value, $key = null, $ret = null)
{
    if ($key == null) {
        if (is_array($value) && !isset($value[$key]))
            return $ret;
        else if (is_array($value) && isset($value[$key]))
            return $value[$key];
        else
            return (($value === 'null' || $value === null || trim($value) == '') ? $ret : trim($value));
    }
    return ((!isset($value[$key])
        || $value[$key] === null
        || (!is_array($value[$key]) && strtolower($value[$key]) === 'null')
        || (!is_array($value[$key]) && trim($value[$key]) == ''))
        ? $ret
        : ((!is_array($value[$key]) && is_string($value[$key]))
            ? trim($value[$key])
            : $value[$key]));
}

function uploadFile($file, $destination)
{
    $filename = uniqid() . '.' . $file->getClientOriginalExtension();

    Storage::put($destination . '/' . $filename, file_get_contents($file));

    $url = Storage::url($destination . '/' . $filename);

    return ['path' => $url];
}

function callExternalAPI($method, $url, $payload)
{
    $response = Http::$method($url, $payload);

    $data = $response->json();

    if ($data['status'] == 'OK') {
        return $data;
    }

    return null;
}

function getLocationDetails($location)
{
    $result = $location['results'][0];

    $country = null;
    $state = null;
    $block = null;
    $district = null;
    $place = null;
    $pincode = null;

    foreach ($result['address_components'] as $component) {
        if (in_array('administrative_area_level_1', $component['types'])) {
            $state = $component['long_name'];
        }if (in_array('administrative_area_level_2', $component['types'])) {
            $block = $component['long_name'];
        }if (in_array('administrative_area_level_3', $component['types'])) {
            $district = $component['long_name'];
        } elseif (in_array('country', $component['types'])) {
            $country = $component['long_name'];
        } elseif (in_array('locality', $component['types'])) {
            $place = $component['long_name'];
        } elseif (in_array('postal_code', $component['types'])) {
            $pincode = $component['long_name'];
        }
    }

    $locationDetails = array(
        'country' => $country,
        'state' => $state,
        'block' => $block,
        'district' => $district,
        'place' => $place,
        'pincode' => $pincode
    );

    return $locationDetails;
}
