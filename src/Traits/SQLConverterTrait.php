<?php

namespace ie\sqlconvertertomigration\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PhpParser\Node\Attribute;
use function GuzzleHttp\Psr7\str;

trait SQLConverterTrait
{

    public static function convertSQLToMigrationFiles($new_patch)
    {
        $result = self::extractQueriesOnly(str_replace('"', '', $new_patch), 'SQL_DOWN = u');
        $result_as_array = explode(";", $result);
        $filtered_results = self::filterResults($result_as_array);
        $filtered_results_according_table = self::filteredResultsAccordingTable($filtered_results);
        self::executeOperationForEveryTable($filtered_results_according_table);
    }

    static function extractQueriesOnly($string, $needle): string
    {
        $start_search = strpos($string, $needle);
        $end_string = strlen($string);
        $result = substr($string, $start_search, $end_string);
        return trim($result, $needle);
    }

    public static function filterResults($result_as_array): array
    {
        $filtered_array = [];
        foreach ($result_as_array as $index => $query) {
            $allow_constraint_in_string=false;
            if (strpos($query, 'DROP CONSTRAINT') !== false){
                $allow_constraint_in_string=true;
            }
            if ($query!=""){
                $query = str_replace('`', '"', $query);
                if ($allow_constraint_in_string==false){
                    preg_match_all('/CONSTRAINT ".*"/', $query, $m);
                    $result = $m[0];
                    if (count($result) > 0) {
                        $serach_include = strstr(reset($result), 'FOREIGN');
                        $query = str_replace(reset($result), $serach_include, $query);
                    }
                }
                if (strpos($query, 'ALTER TABLE') !== false){
                    array_push($filtered_array, $query);
                }
                if (strpos($query, 'CREATE TABLE') !== false ) {
                    array_push($filtered_array, $query);
                }
                if (strpos($query, 'DROP TABLE') !== false) {
                    array_push($filtered_array, $query);
                }
            }
        }
        return $filtered_array;
    }

    public static function includedOperation($query)
    {
        preg_match_all('/KEY ".*"/', $query, $m2);
        $result_KEY = $m2[0];
        if (count($result_KEY) > 0) {
            return  'KEY';
        }
        preg_match_all("/PRIMARY KEY/", $query, $m1);
        $result_PRIMARY_KEY = $m1[0];;
        if (count($result_PRIMARY_KEY) > 0) {
            return  'PRIMARY KEY';
        }
        preg_match_all("/UNIQUE KEY/", $query, $m1);
        $result_UNIQUE_KEY = $m1[0];;
        if (count($result_UNIQUE_KEY) > 0) {
            return  'UNIQUE KEY';
        }
        if (strpos($query,'DROP CONSTRAINT')!==false) {
            return  'DROP CONSTRAINT';
        }
        preg_match_all('/CONSTRAINT ".*"/', $query, $m);
        $result = $m[0];
        if (count($result) > 0) {
            $serach_include = strstr(reset($result), 'FOREIGN');
            $query = str_replace(reset($result), $serach_include, $query);
            return $query;
        }
        $query=str_replace('(','',$query);
        $query=str_replace(')','',$query);
        preg_match_all('/FOREIGN KEY/', $query, $m);
        $result=$m[0];
        if (count($result)>0){
            return 'FOREIGN KEY';
        }
        return '';
    }


    public static function filteredResultsAccordingTable($result_as_array): array
    {
        $queries_of_table = [];
        $tables_name = [];
        foreach ($result_as_array as $query) {
            $inner_queries = [];
            $query = str_replace('`', '"', $query);
            preg_match_all('/"([^"]+)"/', $query, $m);
            $meta_data = $m[0];
            if (count($meta_data) > 0) {
                $table = trim($meta_data[0], '"');
                if (!in_array($table, $tables_name)) {
                    array_push($tables_name, $table);
                }
            }
        }
        foreach ($tables_name as $table) {
            $inner_queries = [];
            foreach ($result_as_array as $query) {
                $query = str_replace('`', '"', $query);
                preg_match_all('/"([^"]+)"/', $query, $m);
                $meta_data = $m[0];
                if (count($meta_data) > 0) {
                    $table_for_query = trim($meta_data[0], '"');
                    if ($table_for_query == $table) {
                        array_push($inner_queries, $query);
                    }
                }
            }
            $queries_of_table[$table] = $inner_queries;
        }
        return $queries_of_table;
    }

