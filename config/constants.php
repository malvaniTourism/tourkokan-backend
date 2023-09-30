<?php

return [
    'upload_path' => [
        'base'              => 'public/assets/',
        'user'              => 'public/assets/users/',
        'photo'             => 'public/assets/gallery',
        'category'          => 'public/assets/categories/',
        'project'           => 'public/assets/projects/',
        'product'           => 'public/assets/products/',
        'places'            => 'public/assets/places/',
        'city'              => 'public/assets/cities/',
        'react'             => 'public/assets/reacts/',
        'blog'              => 'public/assets/blogs/',
        'comments'          => 'public/assets/comments/',
        'placecategory'     => 'public/assets/placecategory/',
        'productCategory'   => 'public/assets/productcategory/',
        'food'              => 'public/assets/food/',
        'profile_picture'   => 'public/assets/profile_picture/',
        'tourpackage'       => 'public/assets/tourpackage/',
        'accomCategory'     => 'public/assets/accomCategory/',
        'busType'           => 'public/assets/busType'
    ],
    'models' => [
        'City' => 'App\Models\City',
        'User' => 'App\Models\User',
    ],
    'banner_levels' => [
        [
            "name" =>  "Carousel",
            "code" =>  "carousel"
        ],
        [
            "name" =>  "Middle",
            "code" =>  "middle"
        ],
        [
            "name" =>  "Footer",
            "code" =>  "footer"
        ]
    ],
    'image_orientation' => [
        [
            "name" =>  "Potrait",
            "code" =>  "potrait"
        ],
        [
            "name" =>  "Landscape",
            "code" =>  "landscape"
        ]
    ],
    'banner_days' => [
        [
            "name" =>  "1 Day",
            "code" =>  "1"
        ],
        [
            "name" =>  "3 Day",
            "code" =>  "3"
        ],
        [
            "name" =>  "5 Day",
            "code" =>  "5"
        ],
        [
            "name" =>  "7 Day",
            "code" =>  "7"
        ]
    ],
];
