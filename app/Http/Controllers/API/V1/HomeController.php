<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\BaseController as BaseController;

class HomeController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
    */
    public function search(Request $request)
    {        
        Log::info('Showing the search results for global search: '.$request->string);

        $validator = Validator::make($request->all(), [
            'string' => 'nullable|string',
            'start_price' => 'nullable|numeric|required_with:priceFilter',
            'priceFilter' => 'nullable|in:equalTo,lessThan,greaterThan|required_with:start_price',
            'ratings' => 'nullable|numeric|required_with:ratingFilter',
            'ratingFilter' => 'nullable|in:equalTo,lessThan,greaterThan|required_with:ratings',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $string = $request->string;
        $start_price = $request->start_price;
        $priceFilter = $request->priceFilter;
        $ratings = $request->ratings;
        $ratingFilter = $request->ratingFilter;

        $field = DB::getSchemaBuilder()->getColumnListing($request->table_name);
        
        $records = DB::table($request->table_name)->Where(function ($query) use($string, $field, $priceFilter, $start_price, $ratingFilter, $ratings) {                       
            for ($i = 0; $i < count($field); $i++){ 
                if ($field[$i] == "start_price") {
                    if ($start_price != '' && $priceFilter != '') {
                        if ($priceFilter == "equalTo") {                            
                            $query->where('start_price', '=', $start_price);
                        }
                        if ($priceFilter == "greaterThan") {
                            $query->where('start_price', '>', $start_price);
                        }
                        if ($priceFilter == "lessThan") {
                            $query->where('start_price', '<', $start_price);
                        }
                    }
                    continue;
                }
                
                if ($field[$i] == "ratings") {
                    if ($ratings != '' && $ratingFilter != '') {
                        if ($ratingFilter == "equalTo") {
                            $query->where('ratings', '=', $ratings);
                        }
                        if ($ratingFilter == "greaterThan") {
                            $query->where('ratings', '>', $ratings);
                        }
                        if ($ratingFilter == "lessThan") {
                            $query->where('ratings', '<', $ratings);
                        }   
                    }
                    continue;
                }

                if ($string != '') {
                    $query->orwhere($field[$i], 'like',  '%' . $string .'%');
                }
            }      

            logger($query->toSql());   
        })
        ->orderBy('id', 'desc')
        ->latest()
        ->paginate(10); //response with pagination

        Log::info("Records fetched");

        return $this->sendResponse($records, 'Records successfully Retrieved...!');
    }
}
    