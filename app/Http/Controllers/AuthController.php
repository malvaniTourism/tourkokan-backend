<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BaseController as BaseController;
use App\Mail\WelcomeEmail;
use App\Models\Roles;
use App\Models\Site;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use DB;
use Carbon\Carbon;
use Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\AppVersion;
use App\Models\BonusTypes;
use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Http;

class AuthController extends BaseController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api', 
        [
            'except' => 
            [
                'login', 'register', 'sendOtp', 'verifyOtp', 'updateEmail', 'isVerifiedEmail', 'deleteMyAccount', 'googleAuth', 'guest-login'
            ]
        ]);
    }

    public function allUsers()
    {
        if (in_array(auth()->user()->roles->code, ['superadmin', 'admin'])) {
            $user = User::with('roles')
                ->paginate(request()->per_page);
            return $this->sendResponse($user, 'User successfully registered');
        } else {
            return $this->sendError('Unauthorized', '', 401);
        }
    }

    public function listUsers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'apitype' => 'required|string|in:list,dropdown',
            'search'  => 'nullable|string|max:100',
            'per_page'=> 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $columns = $request->apitype === 'dropdown'
            ? ['id', 'name', 'mobile', 'email', 'profile_picture']
            : ['id', 'name', 'mobile', 'email', 'gender', 'dob', 'profile_picture', 'isVerified', 'created_at'];

        $query = User::select($columns);

        if ($request->apitype === 'list') {
            $query->with('roles:id,name,code');
        }

        if ($request->filled('search')) {
            $hash = User::makeBlindIndex($request->search);
            $query->where(function ($q) use ($hash) {
                $q->where('name_hash', $hash)
                  ->orWhere('email_hash', $hash)
                  ->orWhere('mobile_hash', $hash);
            });
        }

        $users = $query->latest()->paginate($request->input('per_page', 20));

        return $this->sendResponse($users, 'Users retrieved successfully.');
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $request->attributes->set('app_version', Cache::has('app_version') ? Cache::get('app_version')->version_number : optional(AppVersion::latest()->first())->version_number);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        if (!$token = auth()->attempt($validator->validated(), ['exp' => null])) {
            return $this->sendError('invalid credentials', '', 200);
        }

        $user = Auth::user();

        $roles = Roles::whereIn('name', ['superadmin', 'admin'])->get();
        if (
            $user &&
            Str::startsWith($request->route()->getPrefix(), 'admin') &&
            !in_array($user->roles->id, array_column($roles->toArray(), 'id'))
        ) {
            return $this->sendError('Unauthorized', '', 401);
        }

        if ($user && !$user->isVerified && !in_array($user->roles->id, array_column($roles->toArray(), 'id'))) {
            return $this->sendError('Please verify your email for login', '', 200);
        }

        return $this->createNewToken($token, 'Logged In...!');
    }

    /**
     * Register a User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        try {
            // $prefix = $request->route()->getPrefix();
            // $prefixParts = explode('/', $request->route()->getPrefix());
            // $prefix = $prefixParts[0];

            // there is error in validation for superadmin role return error
            $isGuest = $request->input('is_guest', false);

            $rules = [
                'role_id' => 'sometimes|exists:roles,id',
                'language' => 'sometimes|required|in:mr,en',
                'name' => 'required|string|between:2,60',
                'email' => 'required_if:mobile,null|nullable|string|email|max:100',
                'mobile' => 'required_if:email,null|nullable|digits:10',
                'password' => 'sometimes|string|required_with:email|confirmed|min:6',
                'profile_picture' => 'nullable|string',
                'latitude' => 'sometimes|required_with:longitude',
                'longitude' => 'sometimes|required_with:latitude',
                'referral_code' => 'sometimes|nullable|exists:users,uid',
            ];

            // Modify rules if it's a guest registration
            if ($isGuest) {
                // Guest only needs a name (everything else optional)
                $rules = [
                    'name' => 'required|string|between:2,60',
                    'email' => 'nullable|string|email|max:100',
                    'mobile' => 'nullable|digits:10',
                    'password' => 'sometimes|nullable|string|min:6',
                    'latitude' => 'sometimes|required_with:longitude',
                    'longitude' => 'sometimes|required_with:latitude',
                ];
            }

            $validator = Validator::make($request->all(), $rules, [
                'referral_code.exists' => 'Invalid Referral Code...!',
            ]);


            if ($validator->fails()) {
                return $this->sendError($validator->errors(), [], 200);
            }

            // Uniqueness check via blind index (email/mobile are encrypted in DB)
            if ($request->filled('email')) {
                $existing = User::findByEmail($request->email);
                if ($existing) {
                    return $this->sendError(
                        ['email' => ['The email has already been taken.']],
                        ['isVerified' => $existing->isVerified],
                        200
                    );
                }
            }
            if ($request->filled('mobile')) {
                $existing = User::findByMobile($request->mobile);
                if ($existing) {
                    return $this->sendError(['mobile' => ['The mobile has already been taken.']], [], 200);
                }
            }

            if ($request->password == "") {
                $password = Str::random(10);
            } else {
                $password = $request->password;
            }

            $input = $validator->validated();
            $date = currentDate();
            Log::info("upload file starting");

            //upload profile Image      
            if (isValidReturn($input, 'profile_picture')) {

                $directory = config('constants.upload_path.profile_picture') . $request->name;

                $image_64 = $request->input('profile_picture');

                $extension = explode('/', explode(':', substr($image_64, 0, strpos($image_64, ';')))[1])[1];

                $replace = substr($image_64, 0, strpos($image_64, ',') + 1);

                $image = str_replace($replace, '', $image_64);

                $image = str_replace(' ', '+', $image);

                $imageName = Str::random(10) . '.' . $extension;

                Storage::put($directory . '/' . $imageName, base64_decode($image));

                $input['profile_picture'] = $directory . '/' . $imageName;

                Log::info("FILE STORED" . $input['profile_picture']);
            }

            $input['password'] = bcrypt($password);

            if (
                Str::startsWith($request->route()->getPrefix(), 'admin') &&
                $request->has('role_id')
            ) {
                $input['role_id'] = $request->role_id;
            } else {
                $roles = Roles::whereIn('code', ['tourist'])->first();

                $input['role_id'] = $roles->id;
            }

            #considering uid as coupon code
            $input['uid'] = Str::random(10);

            $joiningBonus = BonusTypes::where(['code' => 'joining_bonus_coins'])->first();

            if (!$joiningBonus) {
                return $this->sendError('Something went wrong', '', 200);
            }

            $user = User::create($input);

            $referrer = [];

            if (isValidReturn($input, 'referral_code')) {
                $referrer = User::where('uid', $input['referral_code'])->first();

                if (!$referrer) {
                    return $this->sendError('Invalid Referral Code...!', '', 200);
                }

                $referralBonus = BonusTypes::where(['code' => 'referral_bonus_coins'])->first();

                if (!$referralBonus) {
                    return $this->sendError('Something went wrong', '', 200);
                }

                $referrerWallet = new Wallet([
                    'user_id' => $referrer->id,
                    'bonus_id' => $referralBonus->id,
                    'amount' => $referralBonus->amount,
                    'description' => "Referral bonus on successful registration of a new user",
                    'referee_id' => $user->id
                ]);

                $referrer->wallets()->save($referrerWallet);
            }

            $newUserWallet = new Wallet([
                'user_id' => $user->id,
                'bonus_id' => $joiningBonus->id,
                'amount' => $joiningBonus->amount,
                'description' => "Joining bonus on successfull registration",
                'referrer_id' => isValidReturn($referrer, 'id')
            ]);

            $user->wallets()->save($newUserWallet);

            $user = User::select('id', 'role_id', 'name', 'email', 'isVerified', 'profile_picture', 'gender', 'uid')->find($user->id);

            if ($request->has(['latitude', 'longitude'])) {
                $locationDetails = getLocationDetails($request->latitude, $request->longitude);

                if ($locationDetails && $locationDetails != 400) {
                    $user->address()->create($locationDetails);
                }
            }

            $roles = Roles::whereIn('code', ['superadmin', 'admin'])->get();

            if (
                $user &&
                !empty($user->email) &&
                !Str::startsWith($request->route()->getPrefix(), 'admin') &&
                !in_array($user->roles->id, array_column($roles->toArray(), 'id'))
            ) {
                Mail::to($user->email)->send(new WelcomeEmail($user, $password));
            }

            if ($isGuest) {
                $user = User::find($user->id);

                $token = JWTAuth::fromUser($user);
                return $this->createNewToken($token, 'Logged In Successfully!', $isGuest);
            }

            return $this->sendResponse($user, 'User successfully registered');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = auth()->user();

            // Validate the incoming request data
            $validator = Validator::make($request->all(), [
                'email' => 'sometimes|nullable|email',
                'password' => 'sometimes|nullable|string|confirmed|min:6',
                'profile_picture' => 'sometimes|nullable|string',
                'language' => 'sometimes|required|in:mr,en',
                'mobile' => 'sometimes|required|digits:10',
                'dob' => 'sometimes|nullable|date_format:Y-m-d',
                'gender' => 'sometimes|nullable|string',
                'latitude' => 'sometimes|nullable|numeric|required_with:longitude',
                'longitude' => 'sometimes|nullable|numeric|required_with:latitude',
            ]);

            if ($validator->fails()) {
                return $this->sendError($validator->errors(), '', 200);
            }

            // Uniqueness check via blind index (email/mobile are encrypted in DB)
            if ($request->filled('email')) {
                $existing = User::findByEmail($request->email);
                if ($existing && $existing->id !== $user->id) {
                    return $this->sendError(['email' => ['The email has already been taken.']], '', 200);
                }
            }
            if ($request->filled('mobile')) {
                $existing = User::findByMobile($request->mobile);
                if ($existing && $existing->id !== $user->id) {
                    return $this->sendError(['mobile' => ['The mobile has already been taken.']], '', 200);
                }
            }

            $input = $validator->validated();

            if ($request->has('password') && $request->password != "") {
                $input['password'] = bcrypt($request->password);
            }

            if (isset($input['profile_picture']) && $input['profile_picture']) {
                $rawOld = $user->getRawOriginal('profile_picture');
                if ($rawOld && !str_starts_with($rawOld, 'http') && Storage::exists($rawOld)) {
                    Storage::delete($rawOld);
                }
            }

            $updateData = array_filter($input, fn($v) => $v !== null && $v !== '');
            if ($request->has('dob')) $updateData['dob'] = $input['dob'];
            if ($request->has('gender')) $updateData['gender'] = $input['gender'];

            unset($updateData['latitude'], $updateData['longitude']);
            $user->update($updateData);

            if ($request->has('latitude') && $request->has('longitude')) {
                $locationDetails = getLocationDetails($request->latitude, $request->longitude);

                if ($locationDetails && $locationDetails != 400) {
                    $user->address()->updateOrCreate(
                        ['addressable_id' => $user->id, 'addressable_type' => get_class($user)],
                        $locationDetails
                    );
                }
            }

            return $this->sendResponse($user, 'User successfully updated');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function isVerifiedEmail(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|unique:users,email',
            ]);

            if ($validator->fails()) {
                $errors = $validator->errors();
                $data = [];
                if ($errors->has('email') && $errors->get('email')[0] === 'The email has already been taken.')
                    $data = ['isVerified' => User::findByEmail($request->email)?->isVerified];

                return $this->sendResponse($data, $validator->errors());
            }

            return $this->sendResponse(true, 'Please register for login', false);
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateEmail(Request $request)
    {
        try {
            $validator = Validator::make(
                $request->all(),
                [
                    'id' => 'required|exists:users,id,email,' . $request->email,
                    'email' => 'required|email',
                    'new_email' => 'required|email|different:email',
                    'uid' => 'required|string|exists:users,uid',
                ],
                [
                    'id.exists' => 'Unauthorized action.',
                    'uid.required' => 'The user id field is required.',
                    'uid.exists' => 'Invalid User Id...! Please check your email for user id..!'
                ]
            );

            if ($validator->fails()) {
                return $this->sendError($validator->errors(), '', 200);
            }

            $user = User::where('id', $request->id)->first();

            // Verify current email matches using blind index
            if (!$user || $user->email_hash !== User::makeBlindIndex($request->email)) {
                return $this->sendError('Unable to change email', '', 200);
            }

            $updated = $user->update([
                'email'      => $request->new_email,
                'email_hash' => User::makeBlindIndex($request->new_email),
            ]);

            if (!$updated) {
                return $this->sendError('Unable to change email', '', 200);
            }

            $user = User::where('id', $request->id)->first();

            $otp         = random_int(100000, 999999);
            $otpCreatedAt = Carbon::parse($user->otp_created_at);
            $now          = Carbon::now();

            if ($user->otp_created_at != null && $now->diffInMinutes($otpCreatedAt) < 5) {
                return $this->sendError('OTP has already been sent. Please wait 5 minutes before requesting a new one.', '', 200);
            }

            $user->update([
                'otp'            => $otp,
                'otp_created_at' => Carbon::now(),
            ]);

            $destination = array_key_first($request->all()) == 'email' ? 'email' : 'mobile';

            $otpSent = sendOTP($otp, $destination, $user);

            return $this->sendResponse($otpSent, 'Email successfully changed & OTP has been sent to your new email ..!');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }
    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth()->logout();

        return $this->sendResponse(null, 'User successfully signed out');

        // return response()->json(['message' => 'User successfully signed out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return $this->createNewToken(auth()->refresh(), 'Refreshed token...!');
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function userProfile()
    {
        $user = auth()->user();

        $user->load(['favourites.favouritable', 'rating', 'commentsOfUser', 'commentsOnUser', 'contacts', 'addresses']);

        // Calculate the sum of wallet amounts
        $walletsSum = $user->wallets()->sum('amount');

        // Attach the sum to the user model
        $user->wallets_sum_amount = $walletsSum;

        return $this->sendResponse($user, 'User Fetched..!');
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function createNewToken($token, $message, $isGuest = false)
    {
        $response = [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => null,
            'isGuest' => $isGuest,
            'user' => JWTAuth::setToken($token)->authenticate()
        ];

        return $this->sendResponse($response, $message);
    }

    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'  => 'sometimes|nullable|required_without:mobile|email|exists:users,email_hash,' . User::makeBlindIndex($request->email ?? ''),
            'mobile' => 'sometimes|nullable|required_without:email',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $otp = random_int(100000, 999999);

        $user = $request->filled('email')
            ? User::findByEmail($request->email)
            : User::findByMobile($request->mobile);

        if (!$user) {
            return $this->sendError('User not found', '', 200);
        }

        $otpCreatedAt = Carbon::parse($user->otp_created_at);
        $now = Carbon::now();

        if ($user->otp_created_at != null && $now->diffInMinutes($otpCreatedAt) < 5) {
            return $this->sendError('OTP has already been sent. Please wait 5 minutes before requesting a new one.', '', 200);
        }

        $user->update([
            'otp'            => User::hashOtp((string) $otp), // store hashed OTP
            'otp_created_at' => Carbon::now(),
        ]);

        $destination = array_key_first($request->all()) == 'email' ? 'email' : 'mobile';

        $data = sendOTP($otp, $destination, $user);

        return $this->sendResponse($data, 'OTP successfully sent!');
    }

    public function verifyOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email'  => 'sometimes|nullable|required_without:mobile|email',
                'mobile' => 'sometimes|nullable|required_without:email',
                'otp'    => 'required',
                'delete' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->sendError($validator->errors(), '', 200);
            }

            $delete = isValidReturn($request->all(), 'delete');

            // Find user by blind index
            $user = $request->filled('email')
                ? User::findByEmail($request->email)
                : User::findByMobile($request->mobile);

            if (!$user) {
                return $this->sendError('Invalid OTP', [], 200);
            }

            // Verify hashed OTP
            if (!User::verifyOtp((string) $request->otp, (string) $user->getRawOriginal('otp'))) {
                return $this->sendError('Invalid OTP', [], 200);
            }

            if ($delete) {
                Wallet::where('user_id', $user->id)->delete();
                $user->delete();
                return $this->sendResponse(null, 'Your account has been removed from our database.');
            }

            $user->update([
                'otp'               => null,
                'email_verified_at' => Carbon::now(),
                'isVerified'        => true,
            ]);

            $token = JWTAuth::fromUser($user);
            return $this->createNewToken($token, 'Logged In Successfully!');

        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return $this->sendError('An error occurred.', [], 500);
        }
    }


    public function getAllFavourites($id)
    {
        $user = User::with('favourites')->find($id);

        if (is_null($user)) {
            return $this->sendError('User not found', [], 404);
        }

        $favourites = $user->favourites;

        if ($favourites->isEmpty()) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($favourites, 'Favourites successfully Retrieved...!');
    }

    public function deleteMyAccount(Request $request)
    {
        try {
            $req = $request->only('email');

            $validator = Validator::make($req, [
                'email' => [
                    'sometimes',
                    'required',
                    'email',
                    Rule::exists('users', 'email')->where(function ($query) {
                        $query->whereNull('deleted_at');
                    }),
                ],
            ]);

            if ($validator->fails()) {
                return $this->sendError($validator->errors(), '', 200);
            }

            $filter = [];

            if (isValidReturn($req, 'email')) {
                $filter = ['email' => $request->email];
            } else {
                logger("not yet develpped");
                $filter = ['email' => auth()->user()->email];
            }

            $otp =  random_int(100000, 999999);

            $user = User::where($filter)->first();

            $otpCreatedAt = Carbon::parse($user->otp_created_at);
            $now = Carbon::now();

            if ($user->otp_created_at != null && $now->diffInMinutes($otpCreatedAt) < 5) {
                return $this->sendError('OTP has already been sent. Please wait 5 minutes before requesting a new one.', '', 200);
            }

            $updateStatus =  array(
                'otp' => $otp,
                'otp_created_at' =>  Carbon::now(),
            );

            $user->update($updateStatus);

            $destination = array_key_first($filter) == 'email' ? 'email' : 'mobile';

            $data = sendOTP($otp, $destination, $user, 'account_delete');

            return $this->sendResponse($data, 'OTP successfully sent!');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return $this->sendError('Something went wrong', [], 500);
        }
    }

    public function googleAuth(Request $request)
    {
        $token = $request->input('token');
    
        $validator = Validator::make(
            $request->all(),
            [
                'language' => 'sometimes|required|in:mr,en',
                'latitude' => 'sometimes|required_with:longitude',
                'longitude' => 'sometimes|required_with:latitude',
                'referral_code' => 'sometimes|nullable|exists:users,uid',
            ],
            [
                'referral_code.exists' => 'Invalid Referral Code...!'
            ]
        );

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), [], 200);
        }

        $googleUser = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $token,
        ])->json();
    
        if (isset($googleUser['sub'])) {

            try {
                $user = User::findByEmail($googleUser['email']);

                if ($user) {
                    $user->update([
                        'email_verified_at' => Carbon::now(),
                        'isVerified'        => true,
                    ]);

                    $token = JWTAuth::fromUser($user);
                    return $this->createNewToken($token, 'Logged In Successfully!');
                } else {
                    $validator = Validator::make(
                        $request->all(),
                        [
                            'latitude' => 'required_with:longitude',
                            'longitude' => 'required_with:latitude',
                        ]
                    );
            
                    if ($validator->fails()) {
                        return $this->sendError($validator->errors(), [], 200);
                    }
                    
                    // Create a new user if not found
                    $password = Str::random(10);
                    $roles = Roles::where('code', 'tourist')->first();
    
                    if (!$roles) {
                        return $this->sendError('Role not found', '', 200);
                    }
    
                    $input = [
                        'name' => $googleUser['name'],
                        'email' => $googleUser['email'],
                        'password' => bcrypt($password), // Store hashed password
                        'role_id' => $roles->id,
                        'email_verified_at' => Carbon::now(),
                        'isVerified' => true,
                        'profile_picture' => preg_replace('/=s\d+-c$/', '=s400-c', $googleUser['picture'] ?? ''),
                        'uid' => Str::random(10), // Assuming uid as coupon code
                    ];
                    
                    $joiningBonus = BonusTypes::where(['code' => 'joining_bonus_coins'])->first();

                    if (!$joiningBonus) {
                        return $this->sendError('Something went wrong', '', 200);
                    }
    
                    $user = User::create($input);
    
                    $referrer = [];

                    if (isValidReturn($request, 'referral_code')) {
                        $referrer = User::where('uid', $request['referral_code'])->first();
        
                        if (!$referrer) {
                            return $this->sendError('Invalid Referral Code...!', '', 200);
                        }
        
                        $referralBonus = BonusTypes::where(['code' => 'referral_bonus_coins'])->first();
        
                        if (!$referralBonus) {
                            return $this->sendError('Something went wrong', '', 200);
                        }
        
                        $referrerWallet = new Wallet([
                            'user_id' => $referrer->id,
                            'bonus_id' => $referralBonus->id,
                            'amount' => $referralBonus->amount,
                            'description' => "Referral bonus on successful registration of a new user",
                            'referee_id' => $user->id
                        ]);
        
                        $referrer->wallets()->save($referrerWallet);
                    }
        
                    $newUserWallet = new Wallet([
                        'user_id' => $user->id,
                        'bonus_id' => $joiningBonus->id,
                        'amount' => $joiningBonus->amount,
                        'description' => "Joining bonus on successfull registration",
                        'referrer_id' => isValidReturn($referrer, 'id')
                    ]);
        
                    $user->wallets()->save($newUserWallet);
        
                    $user = User::select('id', 'role_id', 'name', 'email', 'isVerified', 'profile_picture', 'gender', 'uid')->find($user->id);
        
                    if ($request->has(['latitude', 'longitude'])) {
                        $locationDetails = getLocationDetails($request->latitude, $request->longitude);
        
                        if ($locationDetails && $locationDetails != 400) {
                            $user->address()->create($locationDetails);
                        }
                    }
    
                    $token = JWTAuth::fromUser($user);
                    return $this->createNewToken($token, 'Account Created Successfully!');
                }
            } catch (\Throwable $th) {
                Log::error($th->getMessage());
                return $this->sendError('An error occurred.', [], 500);
            }
        }
        return $this->sendError('Unauthorized', '', 401);
    }
}
