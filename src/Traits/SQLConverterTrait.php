<?php

namespace Ie\Sqlconvertertomigration\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

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
            preg_match_all("/UNIQUE KEY/", $query, $m);
            $result = $m[0];
            if (count($result) == 0) {
                array_push($filtered_array, $query);
            }
        }
        return $filtered_array;
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
                if (strpos($query, $table) !== false) {
                    array_push($inner_queries, $query);
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
            foreach ($tableWithQuery as $j => $everyQuery) {
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
                    $file = self::createFileForMigration($data, $index++);
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
        $is_added = [];
        $mysql_filter = str_replace('`', '"', $query);
        preg_match_all('/"([^"]+)"/', $mysql_filter, $m);
        $table = $m[0][0];
        $data['table'] = trim($table, '"');
        $cols_as_string = str_replace('CREATE TABLE ' . $table . ' (', '', $mysql_filter);
        $cols_as_array = explode(',', self::getColsOfCreateOperation($query));
        foreach ($cols_as_array as $col) {
            preg_match_all('`"([^"]*)"`', $col, $results);
            if (count($results[0]) > 0) {
                $col_name = str_replace('"', '', $results[0][0]);
                if (!in_array($col_name, $is_added)) {
                    $result = preg_split('/' . $col_name . '/', $col);
                    $result_split = explode(' ', $result[1]);
                    $type = $result_split[1];
                    $inner_col['name'] = $col_name;
                    array_push($is_added, $col_name);
                    $inner_col['type'] = $type;
                    $attributes = [];
                    if (self::isPrimaryKey($cols_as_string, $col_name)) {
                        array_push($attributes, 'PRIMARY KEY');
                    }
                    if (self::isUnique($cols_as_string, $data['table'], $col_name)) {
                        array_push($attributes, 'PRIMARY KEY');
                    }
                    $all_attributes = array_merge($attributes, self::getAttributes($col));
                    $inner_col['attributes'] = $all_attributes;
                    array_push($cols, $inner_col);
                }
            }
        }
        $data['columns'] = $cols;
        $file = self::createFileForMigration($data, $index);
        $created_cols = [];
        foreach ($data['columns'] as $column) {
            $column = (object)$column;
            if (in_array('AUTO_INCREMENT', $column->attributes)) {
                $col_migration = '            $table->increments("' . $column->name . '");';
            } else {
                $method_type = self::typeConverter($column->type);
                $attributes_assigned = self::attributeConverter($column->attributes, $data['table'], $column->name, 'create');
                if ($attributes_assigned != "") {
                    $end = $attributes_assigned;
                } else {
                    $end = ';';
                }
                $col_migration = '            $table->' . $method_type . '("' . $column->name . '")' . $end;
            }
            array_push($created_cols, $col_migration);
        }
        $line_to_add = self::getEditableLine($file, '//end_add_columns');
        self::fileEditContents($file, $line_to_add - 1, implode("\n", $created_cols));
        $line_to_reverse = self::getEditableLine($file, ' //end_reverse');
        $table_name = $data['table'];
        $reverse_migration = "         Schema::dropIfExists('$table_name');";
        self::fileEditContents($file, $line_to_reverse - 1, $reverse_migration);
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

    private static function isPrimaryKey($query, $col): bool
    {
        if (strpos($query, 'PRIMARY KEY ("' . $col . '")') !== false) {
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

    static function getAttributes($string): array
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

    public static function createFileForMigration(array $data, $index): string
    {
        if ($data['operation'] == 'create') {
            $main_name = '_create_' . $data['table'] . '_table';
        }
        if ($data['operation'] == 'alter') {
            $main_name = '_edit_columns_to_' . $data['table'] . '_table';
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
            File::copy($root_path . '/app/Templates/TemplateFile.php', $file);
        }
        if ($data['operation'] == 'alter') {
            $operation = 'table';
            File::copy($root_path . '/app/Templates/AlterFile.php', $file);
        }
        if ($data['operation'] == 'drop') {
            File::copy($root_path . '/app/Templates/DropFile.php', $file);
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
        //end other
        foreach ($types as $key => $value) {
            if (strpos($column_type, $key) !== false) {
                return $value;
            }
        }

    }

    public static function attributeConverter($attributes, $table_name, $col_name, $operation,$flag=null): string
    {
        $attributes_converter = [];
        $split_attributes = implode(',', $attributes);
        preg_match_all("/DEFAULT '[a-zA-Z0-9]+'/", $split_attributes, $m);
        $default_value_as_array = $m[0];
        if (count($default_value_as_array) > 0) {
            $default_value = $default_value_as_array[0];
            if (isset($default_value)) {
                $default = self::getDefaultValue($default_value);
                $attributes_converter['DEFAULT ' . $default . ''] = "->default(" . $default . ")";
            }
        }
        $attributes_converter['DEFAULT NULL'] = "->nullable()";
        $attributes_converter['unsigned'] = "->unsigned()";
        $attribute_as_migration = '';
        foreach ($attributes as $index => $attribute) {
            if (array_key_exists($attribute, $attributes_converter) && $index != count($attributes) - 1) {
                $attribute_as_migration .= $attributes_converter[$attribute];
            }
            if (array_key_exists($attribute, $attributes_converter) && $index == count($attributes) - 1) {
                $attribute_as_migration .= $attributes_converter[$attribute];
                if ($operation == 'alter' && Schema::hasColumn($table_name, $col_name)&&$flag!='reverse') {
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

    static function extractDataOfQuery($queries_as_array, $file)
    {
        foreach ($queries_as_array as $mysql){
            $data = [];
            $data['operation'] = 'alter';
            $mysql_filter = str_replace('`', '"', $mysql);
            preg_match_all('/"([^"]+)"/', $mysql_filter, $m);
            $meta_data = $m[0];
            $table = $meta_data[0];
            $column = $meta_data[1];
            $result = preg_split('/' . $column . '/', $mysql_filter);
            $result_split = explode(' ', $result[1]);
            $data['type'] = $result_split[1];
            $data['table'] = trim($table, '"');
            $data['column'] = trim($column, '"');
            $attributes = [];
            if (self::isPrimaryKey($mysql_filter, $data['column'])) {
                array_push($attributes, 'PRIMARY KEY');
            }
            $all_attributes = array_merge($attributes, self::getAttributes($mysql_filter));
            $data['attributes'] = $all_attributes;
            $data = (object)$data;
            $method_type = self::typeConverter($data->type);
            $attributes = $data->attributes;
            $attributes_assigned = self::attributeConverter($attributes, $data->table, $data->column, $data->operation);
            if ($attributes_assigned != '') {
                $end = $attributes_assigned;
            } else {
                $end = ';';
            }

            $col_migration = '            $table->' . $method_type . '("' . $data->column . '")' . $end;
            $col_migration .= "\n";
            $line_to_add = self::getEditableLine($file, '//end_add_columns');
            self::fileEditContents($file, $line_to_add - 1, $col_migration);
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
