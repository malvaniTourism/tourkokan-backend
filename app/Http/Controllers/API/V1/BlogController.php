<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Blog;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class BlogController extends BaseController
{
     /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct() {
        $this->middleware('auth:api');
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $blogs = Blog::withCount(['category', 'photos', 'comments'])
                    ->orderBy('id', 'desc')
                    ->latest()                
                    ->paginate(10);

        return $this->sendResponse($blogs, 'Blogs successfully Retrieved...!');
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
            'category_id' => 'nullable|numeric|exists:categories,id',
            'name' => 'required|string|between:2,100',
            'title' => 'required|string|between:2,200',
            'description' => 'required|string', 
            'image' => 'nullable|mimes:jpeg,jpg,png,webp|max:2048',
            'ratings' => 'nullable|numeric',
            'count' => 'nullable|numeric',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $input = $request->all();
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('image')) {
            Log::info("inside upload image");
            
            $url = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('image')->store(config('constants.upload_path.blog').$request->title);

            $input['image'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image']);
        }

        $blog = Blog::create($input);

        return $this->sendResponse($blog, 'Blog added successfully...!');        
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Blog  $blog
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $blog = Blog::withCount(['photos', 'comments'])
                      ->with(['category', 'photos', 'comments', 'comments.comments', 'comments.users', 'comments.comments.users'])
                      ->latest()
                      ->find($id);
        
        if (is_null($blog)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($blog, 'Blog successfully Retrieved...!');  
    }

     /**
     * Display the specified resource.
     *
     * @param  \App\Models\Blog  $blog
     * @return \Illuminate\Http\Response
     */
    public function blogByCategory($id)
    {
        $blog = Blog::withCount(['photos', 'comments'])
                      ->with(['category', 'photos', 'comments', 'comments.comments', 'comments.users', 'comments.comments.users'])
                      ->latest()
                      ->where('category_id', $id)
                      ->get();
        
        if (is_null($blog)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($blog, 'Blog successfully Retrieved...!');  
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Blog  $blog
     * @return \Illuminate\Http\Response
     */
    public function edit(Blog $blog)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Blog  $blog
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $blog = Blog::find($id);

        if (is_null($blog)) {
            return $this->sendError('Empty', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|numeric',
            'name' => 'nullable|string|between:2,100',
            'title' => 'nullable|string|between:2,200',
            'description' => 'nullable|string',   
            'image' => 'nullable|mimes:jpeg,jpg,png,webp|max:2048',        
            'ratings' => 'nullable|numeric',
            'count' => 'nullable|numeric',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $input = $request->all();
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('image')) {
            Log::info("inside upload image");
            
            if(Storage::exists($photos->image)){
                Log::info("file exist");
                Storage::delete($photos->image);
                Log::info("file deleted");
            }

            $url = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('image')->store(config('constants.upload_path.blog').$request->title);

            $input['image'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image']);
        }

        $blog->update($input);

        return $this->sendResponse($blog, 'Blogs updated successfully...!');   
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Blog  $blog
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $blog = Blog::find($id);

        if (is_null($blog)) {
            return $this->sendError('Empty', [], 404);
        }

        $blog->delete($request->all());

        return $this->sendResponse($blog, 'Blog deleted successfully...!');   
    }
}
