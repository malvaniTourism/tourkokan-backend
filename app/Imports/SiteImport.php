<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Site;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class SiteImport implements ToCollection, WithHeadingRow
{
    /**
     * @param Collection $collection
     */
    public function collection(Collection $data)
    {
        try {
            foreach ($data as $key => $value) {
                $siteRecord = array();
                $siteRecord['name'] = $value['name'];
                $siteRecord['user_id'] = isValidReturn($value, 'user_id');

                $parent = [];
                if ($value['parent_code'] != NULL) {
                    $where_parent = array(
                        'name' => $value['parent_code']
                    );

                    $parent = Site::where($where_parent)->first();
                    if (!$parent) {
                        logger("blank parent");
                    }
                }

                $siteRecord['parent_id'] = isValidReturn($parent, 'id');

                $category = [];
                if ($value['category_code'] != NULL) {
                    $where_category = array(
                        'code' => $value['category_code']
                    );

                    $category = Category::where($where_category)->first();
                    if (!$category) {
                        logger("blank category");
                    }
                }

                $siteRecord['category_id'] = isValidReturn($category, 'id');
                $siteRecord['bus_stop_type'] = $value['bus_stop_type'];
                $siteRecord['tag_line'] = $value['tag_line'];
                $siteRecord['description'] = isValidReturn($value, 'description', "raw");
                $siteRecord['domain_name'] = $value['domain_name'];
                $siteRecord['logo'] = isValidReturn($value, 'logo');
                $siteRecord['icon'] = isValidReturn($value, 'icon');
                $siteRecord['image'] = isValidReturn($value, 'image');
                $siteRecord['status'] = $value['status'];
                $siteRecord['is_hot_place'] = false;
                $siteRecord['latitude'] = $value['latitude'];
                $siteRecord['longitude'] = $value['longitude'];
                $siteRecord['pin_code'] = "" . $value['pin_code'] . "";
                $siteRecord['speciality'] = $value['speciality'];
                $siteRecord['rules'] = $value['rules'];
                $siteRecord['social_media'] = $value['social_media'];
                $siteRecord['meta_data'] = $value['meta_data'];
                Site::create($siteRecord);
            }
        } catch (\Throwable $th) {
            logger($th->getMessage());
            throw $th();
        }
    }
}
