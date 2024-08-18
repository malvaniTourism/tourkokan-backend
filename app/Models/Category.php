<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Hashidable;
use Illuminate\Database\Eloquent\SoftDeletes;


class Category extends Model
{
    use HasFactory, Hashidable, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'code',
        'parent_id',
        'description',
        'icon',
        'status',
        'is_hot_category',
        'meta_data',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'status' => 'boolean',
        'is_hot_category' => 'boolean',
        'meta_data' => 'array'
    ];

    // /**
    //  * Get all of the sites for the Category
    //  *
    //  * @return \Illuminate\Database\Eloquent\Relations\HasMany
    //  */
    // public function sites()
    // {
    //     return $this->hasMany(Site::class, 'category_id', 'id');
    // }

    public function getNameAttribute($value)
    {
        $language = config('language');

        // return $language === 'en' ? $value :  ($this->mr_name == "" ? $value :  $this->mr_name);
        return empty($language) || $language === 'en' ? $value : ($this->mr_name == "" ? $value : $this->mr_name);
    }

    public function getMrNameAttribute($value)
    {
        return $value;
    }

    public function sites()
    {
        return $this->belongsToMany(Site::class);
    }

    /**
     * Get all of the subCategories for the Category
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subCategories()
    {
        return $this->hasMany(Category::class, 'parent_id', 'id')->where('status', true);
    }

    /**
     * Get the category that owns the Contact
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'parent_id', 'id');
    }
}
