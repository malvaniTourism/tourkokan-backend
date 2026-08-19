<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Hashidable;
use App\Traits\HasStorageFiles;
use Illuminate\Database\Eloquent\SoftDeletes;


class Category extends Model
{
    use HasFactory, Hashidable, SoftDeletes, HasStorageFiles;

    protected array $fileFields = ['icon'];

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        // categories.mr_name is NOT NULL with no default. Without it here, mass assignment
        // silently drops it — which only looks harmless because this MySQL runs without
        // STRICT_TRANS_TABLES; on a strict-mode server every create() would fail.
        'mr_name',
        'code',
        'parent_id',
        'description',
        'icon',
        'status',
        'is_hot_category',
        'is_business',
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
        'is_business' => 'boolean',
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
        $language = auth()->user()?->language ?? 'en';

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
     * Only sites a tourist can actually reach: approved and published.
     *
     * `sites()` counts every category_site row — including submissions still under review,
     * rejected businesses, and pivot rows left behind by a deleted site — which inflates the
     * "N places in this category" figure the app shows. Use this for any public-facing count
     * or listing. The join to `sites` also drops orphaned pivots for free, since a deleted
     * site has no row to match.
     */
    public function liveSites()
    {
        return $this->belongsToMany(Site::class)
            ->where('sites.status', true)
            ->where('sites.submission_status', 'approved');
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

    public function allSubCategories()
    {
        return $this->hasMany(Category::class, 'parent_id', 'id');
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
