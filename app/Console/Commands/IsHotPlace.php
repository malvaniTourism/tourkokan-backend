<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Site;
use Illuminate\Console\Command;

class IsHotPlace extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:hotplace';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'set places to hot for temporary';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Fetch cities that belong to the "city" category
        $category_id = Category::where('code', 'city')->pluck('id');

        $city_ids = Site::select('id')->where('category_id', $category_id)->get();

        foreach ($city_ids as $key => $city) {
            $citySites = Site::select('id')->where('parent_id', $city->id)->limit(5)->get();

            foreach ($citySites as $keycs => $value) {
                $updateSite =  Site::where('id', $value->id)
                    ->update(['is_hot_place' => 1]);
            }
        }

        Command::SUCCESS;
    }
}
