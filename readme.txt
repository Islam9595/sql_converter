# sql_converter
### Installation
composer require ie/sql_converter_migration
### How to Use


use ie\sqlconvertertomigration\Models\SQLConverter;

$queries = "your queries"
SQLConverter::convertStringQueries($queries);


### example

$queries = "
DROP TABLE `t2`;
CREATE TABLE `Persons` (
`ID` int(11) NOT NULL,
`LastName` varchar(255) NOT NULL,
`FirstName` varchar(255) DEFAULT NULL,
`Age` int(11) DEFAULT NULL,
PRIMARY KEY (`ID`,`LastName`),
FOREIGN KEY (`Age`) REFERENCES `t1`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
ALTER TABLE `t1` ADD KEY `name` (`name`);
ALTER TABLE `t1` ADD CONSTRAINT `t1_ibfk_1` FOREIGN KEY (`name`) REFERENCES `Persons` (`ID`);
";
SQLConverter::convertStringQueries($queries);