    public static function executeOperationForEveryTable($filtered_results_according_table)
    {
        $index = 0;
        foreach ($filtered_results_according_table as $tableWithQuery) {
            foreach ($tableWithQuery as  $everyQuery) {
                $operation = self::getOperation($everyQuery);
                if ($operation == 'create') {
                    self::createMigrationForNewTable($everyQuery, $index++);
                }
                else if ($operation == 'drop') {
                    self::dropMigration($everyQuery, $index++);
                }
                else if ($operation == 'alter') {
                    $table_name = array_search($tableWithQuery, $filtered_results_according_table);
                    $data = [];
                    $data['table'] = $table_name;
                    $data['operation'] = 'alter';
                    $count_of_queries=count($tableWithQuery);
                    if ($count_of_queries==1){
                        preg_match_all('`"([^"]*)"`', reset($tableWithQuery), $results);
                        $col_name=trim($results[0][1],'"');
                        $file = self::createFileForMigration($data, $index++,$col_name);
                    }
                    else{
                        $file = self::createFileForMigration($data, $index++);
                    }
                    self::extractDataOfQuery($filtered_results_according_table[$table_name], $file);
                    break;
                }
            }
        }
    }

    static function getOperation($string): string
    {
        $operation = '';
        if (strpos($string, 'ALTER TABLE') !== false) {
            $operation = 'alter';
        }
        if (strpos($string, 'CREATE TABLE') !== false) {
            $operation = 'create';
        }
        if (strpos($string, 'DROP TABLE') !== false) {
            $operation = 'drop';
        }
        return $operation;
    }

    private static function createMigrationForNewTable(string $query, $index)
    {
        $data = [];
        $data['operation'] = 'create';
        $cols = [];
        $MULTI_PKS=[];
        $is_added = [];
        $mysql_filter = str_replace('`', '"', $query);
        preg_match_all('/"([^"]+)"/', $mysql_filter, $m);
        $table = $m[0][0];
        $data['table'] = trim($table, '"');
        $cols_as_string = str_replace('CREATE TABLE ' . $table . ' (', '', $mysql_filter);
        $cols_as_array = explode(',', self::getColsOfCreateOperation($query));
        foreach ($cols_as_array as $index=>$col) {
            $attributes = [];
            $all_attributes=[];
            preg_match_all('`"([^"]*)"`', $col, $results);
            if (count($results[0]) > 0) {
                $original__with_semi=$results[0][0];
                $col_name = str_replace('"', '', $results[0][0]);
                if (!in_array($col_name, $is_added)) {
                    $isForignKey=self::isForignKey($col ,$col_name);
                    $result = preg_split('/' . $col_name . '/', $col);
                    $result_split = explode(' ', $result[1]);
                    $type = $result_split[1];
                    $inner_col['name'] = $col_name;
                    array_push($is_added, $col_name);
                    $inner_col['type'] = $type;
                    $PrimaryKeys=self::isPrimaryKey($cols_as_string, $col_name);
                    if (count($PrimaryKeys)==1 && $original__with_semi==reset($PrimaryKeys)){
                        array_push($attributes, 'PRIMARY KEY');
                    }
                    else{
                        $MULTI_PKS=$PrimaryKeys;
                        array_push($attributes, 'MULTI PKS');
                    }
                    $all_attributes = array_merge($attributes, self::getCustomAttributes($col));
                    $inner_col['attributes'] = $all_attributes;
                    array_push($cols, $inner_col);
                }
                $isForignKey=self::isForignKey($col ,$col_name);
                if ($isForignKey!==false) {
                    array_pop($cols);
                    $forign_key_item= $cols_as_array[$index];
                    $forign_key_item=str_replace('(','',$forign_key_item);
                    $forign_key_item=str_replace(')','',$forign_key_item);
                    preg_match_all('`"([^"]*)"`', $forign_key_item, $m);
                    $result=$m[0];
                    $forign_key_table =$result[count($result) - 1];
                    $same_table_key=$result[count($result) - 2];
                    $forign_key_table_key=$result[count($result) - 3];
                    array_push($inner_col['attributes'] , 'FOREIGN KEY '.$same_table_key.''.$forign_key_table.''.$forign_key_table_key.'');
                    array_push($cols, $inner_col);
                }
            }
        }
        $data['columns'] = $cols;
        $file = self::createFileForMigration($data, $index);
        $created_cols = [];
        foreach ($data['columns'] as $column) {
            $column = (object)$column;
            if (in_array('PRIMARY KEY', $column->attributes)) {
                $col_migration = '            $table->bigIncrements("' . $column->name . '");';
            }
            else {
                $method_type = self::typeConverter($column->type);
                $attributes_assigned = self::attributeConverter($column->attributes, $data['table'], $column->name, 'create');
                if ($attributes_assigned != "") {
                    $end = $attributes_assigned;
                } else {
                    $end = ';';
                }
                $col_migration = '            $table->' . $method_type . '("' . $column->name . '")' . $end;
            }
            $col_migration.=';';
            array_push($created_cols, $col_migration);

            $forign_key_items=array_filter($column->attributes, function ($item) {
                if (strpos($item, 'FOREIGN KEY')!==false) {
                    return $item;
                }
            });
            if (count($forign_key_items)>0){
                $forign_key_items_result=  self::addForeignKeyIfExist($forign_key_items,$file);
                $created_cols=array_merge($created_cols,$forign_key_items_result);
            }
        }
        if(in_array('MULTI PKS', $column->attributes) &&count($MULTI_PKS)>1){
            $write_array_of_primary=[];
            array_push($write_array_of_primary,'            $table->primary(['.implode(',',$MULTI_PKS).']);');
            $created_cols=array_merge($created_cols,$write_array_of_primary);
        }
        $line_to_add = self::getEditableLine($file, '//end_add_columns');
        self::fileEditContents($file, $line_to_add - 1, implode("\n", $created_cols));
        $line_to_reverse = self::getEditableLine($file, ' //end_reverse');
        $table_name = $data['table'];
        $reverse_migration = "         Schema::dropIfExists('$table_name');";
        self::fileEditContents($file, $line_to_reverse - 1, $reverse_migration);
    }

