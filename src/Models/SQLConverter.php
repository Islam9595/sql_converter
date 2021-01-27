<?php

namespace ie\sqlconvertertomigration\Models;

use ie\sqlconvertertomigration\Traits\SQLConverterTrait;
use Illuminate\Database\Eloquent\Model;

class SQLConverter extends Model
{
    use SQLConverterTrait;
    public static function convertStringQueries($new_patch){
        self::convertSQLToMigrationFiles($new_patch);
    }

}