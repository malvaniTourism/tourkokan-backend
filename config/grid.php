<?php

return [
    'siteApiTypes' => [
        'list' => [
            "columns" => [
                'id',
                'name',
                'mr_name',
                'parent_id',
                'user_id',
                'bus_stop_type',
                'tag_line',
                'description',
                'domain_name',
                'logo',
                'icon',
                'image',
                'status',
                'is_hot_place',
                'latitude',
                'longitude',
                'pin_code',
                'speciality',
                'rules',
                'social_media',
                'meta_data',
            ]
        ],
        'dropdown' =>  [
            "columns" => [
                'id',
                'name',
                'mr_name',
                'parent_id',
                'bus_stop_type',
                'icon',
                'status',
            ]
        ],
    ],
    "listTransactions" => [
        "columns" => [
            '_id',
            'transaction_id',
            'created_at',
            'transaction_type',
            'debit',
            'credit',
            'current_balance',
            'remark',
            'player_id',
            'tenant_id'
        ],
        "column_labels" => [
            'Client Name' => ["playerDetails" => ["client" => "domain_name"]],
            'Username' => ["playerDetails" => "uername"],
            'Currency' => ["playerDetails" => ["client" => ["currency" => "code"]]],
            'Player Balance' => ["playerDetails" => ["get_player_balance" => "balance"]],
            'Transaction Date' => ["transactionDetails" => "created_at"],
            'Action' => ["transactionDetails" => ["transaction_type" => "transaction_label"]],
            'Debet' => ["transactionDetails" => "debit"],
            'Credit' => ["transactionDetails" => "credit"],
            'Balance' => ["transactionDetails" => "current_balance"],
            'Description' => ["transactionDetails" => "remark"],
            'Transaction ID' => ["transactionDetails" => "_id"],
            'Vendor Transaction ID' => ["transactionDetails" => "transaction_id"]
        ]
    ],
    'listRoutes' => [
        'list' => [
            "columns" => [
                'id',
                'name',
                'source_place_id',
                'destination_place_id',
                'bus_type_id',
                'start_time',
                'end_time',
                'total_time',
                'delayed_time',
                'distance',
            ]
        ],
        'dropdown' =>  [
            "columns" => [
                'id',
                'name'
            ]
        ],
    ],
    'categories' => [
        'list' => [
            "columns" => [
                'id',
                'name',
                'code',
                'parent_id',
                'icon',
                'is_hot_category',
                'status',
                'meta_data',
                'created_at',
                'updated_at',
                'deleted_at',
            ]
        ],
        'dropdown' =>  [
            "columns" => [
                'id',
                'name',
                'code',
                'parent_id',
            ]
        ],
    ],
];