    static function addForeignKeyIfExist($forign_key_items,$file){
        $created_cols=[];
        foreach ($forign_key_items as $item){
            preg_match_all('`"([^"]*)"`', $item, $results);
            $forign_keys_as_array = $results[0];
            $original_key_on_same_table = $forign_keys_as_array[count($forign_keys_as_array) - 1];
            $original_key_on_another_table = $forign_keys_as_array[count($forign_keys_as_array) - 2];
            $another_table = $forign_keys_as_array[count($forign_keys_as_array) - 3];
            //$attributes_converter['FOREIGN KEY ' . $original_key_on_same_table . '' . $another_table . '' . $original_key_on_another_table . ''] = "$table->->foreign(" . $original_key_on_another_table . ")->references(" . $original_key_on_same_table . ")->on(" . $another_table . ")";;
            $col_migration='            $table->foreign(' . $original_key_on_same_table . ')->references(' . $original_key_on_another_table . ')->on(' . $another_table . ');'."\n";
            array_push($created_cols, $col_migration);
        }
        return $created_cols;
    }

    private static function getColsOfCreateOperation($query): string
    {
        $query = str_replace('`', '"', $query);
        $start = strpos($query, '(');
        $close_bracket_exist = [];
        $cols = '';
        $query = str_split($query);
        foreach ($query as $index => $char) {
            if ($char == ')') {
                array_push($close_bracket_exist, $index);
            }
        }
        $end = end($close_bracket_exist);
        foreach ($query as $index => $char) {
            if (($index >= $start && $index <= $end)) {
                $cols .= $char;
            }
        }
        return $cols;
    }

    private static function isPrimaryKey($query, $col)
    {
        $array_of_pks=[];
        preg_match_all('/PRIMARY KEY \(([^\)]*)\)/', $query, $m);
        $result = $m[0];
        $col = '"' . $col . '"';
        if (count($result) > 0) {
            preg_match_all('`"([^"]*)"`', reset($result), $results);
            $array_of_pks = reset($results);
        }
        return $array_of_pks;
    }

    private static function isForignKey($query,$col): bool
    {
        $query=str_replace('(','',$query);
        $query=str_replace(')','',$query);
        preg_match_all('/FOREIGN KEY "'.$col.'" REFERENCES/', $query, $m);
        $result=$m[0];
        if (count($result)>0){
            return true;
        }
        return false;
    }

