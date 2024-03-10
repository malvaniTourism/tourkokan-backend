<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Comment;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CommentController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'commentable_type' => 'required|string',
            'commentable_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $data = getData($request->commentable_id, $request->commentable_type);

        if (!$data) {
            return $this->sendError($request->commentable_type . ' Not Exist..!', '', 400);
        }

        $comments = $data->comment()
            ->with(['users:id,name,email,profile_picture'])
            ->latest()
            ->paginate($request->per_page);


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
            'comment' => 'required|string',
            'commentable_type' => 'required|string',
            'commentable_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $data = getData($request->commentable_id, $request->commentable_type);

        if (!$data) {
            return $this->sendError($request->commentable_type . ' Not Exist..!', '', 400);
        }

        $commentableType = "App\\Models\\" . $request->commentable_type;

        $existingComment = $data->comment()
            ->where([
                'user_id' => config('user_id'),
                'comment' => $request->comment
            ])
            ->whereHasMorph('commentable', $commentableType, function ($subquery) use ($request) {
                $subquery->where('id', $request->commentable_id);
            })->first();

        if ($existingComment) {
            return $this->sendResponse(null, 'Same comment is not allowed');
        } else {
            $comment = [
                'user_id' => config('user_id'),
                'comment' => $request->comment,
                'parent_id' => $request->parent_id
            ];

            $data->comment()->create(array_filter($comment));
        }

        return $this->sendResponse($comment, 'comment created successfully...!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function getComment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:comments,id',
        ], [
            'id.exists' => 'Invalid record'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $comment = Comment::where('user_id', config('user_id'))
            ->find($request->id);

        return $this->sendResponse($comment, 'Comment successfully Retrieved...!');
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateComment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:comments,id',
            'comment' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $comment = Comment::where([
            'id' => $request->id, 'user_id' => config('user_id')
        ])->update($request->all());

        return $this->sendResponse($comment, 'Comment updated successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function deleteComment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:comments,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $comment = Comment::where([
            'id' => $request->id,
            'user_id' => config('user_id')
        ])->delete();

        return $this->sendResponse($comment, 'Comment deleted successfully...!');
    }
}
