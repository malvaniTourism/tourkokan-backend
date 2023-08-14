<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Comment;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class CommentController extends BaseController
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
        $comments = Comment::latest()                
                        ->paginate(10);

        return $this->sendResponse($comments, 'Comments successfully Retrieved...!');  
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
            'parent_id' => 'nullable|numeric|exists:comments,id',
            'user_id' => 'nullable|numeric|exists:users,id',
            'comment' => 'required|string',
            'commentable_type' => 'required|string',
            'commentable_id' => 'required|numeric',
        ]);            

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        // $data = json_encode(DB::table($request->commentable_type)->find($request->commentable_id));//getData($request->commentable_id, $request->commentable_type);
        $data = getData($request->commentable_id, $request->commentable_type);

        if (!$data) {
            return $this->sendError($request->commentable_type.' Not Exist..!', '', 400);       
        }

        $comment = new Comment;

        $comment->parent_id = $request->get('parent_id');
        
        $comment->user_id = $request->get('user_id');
        
        $comment->comment = $request->get('comment');

        $comment->commentable()->associate($data);

        // $comment = $comment->save();       
        $comment = Comment::create(json_decode($comment, true));       

        return $this->sendResponse($comment, 'comment created successfully...!');    
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Comment  $comment
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $comment = Comment::find($id);
        
        if (is_null($comment)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($comment, 'Comment successfully Retrieved...!');  
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Comment  $comment
     * @return \Illuminate\Http\Response
     */
    public function edit(Comment $comment)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Comment  $comment
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string',        
        ]);            

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $comment = Comment::find($id);

        if (is_null($comment)) {
            return $this->sendError('Empty', [], 404);
        }

        $comment->update($request->all());

        return $this->sendResponse($comment, 'Comment updated successfully...!');   
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Comment  $comment
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $comment = Comment::find($id);

        if (is_null($comment)) {
            return $this->sendError('Empty', [], 404);
        }

        $comment->delete($id);

        return $this->sendResponse($comment, 'Comment deleted successfully...!');   
    }
}
