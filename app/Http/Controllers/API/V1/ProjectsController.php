<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Projects;
use Illuminate\Http\Request;
use Validator;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ProjectsController extends BaseController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        Log::info('Showing the search results for global search: ' . $request->string);

        $string = $request->string;

        $field = getDbColumns('projects');

        $records = Projects::withCount(['products', 'photos', 'users', 'contacts', 'comments'])
            ->with(['city', 'category', 'user'])
            ->latest()
            ->Where(function ($query) use ($string, $field) {
                for ($i = 0; $i < count($field); $i++) {
                    $query->orwhere($field[$i], 'like',  '%' . $string . '%');
                }
            })
            ->paginate(10);

        Log::info("Records fetched");

        return $this->sendResponse($records, 'Records successfully Retrieved...!');

        // $projects = Projects::withCount(['products', 'photos', 'users', 'contacts'])
        //                     ->with(['city', 'category', 'user'])
        //                     // ->whereId($id)
        //                     ->latest()
        //                     ->paginate(10); //orderBy('id','desc')->paginate(10);
        // return $this->sendResponse($projects, 'Projects successfully Retrieved...!');   
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|between:2,100',
            'city_id' => 'required|numeric',
            'category_id' => 'required|numeric|exists:categories,id',
            'user_id' => 'nullable|numeric',
            'domain_name' => 'required|string',
            'logo' => 'mimes:jpeg,jpg,png|max:2048',
            'fevicon' => 'mimes:jpeg,jpg,png|max:2048',
            'description' => 'required|string',
            // 'ratings' => 'numeric',  add into users api, user will give rating
            'picture' => 'string',
            'start_price' => 'numeric',
            'speciality' => 'string',
            'link_status' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $input = $request->all();
        $date = currentDate(); //for unique naming of project folder
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('logo')) {
            Log::info("inside upload logo");

            $logo = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('logo')->store(config('constants.upload_path.project') . $request->category_id . '/' . $request->name);

            $input['logo'] = Storage::url($path);

            Log::info("FILE STORED" . $input['logo']);
        }

        //Image 2 store      
        if ($image = $request->file('fevicon')) {
            Log::info("inside upload fevicon");

            $fevicon = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('fevicon')->store(config('constants.upload_path.project') . $request->category_id . '/' . $request->name);

            $input['fevicon'] = Storage::url($path);

            Log::info("FILE STORED" . $input['fevicon']);
        }

        $projects = Projects::create($input);

        return $this->sendResponse($projects, 'Projects added successfully...!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Projects  $projects
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        $projects = Projects::withCount(['products', 'photos', 'contacts', 'comments'])
            ->withAvg("rateable", 'rate')
            ->with([
                'city' => function ($query) {
                    $query->select('id', 'name');
                },
                'city.places' => function ($query) {
                    $query->select('id', 'name', 'city_id', 'description', 'latitude', 'longitude', 'contact_details',  'image_url', 'bg_image_url')
                    ->limit(5);
                },
                'user' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'category' => function ($query) {
                    $query->select('id', 'name');
                },
                'addresses',
                'category',
                'category.allowedproductCategory' => function ($query) {
                    $query->select('id', 'category_id', 'product_category_id');
                },
                'category.allowedproductCategory.productCategory' => function ($query) {
                    $query->select('id', 'name', 'icon', 'meta_data');
                },
                'category.allowedproductCategory.productCategory.products' => function ($query) use ($id) {
                    $query->select('id', 'project_id', 'product_category_id', 'productable_type', 'productable_id')
                        ->where('project_id', '=', $id)
                        ->limit(5);
                },
                'category.allowedproductCategory.productCategory.products.productable',
                'photos' => function ($query) {
                    $query->limit(10);
                },
                'comments' => function ($query) {
                    $query->limit(10);
                },
                'comments.comments' => function ($query) {
                    $query->limit(5);
                },
                'comments.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'comments.comments.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                }
            ])
            ->latest()
            ->find($id);

        if (is_null($projects)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($projects, 'Projects successfully Retrieved...!');
    }


    // below method is currently not in use if need product page with all allowed product categories

    // /**
    //  * Display a listing of the projects.
    //  *
    //  * @return \Illuminate\Http\Response
    //  */
    // public function getAllProducts($id)
    // {
    //     $products   =   Projects::with(['category',
    //                                     'category.allowedproductCategory' => function ($query) {
    //                                         $query->select('id', 'category_id', 'product_category_id');
    //                                     },
    //                                     'category.allowedproductCategory.productCategory'=> function ($query) {
    //                                         $query->select('id', 'name', 'icon', 'meta_data');
    //                                     }, 
    //                                     'category.allowedproductCategory.productCategory.products' => function ($query) use ($id){
    //                                         $query->select('id', 'project_id', 'product_category_id', 'productable_type', 'productable_id')
    //                                             ->where('project_id', '=', $id);
    //                                     },
    //                                     'category.allowedproductCategory.productCategory.products.productable'
    //                                 ])
    //                                 ->whereId($id)
    //                                 ->latest()
    //                                 ->paginate(10);

    //     if (is_null($products)) {
    //         return $this->sendError('Empty', [], 404);
    //     }

    //     return $this->sendResponse($products, 'Products successfully Retrieved...!'); 
    // }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Projects  $projects
     * @return \Illuminate\Http\Response
     */
    public function edit(rojects $projects)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Projects  $projects
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|between:2,100',
            'category_id' => 'numeric',
            'user_id' => 'numeric',
            'domain_name' => 'string',
            'logo' => 'mimes:jpeg,jpg,png|max:2048',
            'fevicon' => 'mimes:jpeg,jpg,png|max:2048',
            'description' => 'string',
            // 'ratings' => 'numeric',  add into users api, user will give rating
            'picture' => 'string',
            'start_price' => 'numeric',
            'speciality' => 'string',
            'link_status' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $projects = Projects::find($id);

        if (is_null($projects)) {
            return $this->sendError('Empty', [], 404);
        }

        $input = $request->all();
        $date = currentDate(); //for unique naming of project folder
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('logo')) {

            if (Storage::exists($projects->logo)) {
                Storage::delete($projects->logo);
            }

            Log::info("inside upload logo");

            $logo = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('logo')->store(config('constants.upload_path.project') . $request->category_id . '/' . $request->name);

            $input['logo'] = Storage::url($path);

            Log::info("FILE STORED" . $input['logo']);
        }

        //Image 2 store      
        if ($image = $request->file('fevicon')) {

            if (Storage::exists($projects->fevicon)) {
                Storage::delete($projects->fevicon);
            }

            Log::info("inside upload fevicon");

            $fevicon = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('fevicon')->store(config('constants.upload_path.project') . $request->category_id . '/' . $request->name);

            $input['fevicon'] = Storage::url($path);

            Log::info("FILE STORED" . $input['fevicon']);
        }

        $projects->update($input);

        return $this->sendResponse($projects, 'Projects updated successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Projects  $projects
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $projects = Projects::find($id);

        if (is_null($projects)) {
            return $this->sendError('Empty', [], 404);
        }

        if (Storage::exists($projects->logo)) {
            Storage::delete($projects->logo);
        }

        if (Storage::exists($projects->fevicon)) {
            Storage::delete($projects->fevicon);
        }

        $projects->delete($request->all());

        return $this->sendResponse($projects, 'Projects deleted successfully...!');
    }
}