    private static function isUnique($query, $table, $col): bool
    {
        if (strpos($query, 'ALTER TABLE `' . $table . '` ADD UNIQUE KEY `' . $col . '` (`' . $col . '`);') !== false) {
            return true;
        }
        return false;
    }

    static function getCustomAttributes($string): array
    {
        $options = [];
        $meta_data=['DEFAULT NULL','AUTO_INCREMENT','unsigned'];
        preg_match_all("/DEFAULT '[a-zA-Z0-9]+'/", $string, $m);
        $default_value_as_array = $m[0];
        if (count($default_value_as_array) > 0) {
            $default_value = $default_value_as_array[0];
            if (isset($default_value)) {
                array_push($options, $default_value);
            }
        }
        if (strpos($string, 'DEFAULT NULL') !== false) {
            array_push($options, 'DEFAULT NULL');
        }
        if (strpos($string, 'AUTO_INCREMENT') !== false) {
            array_push($options, 'AUTO_INCREMENT');
        }
        if (strpos($string, 'unsigned') !== false) {
            array_push($options, 'unsigned');
        }
        return $options;
    }

    public static function createFileForMigration(array $data, $index,$col_name=null): string
    {
        if ($data['operation'] == 'create') {
            $main_name = '_create_' . $data['table'] . '_table';
        }
        if ($data['operation'] == 'alter') {
            if ($col_name!=null){
                $main_name = '_add_'.$col_name.'_to_' . $data['table'] . '_table';
            }
            else{
                $main_name = '_edit_columns_in_' . $data['table'] . '_table';
            }
        }
        if ($data['operation'] == 'drop') {
            $main_name = '_drop_' . $data['table'] . '_table';
        }
        $root_path = str_replace('/public', '', realpath("."));
        $date = date('Y_m_d');
        $time = str_replace(':', '', date('H:i:s')) . $index;
        $full_name = $date . '_' . $time . $main_name . '.php';
        $file = $root_path . '/database/migrations/' . $full_name;
        touch($file, strtotime('-1 days'));
        if ($data['operation'] == 'create') {
            $operation = 'create';
            $dir=str_replace('/Traits','',__DIR__);
            File::copy($dir . '/Templates/TemplateFile.php', $file);
        }
        if ($data['operation'] == 'alter') {
            $operation = 'table';
            $dir=str_replace('/Traits','',__DIR__);
            File::copy($dir.'/Templates/AlterFile.php', $file);
        }
        if ($data['operation'] == 'drop') {
            $dir=str_replace('/Traits','',__DIR__);
            File::copy($dir . '/Templates/DropFile.php', $file);
            self::ReplaceWordInFile($file, 'table_name', $data['table']);
        }
        if ($data['operation'] != 'drop') {
            self::ReplaceWordInFile($file, 'operation', $operation);
        }
        self::ReplaceWordInFile($file, 'table_name', $data['table']);
        self::ReplaceWordInFile($file, 'MigrationName', self::renameMigration($main_name));
        return $file;
    }

    public static function ReplaceWordInFile($file, $needle, $replacement)
    {
        if (file_exists($file) === TRUE) {
            if (is_writeable($file)) {
                $FileContent = file_get_contents($file);
                $FileContent = str_replace($needle, $replacement, $FileContent);
                file_put_contents($file, $FileContent);
            }
        }
    }

    public static function renameMigration($name): string
    {
        $Array_words = explode('_', $name);
        $result = '';
        foreach ($Array_words as $word) {
            if ($word != '') {
                $result = $result . '' . ucfirst($word);
            }
        }
        return $result;
    }

    public static function typeConverter($column_type)
    {
        $types=self::getAllTypesMySQL();
        //end other
        foreach ($types as $key => $value) {
            if (strpos($column_type, $key) !== false) {
                return $value;
            }
        }

    }

