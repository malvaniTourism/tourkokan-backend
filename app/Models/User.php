<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Hashidable;
use App\Traits\HasStorageFiles;
use App\Traits\EncryptsPersonalData;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, Hashidable, HasFactory, Notifiable, SoftDeletes, HasStorageFiles, EncryptsPersonalData;

    protected array $fileFields = ['profile_picture'];

    /** Fields that get a blind-index hash column auto-populated on save */
    protected array $blindIndexFields = ['name', 'email', 'mobile'];

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'language',
        'project_id',
        'name',
        'name_hash',
        'email',
        'email_hash',
        'password',
        'mobile',
        'mobile_hash',
        'code',
        'gender',
        'dob',
        'privilage',
        'profile_picture',
        'isVerified',
        'uid',
        'otp',
        'otp_created_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'otp',
        'name_hash',
        'email_hash',
        'mobile_hash',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'otp_created_at'    => 'datetime',
        // PII — encrypted at rest using APP_KEY (AES-256-CBC)
        'name'              => 'encrypted',
        'email'             => 'encrypted',
        'mobile'            => 'encrypted',
        'dob'               => 'date:Y-m-d',
        'gender'            => 'encrypted',
    ];

    /**
     * Override castAttribute to gracefully handle records encrypted with a
     * different APP_KEY (e.g. data migrated from another environment).
     * Returns null instead of throwing DecryptException so the rest of the
     * response is still usable.
     */
    protected function castAttribute($key, $value)
    {
        try {
            return parent::castAttribute($key, $value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Get the roles that owns the User
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function roles()
    {
        return $this->belongsToMany(Roles::class, 'user_roles', 'user_id', 'role_id');
    }

    public function hasRole(string $code): bool
    {
        return $this->roles()->where('code', $code)->exists();
    }

    /**
     * Get all of the comments for the User
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function commentsOfUser()
    {
        return $this->hasMany(Comment::class, 'user_id');
    }

    /**
     * Get all of the product's comments.
     */
    public function commentsOnUser()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }


    /**
     * Get the project that owns the User
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Projects::class);
    }

    /**
     * Get all of the projects for the User
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function projects()
    {
        return $this->hasMany(Projects::class, 'user_id');
    }


    /**
     * Get all of the project's comments.
     */
    public function favourites()
    {
        return $this->hasMany(Favourite::class, 'user_id');
    }

    /**
     * Get all of the contact's comments.
     */
    public function contacts()
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    /**
     * Get all of the address's projects.
     */
    public function addresses()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * Get all of the rating for the User
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function rating()
    {
        return $this->hasMany(Rating::class, 'user_id');
    }

    public function address()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

     /**
     * Get all of the project's comments.
     */
    public function wallets()
    {
        return $this->hasMany(Wallet::class, 'user_id');
    }
}
