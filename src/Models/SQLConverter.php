<?php

namespace Ie\Sqlconvertertomigration\Models;

use Ie\Sqlconvertertomigration\Traits\SQLConverterTrait;
use Illuminate\Database\Eloquent\Model;

class SQLConverter extends Model
{
    use SQLConverterTrait;
    public static function convertStringQueries($new_patch){
        $new_patch =
            "
DROP TABLE `t2222`;
CREATE TABLE `t6` (
  `id` int(11) NOT NULL,
  `name` int(11) NOT NULL,
  `phone` int(11) DEFAULT NULL,
  `Email` int(11) unsigned NOT NULL DEFAULT '10',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
CREATE TABLE `t3` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` int(11) NOT NULL,
  `c1` date NOT NULL,
  `c2` tinyint(4) NOT NULL,
  `c3` float NOT NULL,
  `c4` tinyint(1) NOT NULL,
  `c5` datetime NOT NULL,
  `c10` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `c11` time NOT NULL,
  `c12` mediumtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
ALTER TABLE `t1` ADD `phone` int(11) NOT NULL DEFAULT 'qu';
ALTER TABLE `t1` ADD `ss` int(11) DEFAULT NULL;
ALTER TABLE `t1` ADD `ssq` int(11) DEFAULT NULL;
ALTER TABLE `t1` ADD `islam` int(11) NOT NULL DEFAULT '1';
CREATE TABLE `t4` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` int(11) NOT NULL,
  `c1` date NOT NULL,
  `c2` tinyint(4) DEFAULT NULL,
  `c3` float NOT NULL,
  `c4` tinyint(1) unsigned,
  `c5` datetime NOT NULL,
  `c10` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `c11` time NOT NULL,
  `c12` mediumtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
";
        self::convertSQLToMigrationFiles($new_patch);
    }

}