<?php

namespace Database\Seeders;

use App\Imports\ProductCategoryImport;
use Illuminate\Database\Seeder;
use Maatwebsite\Excel\Facades\Excel;

class ProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $path = 'excels/product_categories.xls';
        Excel::import(new ProductCategoryImport, $path);
    }
}
