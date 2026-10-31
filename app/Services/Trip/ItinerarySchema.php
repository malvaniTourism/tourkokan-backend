<?php

namespace App\Services\Trip;

/**
 * The fixed JSON contract the model must answer in.
 *
 * It deliberately carries **no facts** — only ordering, site ids and prose.
 * Names, phone numbers, prices and bus times are attached afterwards from our
 * own rows by the assembler, which is why a contact in a finished itinerary is
 * always the one currently in the database rather than one a model reproduced.
 */
class ItinerarySchema
{
    public static function json(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'trip' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'   => ['type' => 'string'],
                        'summary' => ['type' => 'string'],
                    ],
                    'required'             => ['title', 'summary'],
                    'additionalProperties' => false,
                ],
                'days' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'day'   => ['type' => 'integer'],
                            'theme' => ['type' => 'string'],
                            'stops' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'site_id' => ['type' => 'integer'],
                                        'arrive'  => ['type' => 'string'],
                                        'depart'  => ['type' => 'string'],
                                        'note'    => ['type' => 'string'],
                                    ],
                                    'required'             => ['site_id', 'arrive', 'note'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required'             => ['day', 'theme', 'stops'],
                        'additionalProperties' => false,
                    ],
                ],
                'warnings' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required'             => ['trip', 'days'],
            'additionalProperties' => false,
        ];
    }
}
