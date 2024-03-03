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
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Spatie\Geocoder\Geocoder;
use Illuminate\Support\Facades\Mail;

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

        case 'Site':
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

function getLocationDetails($latitude, $longitude)
{
    try {

        $country = null;
        $state = null;
        $block = null;
        $district = null;
        $city = null;
        $place = null;
        $pincode = null;

        $client = new \GuzzleHttp\Client();

        $geocoder = new Geocoder($client);

        $geocoder->setLanguage('en');

        $geocoder->setApiKey(config('geocoder.key'));

        $location =  $geocoder->getAddressForCoordinates($latitude, $longitude);

        if ($location) {
            $place = $location['formatted_address'];

            foreach ($location['address_components'] as $component) {
                $types = $component->types;
                if (in_array('country', $types) && in_array('political', $types)) {
                    $country = $component->long_name;
                } elseif (in_array('administrative_area_level_1', $types) && in_array('political', $types)) {
                    $state = $component->long_name;
                } elseif (in_array('administrative_area_level_2', $types) && in_array('political', $types)) {
                    $block = $component->long_name;
                } elseif (in_array('administrative_area_level_3', $types) && in_array('political', $types)) {
                    $district = $component->long_name;
                } elseif (in_array('locality', $types) && in_array('political', $types)) {
                    $city = $component->long_name;
                } elseif (in_array('postal_code', $types)) {
                    $pincode = $component->long_name;
                }
            }

            $site = Site::select('id', 'name', 'parent_id')->where('name', $city)->first();

            $address = array(
                'country' => $country,
                'state' => $state,
                'block' => $block,
                'district' => $district,
                'place' => $place,
                'site_id' => isValidReturn($site, 'id'),
                'pincode' => $pincode,
                'latitude' => $latitude,
                'longitude' => $longitude
            );

            return $address;
        }
    } catch (ClientException $e) {
        $response = $e->getResponse();
        $statusCode = $response->getStatusCode();
        // $reasonPhrase = $response->getReasonPhrase();
        return  $statusCode;
    } catch (\Exception $e) {
        return  $e->getMessage();
    }
}


function sendOTP($destination)
{
    $user = User::where($destination)->first();

    if ($user) {
        $otp =  random_int(100000, 999999);

        User::where($destination)->update(array('otp' => $otp));

        $data = [
            'subject' => 'Tourkokan OTP',
            'content' => '
                <html>
                <head>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            line-height: 1.6;
                            margin: 0;
                            padding: 0;
                        }
                        .container {
                            max-width: 600px;
                            margin: auto;
                            padding: 20px;
                            border: 1px solid #ccc;
                            border-radius: 5px;
                        }
                        h2 {
                            color: #333;
                        }
                        p {
                            margin-bottom: 15px;
                        }
                        .otp {
                            font-size: 24px;
                            font-weight: bold;
                            color: #007bff;
                        }
                        .signature {
                            margin-top: 20px;
                            color: #666;
                        }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <h2> Tourkokan </h2>
                        <p>Hello,</p>
                        <p>Your One-Time Password (OTP) for accessing Tourkokan is: <span class="otp">' . $otp . '</span></p>
                        <p>Please use this OTP to complete your login process. If you did not request this OTP, please ignore this message.</p>
                        <p>Thank you,</p>
                        <p class="signature">Team Tourkokan</p>
                    </div>
                </body>
                </html>
            ',
        ];


        if (array_key_first($destination) == 'email') {
            $data['email'] = $destination['email'];

            Mail::send('email-template', $data, function ($message) use ($data, $destination) {
                $message->to($destination['email'])
                    ->subject($data['subject'])
                    ->from('no-reply@tourkokan.com', 'Tourkokan');
            });
        }

        if (array_key_first($destination) == 'mobile') {
            #send otp using sms gateway
        }

        return 1;
    }
}
