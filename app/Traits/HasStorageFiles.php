<?php

namespace App\Traits;

/**
 * Auto-converts stored file paths to full URLs when the attribute is read.
 *
 * Usage in a model:
 *   use HasStorageFiles;
 *   protected array $fileFields = ['icon', 'logo', 'image'];
 *
 * - New uploads store only the storage path  (e.g. public/assets/categories/abc.jpg)
 * - Legacy rows that already contain a full URL are returned unchanged
 * - Null / empty values are returned as null
 */
trait HasStorageFiles
{
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if (
            !empty($this->fileFields) &&
            in_array($key, $this->fileFields) &&
            !empty($value)
        ) {
            return storageUrl($value);
        }

        return $value;
    }
}