    public static function getAllTypesMySQL(){
        $types = [];
        // start int
        $types['int'] = 'integer';
        $types['tinyint'] = 'tinyInteger';
        $types['smallint'] = 'smallInteger';
        $types['mediumint'] = 'mediumInteger';
        $types['bigint'] = 'bigInteger';
        $types['float'] = 'float';
        $types['double'] = 'double';
        $types['decimal'] = 'decimal';
        $types['boolean'] = 'boolean';
        //end int

        //start time
        $types['date'] = 'date';
        $types['datetime'] = 'datetime';
        $types['timestamp'] = 'timestamp';
        $types['time'] = 'time';
        $types['year'] = 'year';
        //end time

        //start string

        //end string
        $types['varchar'] = 'string';
        $types['char'] = 'char';
        $types['tinytext'] = 'tinyText';
        $types['mediumtext'] = 'mediumText';
        $types['longtext'] = 'longText';
        $types['text'] = 'text';
        $types['binary'] = 'binary';
        $types['blob'] = 'binary';
        $types['tinyblob'] = 'binary';
        $types['mediumblob'] = 'binary';
        $types['longblob'] = 'binary';
        //end string

        //start geo
        $types['geometry'] = 'geometry';
        $types['point'] = 'point';
        $types['polygon'] = 'polygon';
        $types['multipoint'] = 'multiPoint';
        $types['multipolygon'] = 'multiPolygon';
        $types['geometrycollection'] = 'geometryCollection';
        $types['linestring'] = 'lineString';
        $types['multilinestring'] = 'multiLineString';

        //end geo

        //start other
        $types['enum'] = 'enum';
        $types['json'] = 'json';
        $types['set'] = 'set';
        return $types;
    }

    public static function attributeConverter($attributes, $table_name, $col_name, $operation,$flag=null): string
    {
        $attributes_converter = [];
        $attribute_as_migration = '';
        $split_attributes = implode(',', $attributes);;
        preg_match_all("/DEFAULT '[a-zA-Z0-9]+'/", $split_attributes, $m);
        $default_value_as_array = $m[0];
        if (count($default_value_as_array) > 0) {
            $default_value = $default_value_as_array[0];
            if (isset($default_value)) {
                $default = self::getDefaultValue($default_value);
                $attributes_converter['DEFAULT ' . $default . ''] = "->default(" . $default . ")";
            }
        }
        if (count($default_value_as_array) > 0) {
            $default_value = $default_value_as_array[0];
            if (isset($default_value)) {
                $default = self::getDefaultValue($default_value);
                $attributes_converter['DEFAULT ' . $default . ''] = "->default(" . $default . ")";
            }
        }
        $attributes_converter['DEFAULT NULL'] = "->nullable()";
        $attributes_converter['unsigned'] = "->unsigned()";
        foreach ($attributes as $index => $attribute) {
            if (array_key_exists($attribute, $attributes_converter) && $index != count($attributes) - 1) {
                $attribute_as_migration .= $attributes_converter[$attribute];
            }
            if (array_key_exists($attribute, $attributes_converter) && $index == count($attributes) - 1) {
                $attribute_as_migration .= $attributes_converter[$attribute];
                if ($operation == 'alter' && Schema::hasColumn($table_name, $col_name) && $flag != 'reverse') {
                    $attribute_as_migration .= '->change()';
                }
                $attribute_as_migration .= ';';
            }
        }
        return $attribute_as_migration;
    }

    private static function getDefaultValue($default)
    {
        preg_match_all("/'([^']+)'/", $default, $m);
        return $m[0][0];
    }

    public static function getEditableLine($file, $searchable): int
    {
        $line = 1;
        $handle = fopen($file, "r");
        if ($handle) {
            while (!feof($handle)) {
                $buffer = fgets($handle);
                if (strpos($buffer, $searchable) !== FALSE) {
                    fclose($handle);
                    return $line;
                }
                $line++;
            }
        }
    }

    public static function fileEditContents($file_name, $line, $new_value)
    {
        $file = explode("\n", rtrim(file_get_contents($file_name)));
        $file[$line - 1] = $new_value;
        $file = implode("\n", $file);
        file_put_contents($file_name, $file);
    }

    static function dropMigration($query, $index)
    {
        $data = [];
        $data['operation'] = 'drop';
        $query = str_replace('`', '"', $query);
        preg_match_all('/"([^"]+)"/', $query, $m);
        $table_name = str_replace('"', '', $m[0][0]);
        $data['table'] = $table_name;
        //qu
        $file = self::createFileForMigration($data, $index);
        $data = self::getTableInformationBeforeDrop($data['table']);
        $line_to_reverse_drop = self::getEditableLine($file, '//end_add_columns');
        $created_cols = [];
        foreach ($data['columns'] as $column) {
            $column = (object)$column;
            if (in_array('AUTO_INCREMENT', $column->attributes)) {
                $col_migration = '            $table->increments("' . $column->name . '");';
            } else {
                $method_type = self::typeConverter($column->type);
                $attributes_assigned = self::attributeConverter($column->attributes, $data['table'], $column->name, 'drop');
                if ($attributes_assigned != "") {
                    $end = $attributes_assigned;
                } else {
                    $end = ';';
                }
                $col_migration = '            $table->' . $method_type . '("' . $column->name . '")' . $end;
            }
            array_push($created_cols, $col_migration);
        }
        self::fileEditContents($file, $line_to_reverse_drop - 1, implode("\n", $created_cols));
    }

