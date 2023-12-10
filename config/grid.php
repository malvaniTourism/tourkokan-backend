<?php

return [
    'siteApiTypes' => [
        'list' => [
            "columns" => [
                'name',
                'parent_id',
                'user_id',
                'category_id',
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
                'name',
                'category_id',
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
    ]
];
