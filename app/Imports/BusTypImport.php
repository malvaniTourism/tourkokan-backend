<?php

namespace App\Imports;

use App\Models\BusType;
use Maatwebsite\Excel\Concerns\ToModel;

class BusTypImport implements ToModel
{
    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        return new BusType([
            //
        ]);
    }
}