    private static function getTableInformationBeforeDrop($table): array
    {
        $info_cols = DB::select(' DESCRIBE ' . $table);
        $data['operation'] = 'create';
        $data['table'] = $table;
        $data['columns'] = [];
        foreach ($info_cols as $col) {
            $inner_col = [];
            $col = (object)$col;
            $col_name = $col->Field;
            $inner_col['name'] = $col_name;
            $attributes = '';
            $type_and_attributes = explode(' ', $col->Type);
            $type = $type_and_attributes[0];
            $inner_col['type'] = $type;
            if (count($type_and_attributes) > 1) {
                for ($i = 1; $i < count($type_and_attributes); $i++) {
                    $attributes .= $type_and_attributes[$i] . ',';
                }
            }
            if ($col->Key == 'PRI') {
                $attributes .= 'PRIMARY KEY,';
            }
            if ($col->Default == null) {
                $attributes .= 'DEFAULT NULL,';
            } else {
                $default = "'" . $col->Default . "'";
                $attributes .= 'DEFAULT ' . $default . ',';
            }
            $inner_col['attributes'] = explode(',', rtrim($attributes, ","));
            array_push($data['columns'], $inner_col);
        }
        return $data;
    }

    static function checkIfAlterOrDropAlter($mysql){
        if (strpos($mysql, 'ALTER TABLE') !== false) {
            $operation = 'alter_only';
        }
        if (strpos($mysql, 'ALTER TABLE') !== false &&strpos($mysql, 'DROP') !== false ) {
            $operation = 'alter_drop';
        }
        return $operation;
    }

