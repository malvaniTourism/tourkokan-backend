<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\Exportable;

class CrawlGoogleMapDownloadPlace implements  FromCollection, WithHeadings
{
    use Exportable;

    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function array(): array
    {
        return $this->data;
    }


    public function collection()
    {
        return collect($this->data);
    }

    public function headings(): array
    {
        return [
            'Business Status',
            'Formatted Address',
            'latitude',
            'longitude',
            'Name',
            'Place ID',
            'Rating',
            'Reference',
            'Types',
            'User Ratings Total',
            'Viewport',
            'Photos',
            'payload'
        ];
    }
}
