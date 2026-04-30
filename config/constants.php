<?php

return [
    'upload_path' => [
        'base'              => env('APP_ENV', 'other'),
        'user'              => env('APP_ENV', 'other').'/users',
        'photo'             => env('APP_ENV', 'other').'/gallery',
        'category'          => env('APP_ENV', 'other').'/categories',
        'project'           => env('APP_ENV', 'other').'/projects',
        'product'           => env('APP_ENV', 'other').'/products',
        'places'            => env('APP_ENV', 'other').'/places',
        'city'              => env('APP_ENV', 'other').'/cities',
        'react'             => env('APP_ENV', 'other').'/reacts',
        'blog'              => env('APP_ENV', 'other').'/blogs',
        'comments'          => env('APP_ENV', 'other').'/comments',
        'placecategory'     => env('APP_ENV', 'other').'/placecategory',
        'productCategory'   => env('APP_ENV', 'other').'/productcategory',
        'food'              => env('APP_ENV', 'other').'/food',
        'profile_picture'   => env('APP_ENV', 'other').'/profile_pictures',
        'tourpackage'       => env('APP_ENV', 'other').'/tourpackages',
        'accomCategory'     => env('APP_ENV', 'other').'/accom_categories',
        'busType'           => env('APP_ENV', 'other').'/bus_types',
        'site'              => env('APP_ENV', 'other').'/sites',
        'banner'            => env('APP_ENV', 'other').'/banners',
        'event_banner'      => env('APP_ENV', 'other').'/events/banners',
        'event_gallery'     => env('APP_ENV', 'other').'/events/gallery',
        'site_gallery'      => env('APP_ENV', 'other').'/sites/gallery',
    ],
    'models' => [
        'City' => 'App\Models\City',
        'User' => 'App\Models\User',
    ],
    'banner_levels' => [
        [
            "id"  => 1,
            "name" =>  "Carousel",
            "code" =>  "carousel"
        ],
        [
            "id"  => 2,
            "name" =>  "Middle",
            "code" =>  "middle"
        ],
        [
            "id"  => 3,
            "name" =>  "Footer",
            "code" =>  "footer"
        ]
    ],
    'image_orientation' => [
        [
            "id"  => 1,
            "name" =>  "Potrait",
            "code" =>  "potrait"
        ],
        [
            "id"  => 2,
            "name" =>  "Landscape",
            "code" =>  "landscape"
        ]
    ],
    'banner_days' => [
        [
            "id"  => 1,
            "name" =>  "1 Day",
            "code" =>  "1"
        ],
        [
            "id"  => 2,
            "name" =>  "3 Day",
            "code" =>  "3"
        ],
        [
            "id"  => 3,
            "name" =>  "5 Day",
            "code" =>  "5"
        ],
        [
            "id"  => 4,
            "name" =>  "7 Day",
            "code" =>  "7"
        ]
    ],
];