    static function extractDataOfQuery($queries_as_array, $file)
    {
        foreach ($queries_as_array as $mysql){
            $exception_operator=false;
            $data = [];
            $MULTI_PKS=[];
            $data['operation'] = 'alter';
            $mysql_filter = str_replace('`', '"', $mysql);
            $includedOperation= self::includedOperation($mysql);
            if ($includedOperation=='KEY'){
                continue;
            }
            elseif ($includedOperation=='DROP CONSTRAINT'){
                continue;
            }
            preg_match_all('/"([^"]+)"/', $mysql_filter, $m);
            $meta_data = $m[0];
            $table = $meta_data[0];
            $column = $meta_data[1];
            $result = preg_split('/' . $column . '/', $mysql_filter);
            $result_split = explode(' ', $result[1]);
            $checkIfAlterOrDropAlter=  self::checkIfAlterOrDropAlter($mysql);
            if ($checkIfAlterOrDropAlter=='alter_drop'){
                $exception_operator=true;
                $col_migration='';
                $col_migration .= '                  $table->dropColumn(' . $column . ');';
                $col_migration.=';';
                $col_migration .= "\n";
                $line_to_add = self::getEditableLine($file, '//end_add_columns');
                self::fileEditContents($file, $line_to_add - 1, $col_migration);
            }
            if ($includedOperation=='FOREIGN KEY'){
                $exception_operator=true;
                $forign_key_items=[];
                $forign_key_item=str_replace('(','',$mysql_filter);
                $forign_key_item=str_replace(')','',$mysql_filter);
                preg_match_all('`"([^"]*)"`', $forign_key_item, $m);
                $result=$m[0];
                $forign_key_table =$result[count($result) - 1];
                $same_table_key=$result[count($result) - 2];
                $forign_key_table_key=$result[count($result) - 3];
                array_push($forign_key_items, 'FOREIGN KEY '.$same_table_key.''.$forign_key_table.''.$forign_key_table_key.'');
                $addForeignKeyIfExist=self::addForeignKeyIfExist($forign_key_items,$file);
                $col_migration='';
                $col_migration .= reset($addForeignKeyIfExist);
                $col_migration .= "\n";
                $line_to_add = self::getEditableLine($file, '//end_add_columns');
                self::fileEditContents($file, $line_to_add - 1, $col_migration);
            }
            elseif ($includedOperation=='PRIMARY KEY'){
                $exception_operator=true;
                $col_migration='';
                $col_migration .= '            $table->primary(['.$column.'])';
                $col_migration.=';';
                $col_migration .= "\n";
                $line_to_add = self::getEditableLine($file, '//end_add_columns');
                self::fileEditContents($file, $line_to_add - 1, $col_migration);
                continue;
            }
            if (count($result_split)>1){
                $type = $result_split[1];
            }
            if (isset($type)&&$type==""){
                foreach (self::getAllTypesMySQL() as $key=>$general_type){
                    if (strpos($mysql_filter, $key)!==false) {
                        $type= $key;
                        break;
                    }
                }
            }
            if (isset($type)){
                $data['type']=$type;
            }
            $data['table'] = trim($table, '"');
            $data['column'] = trim($column, '"');
            $attributes = [];
            $PrimaryKeys=self::isPrimaryKey($mysql_filter, $data['column']);
            if (count($PrimaryKeys)==1 && $original__with_semi==reset($PrimaryKeys)){
                array_push($attributes, 'PRIMARY KEY');
            }
            else if (count($PrimaryKeys)>1 ){
                $MULTI_PKS=$PrimaryKeys;
                array_push($attributes, 'MULTI PKS');
            }
            $all_attributes = array_merge($attributes, self::getCustomAttributes($mysql_filter));
            $data['attributes'] = $all_attributes;
            $data = (object)$data;
            if (isset($data->type)){
                $method_type = self::typeConverter($data->type);
            }
            $attributes = $data->attributes;
            $attributes_assigned = self::attributeConverter($attributes, $data->table, $data->column, $data->operation);
            if (Schema::hasColumn($data->table, $data->column)) {
                $attributes_assigned .= '->change()';
            }
            if ($attributes_assigned != '') {
                $end = $attributes_assigned;
            } else {
                $end = ';';
            }
            if (isset($method_type)){
                $col_migration = '            $table->' . $method_type . '("' . $data->column . '")' . $end;
                $col_migration.=';';
                $col_migration .= "\n";
                if ($exception_operator==false){
                    $line_to_add = self::getEditableLine($file, '//end_add_columns');
                    self::fileEditContents($file, $line_to_add - 1, $col_migration);
                }
            }
            //que
            if (Schema::hasColumn($data->table, $data->column)) {
                $result = self::getTableInformationBeforeDrop($data->table);
                foreach ($result['columns'] as $one_col) {
                    $one_col = (object)$one_col;
                    if ($one_col->name == $data->column) {
                        $type = self::typeConverter($one_col->type);
                        $attribute_as_migration = '';
                        foreach ($one_col->attributes as $attr) {
//                            preg_match_all("/DEFAULT '[a-zA-Z0-9]+'/", $attr, $m);
//                            $default_value_as_array = $m[0];
//                            if (count($default_value_as_array) > 0) {
//                                $default_value = $default_value_as_array[0];
//                                if (isset($default_value)) {
//                                    $default = self::getDefaultValue($default_value);
//                                    $attributes_converter['DEFAULT ' . $default . ''] = "->default(" . $default . ")";
//                                }
//                            }
                            $attribute_as_migration .= self::attributeConverter($one_col->attributes,$data->table,$data->column,$data->operation,'reverse');
                        }
                        $line_to_drop = self::getEditableLine($file, '//end_reverse');
                        $full_table_name = "'" . $data->table . "'";
                        $reverse_migration = '
            $table->' . $type . '("' . $data->column . '")' . $attribute_as_migration . '';
                        $reverse_migration .= "\n";
                        self::fileEditContents($file, $line_to_drop - 1, $reverse_migration);
                    }
                }
            } else if (!Schema::hasColumn($data->table, $data->column)) {
                $line_to_drop = self::getEditableLine($file, '//end_reverse');
                $full_name_col = "'" . $data->column . "'";
                $full_table_name = "'" . $data->table . "'";
                $reverse_migration = '
            $table->dropColumn(' . $full_name_col . ');
       ';
                $reverse_migration .= "\n";
                self::fileEditContents($file, $line_to_drop - 1, $reverse_migration);

            }
        }
    }

}
