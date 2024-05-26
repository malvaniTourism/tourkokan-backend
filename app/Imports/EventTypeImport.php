<?php

namespace App\Imports;

use App\Models\EventType;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Exception;
use Illuminate\Support\Facades\Cache;

class EventTypeImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $data)
    {
        foreach ($data as $key => $value) {
            $parent_id = null;
            if (
                !isValidReturn($value, 'name')
                || !isValidReturn($value, 'code')
            ) {
                echo ' all fields are required.' . PHP_EOL;
                throw new Exception('all fields are required in event type Excel.');
            }
            $exists = EventType::where(array(
                'code' => strtolower($value['code'])
            ))->first();

            $parent_code = isValidReturn($value, 'parent_code');

            if (!is_null($value['parent_code'])) {
                $parent = EventType::where('code', $parent_code)->first();
                if ($parent)
                    $parent_id = $parent->id;
            }

            if (is_null($exists)) {
                $obj = new EventType();
                $obj->name              = $value['name'];
                $obj->mr_name           = $value['mr_name'];
                $obj->code              = strtolower($value['code']);
                $obj->parent_id         = $parent_id;
                $obj->description       = isValidReturn($value, 'description');
                $obj->icon              = isValidReturn($value, 'icon', false);
                $obj->status            = isValidReturn($value, 'status', false);
                $obj->save();
            } else {
                $exists->name              = $value['name'];
                $exists->mr_name           = $value['mr_name'];
                $exists->code              = strtolower($value['code']);
                $exists->parent_id         = $parent_id;
                $exists->description       = isValidReturn($value, 'description');
                $exists->icon              = isValidReturn($value, 'icon', false);
                $exists->status            = isValidReturn($value, 'status', false);
                $exists->save();
            }
        }
    }
}
